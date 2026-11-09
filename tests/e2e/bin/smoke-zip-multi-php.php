#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @brief Build a multi-entry virtual ZIP and verify unzip + SHA-256 per entry.
 * @date 2026-11-02
 * @author Stephane H.
 *
 * Usage: php tests/e2e/bin/smoke-zip-multi-php.php [count=3] [bytesPerFile=8388608]
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

$count = isset($argv[1]) ? max(1, (int) $argv[1]) : 3;
$bytes = isset($argv[2]) ? max(1024, (int) $argv[2]) : 8 * 1024 * 1024;
$key = $_ENV['APP_FILE_ENCRYPTION_KEY'] ?? '';
if ($key === '') {
    fwrite(STDERR, "APP_FILE_ENCRYPTION_KEY missing\n");
    exit(2);
}

$work = dirname(__DIR__, 3).'/var/test/e2e-dl100-zip';
@mkdir($work, 0775, true);
foreach (glob($work.'/*') ?: [] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}

$enc = new FileEncryptionService($key);
$reader = new EncryptedRangeReader($enc, new V2SegmentIndexService($enc));
$layoutEntries = [];
$resources = [];
$expected = [];

for ($i = 0; $i < $count; ++$i) {
    $plain = $work.'/p'.$i.'.bin';
    $cipher = $work.'/c'.$i.'.dat';
    $data = random_bytes($bytes);
    file_put_contents($plain, $data);
    $fp = $enc->encryptPlainFileToV2Storage($plain, $cipher);
    $sf = new SharedFile(1, $cipher, 'private', bin2hex(random_bytes(4)), 'file'.$i.'.bin', $bytes);
    $sf->applyContentFingerprints($fp);
    $ref = new ReflectionClass($sf);
    $p = $ref->getProperty('id');
    $p->setAccessible(true);
    $p->setValue($sf, 1000 + $i);
    $resources[] = new FileDownloadResource($sf, $reader);
    $layoutEntries[] = [
        'name' => 'file'.$i.'.bin',
        'size' => $bytes,
        'mtime' => time(),
        'crc32' => $fp['content_crc32'],
    ];
    $expected['file'.$i.'.bin'] = hash('sha256', $data);
}

$layout = VirtualZipLayout::fromEntries($layoutEntries);
$zipPath = $work.'/multi.zip';
$zh = fopen($zipPath, 'wb');
foreach ($layoutEntries as $i => $_) {
    fwrite($zh, $layout->buildLocalHeader($i));
    $resources[$i]->readRange(0, $bytes, static function (string $c) use ($zh): void {
        fwrite($zh, $c);
    });
}
fwrite($zh, $layout->buildCentralDirectoryAndEndings());
fclose($zh);

if (filesize($zipPath) !== $layout->getTotalSize()) {
    fwrite(STDERR, "ZIP size mismatch\n");
    exit(1);
}

$o = [];
exec('unzip -t '.escapeshellarg($zipPath).' 2>&1', $o, $c);
echo implode("\n", $o)."\n";
if ($c !== 0) {
    exit(1);
}

$extract = $work.'/out';
@mkdir($extract, 0775, true);
exec('unzip -o '.escapeshellarg($zipPath).' -d '.escapeshellarg($extract).' 2>&1', $o2, $c2);
if ($c2 !== 0) {
    exit(1);
}
foreach ($expected as $name => $sha) {
    $got = hash_file('sha256', $extract.'/'.$name);
    if ($got !== $sha) {
        fwrite(STDERR, "hash fail $name\n");
        exit(1);
    }
}

echo "ZIP multi OK count=$count bytes=$bytes\n";
exit(0);
