#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @brief Local smoke test: encrypt → Range download → virtual ZIP → SHA-256 verify.
 * @date 2026-11-02
 * @author Stephane H.
 *
 * Usage: php tests/e2e/bin/smoke-download-php.php [plainBytes=33554432]
 */

use App\Entity\SharedFile;
use App\Service\Download\EncryptedRangeReader;
use App\Service\Download\FileDownloadResource;
use App\Service\Download\VirtualZipLayout;
use App\Service\File\FileEncryptionService;
use App\Service\File\V2SegmentIndexService;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

$plainBytes = isset($argv[1]) ? (int) $argv[1] : 32 * 1024 * 1024;
if ($plainBytes < 1024) {
    fwrite(STDERR, "plainBytes too small\n");
    exit(2);
}

$key = $_ENV['APP_FILE_ENCRYPTION_KEY'] ?? $_SERVER['APP_FILE_ENCRYPTION_KEY'] ?? '';
if ($key === '') {
    fwrite(STDERR, "APP_FILE_ENCRYPTION_KEY missing\n");
    exit(2);
}

$projectDir = dirname(__DIR__, 3);
$workDir = $projectDir.'/var/test/e2e-dl100-smoke';
@mkdir($workDir, 0775, true);
$plainPath = $workDir.'/source.bin';
$cipherPath = $workDir.'/source.dat';
$outPath = $workDir.'/downloaded.bin';
$zipPath = $workDir.'/archive.zip';

$cleanup = static function () use ($workDir): void {
    foreach (glob($workDir.'/*') ?: [] as $f) {
        @unlink($f);
    }
};
register_shutdown_function($cleanup);

echo "Generating {$plainBytes} bytes...\n";
$fh = fopen($plainPath, 'wb');
$left = $plainBytes;
$sha = hash_init('sha256');
$crc = hash_init('crc32b');
while ($left > 0) {
    $chunk = random_bytes(min(1024 * 1024, $left));
    fwrite($fh, $chunk);
    hash_update($sha, $chunk);
    hash_update($crc, $chunk);
    $left -= strlen($chunk);
}
fclose($fh);
$expectedSha = hash_final($sha);
$expectedCrc = (int) hexdec(hash_final($crc));

$enc = new FileEncryptionService($key);
$index = new V2SegmentIndexService($enc);
$reader = new EncryptedRangeReader($enc, $index, 1073741824);

echo "Encrypting...\n";
$fingerprints = $enc->encryptPlainFileToV2Storage($plainPath, $cipherPath);
if ($fingerprints['content_sha256'] !== $expectedSha) {
    fwrite(STDERR, "Fingerprint SHA mismatch during encrypt\n");
    exit(1);
}
if (($fingerprints['content_crc32'] & 0xFFFFFFFF) !== ($expectedCrc & 0xFFFFFFFF)) {
    fwrite(STDERR, "Fingerprint CRC mismatch during encrypt\n");
    exit(1);
}

$shared = new SharedFile(1, $cipherPath, 'private', bin2hex(random_bytes(8)), 'source.bin', $plainBytes);
$shared->applyContentFingerprints($fingerprints);
$ref = new ReflectionClass($shared);
$idProp = $ref->getProperty('id');
$idProp->setAccessible(true);
$idProp->setValue($shared, 424242);

$resource = new FileDownloadResource($shared, $reader);
echo "Streaming via 3 ranges...\n";
$out = fopen($outPath, 'wb');
$third = intdiv($plainBytes, 3);
$ranges = [
    [0, $third],
    [$third, $third],
    [$third * 2, $plainBytes - $third * 2],
];
foreach ($ranges as [$start, $len]) {
    $resource->readRange($start, $len, static function (string $chunk) use ($out): void {
        fwrite($out, $chunk);
    });
}
fclose($out);

$gotSha = hash_file('sha256', $outPath);
if ($gotSha !== $expectedSha) {
    fwrite(STDERR, "Downloaded SHA mismatch\n");
    exit(1);
}
echo "File Range OK sha=$gotSha\n";

echo "Building virtual ZIP...\n";
$layout = VirtualZipLayout::fromEntries([[
    'name' => 'source.bin',
    'size' => $plainBytes,
    'mtime' => time(),
    'crc32' => $expectedCrc,
]]);

$zip = fopen($zipPath, 'wb');
fwrite($zip, $layout->buildLocalHeader(0));
$resource->readRange(0, $plainBytes, static function (string $chunk) use ($zip): void {
    fwrite($zip, $chunk);
});
fwrite($zip, $layout->buildCentralDirectoryAndEndings());
fclose($zip);

if (filesize($zipPath) !== $layout->getTotalSize()) {
    fwrite(STDERR, "ZIP size mismatch\n");
    exit(1);
}

$unzipTest = [];
exec('unzip -t '.escapeshellarg($zipPath).' 2>&1', $unzipTest, $code);
echo implode("\n", $unzipTest)."\n";
if ($code !== 0) {
    fwrite(STDERR, "unzip -t failed\n");
    exit(1);
}

$extractDir = $workDir.'/extract';
@mkdir($extractDir, 0775, true);
exec('unzip -o '.escapeshellarg($zipPath).' -d '.escapeshellarg($extractDir).' 2>&1', $extractOut, $extractCode);
if ($extractCode !== 0) {
    fwrite(STDERR, "unzip extract failed\n");
    exit(1);
}
$extractedSha = hash_file('sha256', $extractDir.'/source.bin');
if ($extractedSha !== $expectedSha) {
    fwrite(STDERR, "Extracted SHA mismatch\n");
    exit(1);
}

echo "SMOKE OK bytes={$plainBytes}\n";
exit(0);
