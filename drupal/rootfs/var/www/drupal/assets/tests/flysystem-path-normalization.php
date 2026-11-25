<?php

// Regression check for the Flysystem 1.x backport of CVE-2026-102601.
require __DIR__ . '/../../vendor/autoload.php';

use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\Util;

foreach (["foo\x80\x1b bar", "foo\x80bar", "foo\x1b bar", "foo\0bar", "foo\xffbar"] as $path) {
    try {
        Util::normalizePath($path);
    } catch (CorruptedPathDetected $e) {
        continue;
    }
    throw new RuntimeException('Accepted corrupted path: ' . bin2hex($path));
}

foreach (['folder/../café.txt' => 'café.txt', '目录/文件.txt' => '目录/文件.txt', 'foo\\bar.txt' => 'foo/bar.txt'] as $path => $expected) {
    if (Util::normalizePath($path) !== $expected) {
        throw new RuntimeException('Valid path normalization changed.');
    }
}

try {
    Util::normalizePath('../outside.txt');
    throw new RuntimeException('Accepted path traversal.');
} catch (LogicException $e) {
    // Existing traversal protection must remain intact.
}

echo "Flysystem path normalization checks passed.\n";
