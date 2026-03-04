<?php
/**
 * serve.php — One-time file download endpoint.
 *
 * Receives a 32-character hex token that maps to a temporary directory
 * created by download.php.  If the token is valid and a file exists,
 * it is streamed to the browser as an attachment download.  The file
 * and its temporary directory are deleted immediately afterwards so
 * each token can only be used once.
 *
 * Platform differences (Windows localhost vs Linux server):
 *
 *   Temp directory
 *     Windows – %TEMP%\ytdl_<token>   (via sys_get_temp_dir())
 *     Linux   – /tmp/ytdl_<token>      (via sys_get_temp_dir())
 *
 *   File discovery
 *     Both    – glob() first, scandir() fallback (glob can miss
 *               files with long paths or unicode names on Windows)
 *
 *   Streaming
 *     Both    – output buffering disabled, file read in 8 KB chunks
 *               to avoid memory_limit issues with large files
 */

// Large files may take a while to stream — remove the time limit
set_time_limit(0);

// ── Validate the token ──────────────────────────────────────────────
$token = $_GET['token'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    die('Invalid token');
}

// ── Locate the temporary download directory ─────────────────────────
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ytdl_' . $token;

if (!is_dir($tmpDir)) {
    http_response_code(404);
    die('Download not found or expired');
}

// ── Find the downloaded file (there should be exactly one) ──────────
// glob() can miss files on Windows with long paths or unicode names,
// so fall back to scandir() if glob returns nothing.
$files = glob($tmpDir . DIRECTORY_SEPARATOR . '*');
if (empty($files)) {
    $entries = @scandir($tmpDir);
    if ($entries) {
        $entries = array_diff($entries, ['.', '..']);
        foreach ($entries as $entry) {
            $files[] = $tmpDir . DIRECTORY_SEPARATOR . $entry;
        }
    }
}
if (empty($files)) {
    http_response_code(404);
    die('No file found');
}

$file     = $files[0];
$filename = basename($file);
$filesize = filesize($file);

// ── Stream the file to the client as a binary download ──────────────
// Disable all output buffering so PHP streams the file directly
// instead of trying to buffer hundreds of MB into memory.
while (ob_get_level()) ob_end_clean();

header_remove('Content-Type');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache');

// Stream in 8 KB chunks instead of readfile() which can hit
// memory_limit when output buffering is implicitly re-enabled.
$fp = fopen($file, 'rb');
if ($fp === false) {
    http_response_code(500);
    die('Cannot open file');
}
while (!feof($fp)) {
    echo fread($fp, 8192);
    flush();
}
fclose($fp);

// ── Clean up: remove the file and its temporary directory ───────────
array_map('unlink', glob($tmpDir . '/*'));
@rmdir($tmpDir);
