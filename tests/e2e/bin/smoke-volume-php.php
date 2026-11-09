#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @brief Parametric volume smoke: encrypt, multi-range resume download, virtual ZIP, SHA-256.
 * @date 2026-11-02
 * @author Stephane H.
 *
 * Usage:
 *   php tests/e2e/bin/smoke-volume-php.php <size> [cut]
 *
 * <size> is required. [cut] is the resume split offset; when omitted it is size/4.
 * Values accept plain integers (bytes) or suffixes K/M/G/T.
 * Examples:
 *   php tests/e2e/bin/smoke-volume-php.php 64M
 *   php tests/e2e/bin/smoke-volume-php.php 25G 4G
 *   php tests/e2e/bin/smoke-volume-php.php 100G 8G
 *
 * Disk strategy (peak ≈ 2× size):
 * 1) fallocate sparse plain (+ random head/mid/tail patches) → encrypt → delete plain
 * 2) range-download to out → hash → delete out
 * 3) stream ZIP from cipher → unzip -t → extract+hash → cleanup
 *
 * Heavy artefacts are always removed on exit (success or failure) via shutdown cleanup.
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

/**
 * @brief Parse a size string (bytes or K/M/G/T suffix) into an integer byte count.
 * @param string $value Raw CLI value (e.g. "25G", "512M", "1048576").
 * @return int Byte count.
 * @date 2026-11-03
 * @author Stephane H.
 */
function parseSizeBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^(\d+)([KMGT])?$/i', $value, $m)) {
        throw new InvalidArgumentException('invalid size: '.$value);
    }
    $n = (int) $m[1];
    $unit = strtoupper($m[2] ?? '');
    $mult = match ($unit) {
        'K' => 1024,
        'M' => 1024 ** 2,
        'G' => 1024 ** 3,
        'T' => 1024 ** 4,
        default => 1,
    };

    return $n * $mult;
}

if (!isset($argv[1]) || trim((string) $argv[1]) === '') {
    fwrite(STDERR, "Usage: php tests/e2e/bin/smoke-volume-php.php <size> [cut]\n");
    fwrite(STDERR, "  size  Required payload size (e.g. 64M, 25G, 26843545600)\n");
    fwrite(STDERR, "  cut   Optional resume split offset; default = size/4\n");
    exit(2);
}

try {
    $plainBytes = parseSizeBytes((string) $argv[1]);
    $cutBytes = isset($argv[2]) && trim((string) $argv[2]) !== ''
        ? parseSizeBytes((string) $argv[2])
        : max(1, intdiv($plainBytes, 4));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(2);
}
if ($plainBytes < 2) {
    fwrite(STDERR, "size must be at least 2 bytes\n");
    exit(2);
}
if ($cutBytes < 1 || $cutBytes >= $plainBytes) {
    fwrite(STDERR, "cut must be in [1, size-1]; got cut=".$cutBytes." size=".$plainBytes."\n");
    exit(2);
}

$key = $_ENV['APP_FILE_ENCRYPTION_KEY'] ?? '';
if ($key === '') {
    fwrite(STDERR, "APP_FILE_ENCRYPTION_KEY missing\n");
    exit(2);
}

$projectDir = dirname(__DIR__, 3);
$workDir = $projectDir.'/var/test/e2e-dl100-volume';
@mkdir($workDir, 0775, true);

$plainPath = $workDir.'/source.bin';
$cipherPath = $workDir.'/source.dat';
$outPath = $workDir.'/downloaded.bin';
$zipPath = $workDir.'/archive.zip';
$extractDir = $workDir.'/extract';

/**
 * @brief Remove heavy smoke artefacts (plain/cipher/out/zip/extract); keeps run.log if present.
 * @param string $workDir Work directory.
 * @param string $plainPath Plaintext path.
 * @param string $cipherPath Cipher path.
 * @param string $outPath Download output path.
 * @param string $zipPath ZIP path.
 * @param string $extractDir Extract directory.
 * @return void
 * @date 2026-11-03
 * @author Stephane H.
 */
function cleanupVolumeArtefacts(
    string $workDir,
    string $plainPath,
    string $cipherPath,
    string $outPath,
    string $zipPath,
    string $extractDir,
): void {
    foreach ([$plainPath, $cipherPath, $cipherPath.'.cvf2idx', $outPath, $zipPath] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    if (is_dir($extractDir)) {
        foreach (glob($extractDir.'/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($extractDir);
    }
}

register_shutdown_function(static function () use ($workDir, $plainPath, $cipherPath, $outPath, $zipPath, $extractDir): void {
    cleanupVolumeArtefacts($workDir, $plainPath, $cipherPath, $outPath, $zipPath, $extractDir);
});

/**
 * @brief Human-readable byte size.
 * @param int $n Bytes.
 * @return string
 * @date 2026-11-02
 * @author Stephane H.
 */
function fmtBytes(int $n): string
{
    if ($n >= 1073741824) {
        return sprintf('%.2f GiB', $n / 1073741824);
    }

    return sprintf('%.2f MiB', $n / 1048576);
}

/**
 * @brief Free bytes on the filesystem of a path.
 * @param string $path Path on target filesystem.
 * @return int
 * @date 2026-11-02
 * @author Stephane H.
 */
function freeBytes(string $path): int
{
    $free = @disk_free_space($path);

    return is_float($free) || is_int($free) ? (int) $free : 0;
}

/**
 * @brief Log a progress line with timestamp and free disk.
 * @param string $workDir Work directory.
 * @param string $message Message.
 * @return void
 * @date 2026-11-02
 * @author Stephane H.
 */
function logStep(string $workDir, string $message): void
{
    echo sprintf(
        "[%s] %s | free=%s\n",
        date('H:i:s'),
        $message,
        fmtBytes(freeBytes($workDir)),
    );
    @ob_flush();
    flush();
}

$neededPeak = (int) ($plainBytes * 2.2);
$free = freeBytes($workDir);
if ($free < $neededPeak) {
    fwrite(STDERR, sprintf(
        "Not enough free disk: need ~%s peak, have %s\n",
        fmtBytes($neededPeak),
        fmtBytes($free),
    ));
    exit(2);
}

foreach ([$plainPath, $cipherPath, $outPath, $zipPath] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
if (is_dir($extractDir)) {
    foreach (glob($extractDir.'/**/*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

$started = microtime(true);
logStep($workDir, 'START plain='.fmtBytes($plainBytes).' cut='.fmtBytes($cutBytes));

/**
 * @brief Create a sparse plaintext of exact size with random patches at head/mid/tail.
 * @param string $path Destination path.
 * @param int $bytes Exact logical size.
 * @return void
 * @date 2026-11-02
 * @author Stephane H.
 */
function createSparsePlainWithPatches(string $path, int $bytes): void
{
    if (is_file($path)) {
        @unlink($path);
    }
    $cmd = sprintf('fallocate -l %d %s 2>&1', $bytes, escapeshellarg($path));
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($path) || filesize($path) !== $bytes) {
        // Fallback: truncate via PHP if fallocate unavailable.
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('cannot create plain file');
        }
        if ($bytes > 0 && ftruncate($fh, $bytes) === false) {
            fclose($fh);
            throw new RuntimeException('ftruncate failed');
        }
        fclose($fh);
    }
    clearstatcache(true, $path);
    if (filesize($path) !== $bytes) {
        throw new RuntimeException('plain size mismatch after allocate');
    }

    // Patch length is derived from payload size; 8 MiB is only an I/O write ceiling.
    $patchSize = (int) max(1, min(8 * 1024 * 1024, intdiv($bytes, 8)));
    $offsets = [0];
    if ($bytes > $patchSize * 2) {
        $offsets[] = intdiv($bytes, 2) - intdiv($patchSize, 2);
        $offsets[] = $bytes - $patchSize;
    }
    $fh = fopen($path, 'r+b');
    if ($fh === false) {
        throw new RuntimeException('cannot reopen plain for patches');
    }
    foreach ($offsets as $off) {
        if ($off < 0 || $off >= $bytes) {
            continue;
        }
        $len = (int) min($patchSize, $bytes - $off);
        fseek($fh, $off);
        $left = $len;
        while ($left > 0) {
            $n = (int) min(8 * 1024 * 1024, $left);
            fwrite($fh, random_bytes($n));
            $left -= $n;
        }
    }
    fclose($fh);
}

// 1) Sparse plaintext + random patches (full logical size, fast allocate).
logStep($workDir, 'Allocating sparse plaintext + random head/mid/tail patches...');
try {
    $tGen = microtime(true);
    createSparsePlainWithPatches($plainPath, $plainBytes);
    logStep($workDir, sprintf('Plain ready in %.1fs size=%s', microtime(true) - $tGen, fmtBytes($plainBytes)));
} catch (Throwable $e) {
    fwrite(STDERR, 'plain generate failed: '.$e->getMessage()."\n");
    exit(1);
}

$enc = new FileEncryptionService($key);
$index = new V2SegmentIndexService($enc);
$reader = new EncryptedRangeReader($enc, $index, max($plainBytes, 1));

// 2) Encrypt (hashes while streaming) then drop plaintext.
logStep($workDir, 'Encrypting to v2 storage (this writes ~'.fmtBytes($plainBytes).' cipher)...');
$tEnc = microtime(true);
$fingerprints = $enc->encryptPlainFileToV2Storage($plainPath, $cipherPath);
@unlink($plainPath);
$expectedSha = (string) $fingerprints['content_sha256'];
$expectedCrc = ((int) $fingerprints['content_crc32']) & 0xFFFFFFFF;
logStep($workDir, sprintf(
    'Encrypted in %.1fs, plain deleted sha=%s… crc=%u',
    microtime(true) - $tEnc,
    substr($expectedSha, 0, 16),
    $expectedCrc,
));

$shared = new SharedFile(1, $cipherPath, 'private', bin2hex(random_bytes(8)), 'source.bin', $plainBytes);
$shared->applyContentFingerprints($fingerprints);
$ref = new ReflectionClass($shared);
$idProp = $ref->getProperty('id');
$idProp->setAccessible(true);
$idProp->setValue($shared, 252525);
$resource = new FileDownloadResource($shared, $reader);

// 3) Resume-like download: first cutBytes, then remainder (2 ranges), hash, delete out.
logStep($workDir, 'Downloading via ranges (cut then remainder)...');
$tDl = microtime(true);
$out = fopen($outPath, 'wb');
if ($out === false) {
    fwrite(STDERR, "cannot open out\n");
    exit(1);
}
$ranges = [
    [0, $cutBytes],
    [$cutBytes, $plainBytes - $cutBytes],
];
$writtenTotal = 0;
foreach ($ranges as $idx => [$start, $len]) {
    logStep($workDir, sprintf('range #%d start=%s len=%s', $idx + 1, fmtBytes($start), fmtBytes($len)));
    $resource->readRange($start, $len, static function (string $chunk) use ($out, &$writtenTotal, $workDir, $plainBytes): void {
        fwrite($out, $chunk);
        $writtenTotal += strlen($chunk);
        static $last = 0;
        if (time() - $last >= 5) {
            logStep($workDir, sprintf('download %s / %s', fmtBytes($writtenTotal), fmtBytes($plainBytes)));
            $last = time();
        }
    });
}
fclose($out);
if ($writtenTotal !== $plainBytes || filesize($outPath) !== $plainBytes) {
    fwrite(STDERR, "downloaded size mismatch\n");
    exit(1);
}
$gotSha = hash_file('sha256', $outPath);
if ($gotSha !== $expectedSha) {
    fwrite(STDERR, "downloaded SHA mismatch\n");
    exit(1);
}
logStep($workDir, sprintf('Download OK in %.1fs sha match, deleting out', microtime(true) - $tDl));
@unlink($outPath);

// 4) Virtual ZIP streamed from cipher, unzip -t, extract + hash.
logStep($workDir, 'Building virtual ZIP...');
$tZip = microtime(true);
$layout = VirtualZipLayout::fromEntries([[
    'name' => 'source.bin',
    'size' => $plainBytes,
    'mtime' => time(),
    'crc32' => $expectedCrc,
]]);
$zip = fopen($zipPath, 'wb');
if ($zip === false) {
    fwrite(STDERR, "cannot open zip\n");
    exit(1);
}
fwrite($zip, $layout->buildLocalHeader(0));
$zipped = 0;
$resource->readRange(0, $plainBytes, static function (string $chunk) use ($zip, &$zipped, $workDir, $plainBytes): void {
    fwrite($zip, $chunk);
    $zipped += strlen($chunk);
    static $last = 0;
    if (time() - $last >= 5) {
        logStep($workDir, sprintf('zip data %s / %s', fmtBytes($zipped), fmtBytes($plainBytes)));
        $last = time();
    }
});
fwrite($zip, $layout->buildCentralDirectoryAndEndings());
fclose($zip);
if (filesize($zipPath) !== $layout->getTotalSize()) {
    fwrite(STDERR, "zip size mismatch\n");
    exit(1);
}
logStep($workDir, sprintf('ZIP built in %.1fs size=%s', microtime(true) - $tZip, fmtBytes((int) filesize($zipPath))));

$unzipTest = [];
exec('unzip -t '.escapeshellarg($zipPath).' 2>&1', $unzipTest, $code);
echo implode("\n", $unzipTest)."\n";
if ($code !== 0) {
    fwrite(STDERR, "unzip -t failed\n");
    exit(1);
}

// Extract needs another full plain copy — check free space first.
$freeNow = freeBytes($workDir);
if ($freeNow < (int) ($plainBytes * 1.05)) {
    logStep($workDir, 'Skipping extract (not enough free disk after ZIP); unzip -t + download SHA already OK');
} else {
    @mkdir($extractDir, 0775, true);
    logStep($workDir, 'Extracting ZIP for final SHA check...');
    $extractOut = [];
    exec('unzip -o '.escapeshellarg($zipPath).' -d '.escapeshellarg($extractDir).' 2>&1', $extractOut, $extractCode);
    if ($extractCode !== 0) {
        fwrite(STDERR, "unzip extract failed\n");
        exit(1);
    }
    $extractedSha = hash_file('sha256', $extractDir.'/source.bin');
    if ($extractedSha !== $expectedSha) {
        fwrite(STDERR, "extracted SHA mismatch\n");
        exit(1);
    }
    logStep($workDir, 'Extract SHA match');
}

$elapsed = microtime(true) - $started;
logStep($workDir, sprintf('SMOKE VOLUME OK bytes=%d elapsed=%.1fs (cleanup on exit)', $plainBytes, $elapsed));
echo "PASS\n";
exit(0);
