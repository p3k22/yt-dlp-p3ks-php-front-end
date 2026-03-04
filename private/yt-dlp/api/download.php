<?php
/**
 * download.php — SSE-based media download endpoint.
 *
 * Accepts a URL and format/quality/filetype options via GET parameters,
 * invokes yt-dlp as a subprocess, and streams real-time progress back
 * to the browser using Server-Sent Events (SSE).  On completion it
 * emits a "done" event containing a one-time token that the client can
 * exchange via serve.php to retrieve the downloaded file.
 *
 * SSE event types emitted:
 *   log      – raw yt-dlp output lines
 *   phase    – signals a new download phase (video / audio / merging)
 *   progress – percentage, speed, and ETA for the current download
 *   done     – download complete (includes token + filename)
 *   error    – fatal or recoverable error message
 *
 * Platform differences (Windows localhost vs Linux server):
 *
 *   yt-dlp binary
 *     Windows – bin\yt-dlp.exe (project-local), then `where` fallback
 *     Linux   – bin/yt-dlp, /usr/local/bin/yt-dlp, then `which`
 *
 *   ffmpeg binary
 *     Windows – bin\ffmpeg.exe (project-local), then `where` fallback
 *     Linux   – bin/ffmpeg, /usr/local/bin/ffmpeg, then `which`
 *     Both    – if found, passed to yt-dlp via --ffmpeg-location
 *
 *   Temp directory
 *     Windows – %TEMP%\ytdl_<token>   (via sys_get_temp_dir())
 *     Linux   – /tmp/ytdl_<token>      (via sys_get_temp_dir())
 *
 *   Process execution
 *     Both    – proc_open() with an array argument list (no shell).
 *               stderr is redirected into stdout via ['redirect', 1]
 *               to avoid the Windows pipe-blocking deadlock.
 *
 *   Binary detection
 *     Windows – file_exists() (is_executable() unreliable on .exe)
 *     Linux   – is_executable()
 *
 *   User identity (logging)
 *     Windows – get_current_user() (POSIX functions unavailable)
 *     Linux   – posix_getpwuid(posix_geteuid())
 */

// ── Platform detection ───────────────────────────────────────────────
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

// ── Error handling & server-side logging ─────────────────────────────
$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ytdlp.log';

/**
 * Appends a timestamped message to the server log file.
 */
function logMsg(string $msg): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$msg}\n", FILE_APPEND | LOCK_EX);
}

/**
 * Sends a single SSE event frame to the client.
 */
function sseEvent(string $event, string $data): void {
    echo "event: {$event}\ndata: {$data}\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

/**
 * Sends an SSE error event, logs the detail, and terminates.
 */
function sseFail(string $msg, string $logDetail = ''): never {
    logMsg('ERROR: ' . ($logDetail ?: $msg));
    sseEvent('error', $msg);
    exit(1);
}

// Convert PHP notices/warnings into exceptions for consistent handling
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatal errors that bypass the try/catch and notify the client
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        logMsg("FATAL: {$err['message']} in {$err['file']}:{$err['line']}");
        sseEvent('error', 'Fatal PHP error — check server logs');
    }
});

// ── SSE response headers ────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // disable nginx proxy buffering
if (ob_get_level()) ob_end_flush();

// Downloads can take minutes — remove PHP's execution time limit
set_time_limit(0);
ignore_user_abort(false);   // stop cleanly if the client disconnects

try {

    // ── Binary search helper ─────────────────────────────────────────
    // Searches: project bin/ → /usr/local/bin (Linux) → which/where
    /**
     * Locates a binary by name using a consistent search order:
     *   1. Project-local bin/ directory
     *   2. /usr/local/bin (Linux only — typical manual-install location)
     *   3. which (Linux) / where (Windows) fallback
     *
     * @return array{path:string,source:string}|null  Binary info, or null if not found.
     *         'source' is 'local' (project bin/) or 'system' (OS path).
     */
    function findBinary(string $name, bool $isWindows): ?array {
        $exeName  = $isWindows ? "{$name}.exe" : $name;
        $localBin = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin'
                  . DIRECTORY_SEPARATOR . $exeName;

        // Check project-local bin/ first
        if ($isWindows ? file_exists($localBin) : is_executable($localBin)) {
            return ['path' => $localBin, 'source' => 'local'];
        }

        // Linux: check /usr/local/bin
        if (!$isWindows) {
            $sysPath = "/usr/local/bin/{$name}";
            if (is_executable($sysPath)) {
                return ['path' => $sysPath, 'source' => 'system'];
            }
        }

        // Fallback: ask the OS (use proc_open since shell_exec may be disabled)
        $cmd    = $isWindows ? "where {$name} 2>nul" : "which {$name} 2>/dev/null";
        $fallback = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fp, null, null, ['bypass_shell' => false]);
        if (is_resource($fallback)) {
            $output = trim(explode("\n", stream_get_contents($fp[1]))[0]);
            fclose($fp[1]); fclose($fp[2]); proc_close($fallback);
        } else {
            $output = '';
        }
        if ($output !== '' && ($isWindows ? file_exists($output) : is_executable($output))) {
            return ['path' => $output, 'source' => 'system'];
        }

        return null;
    }

    // ── Locate the yt-dlp binary ────────────────────────────────────
    global $isWindows;
    $ytdlpInfo = findBinary('yt-dlp', $isWindows);

    if (!$ytdlpInfo) {
        sseFail('yt-dlp not found', 'Searched bin/, /usr/local/bin, and which/where');
    }

    $ytdlp = $ytdlpInfo['path'];
    $ytdlpSource = $ytdlpInfo['source'] === 'local' ? 'Local' : 'System';
    $ytdlpVersion = 'unknown';
    $vProc = @proc_open([$ytdlp, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $vp);
    if (is_resource($vProc)) {
        $ytdlpVersion = trim(stream_get_contents($vp[1]));
        fclose($vp[1]); fclose($vp[2]); proc_close($vProc);
    }

    // ── Locate the ffmpeg binary (optional but needed for merging) ────
    $ffmpegInfo = findBinary('ffmpeg', $isWindows);

    $ffmpegVersion = 'not found';
    $ffmpegSource = '';
    if ($ffmpegInfo) {
        $ffmpeg = $ffmpegInfo['path'];
        $ffmpegSource = $ffmpegInfo['source'] === 'local' ? 'Local' : 'System';
        $fProc = @proc_open([$ffmpeg, '-version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fpp);
        if (is_resource($fProc)) {
            $ffmpegOut = trim(explode("\n", stream_get_contents($fpp[1]))[0]);
            fclose($fpp[1]); fclose($fpp[2]); proc_close($fProc);
            $ffmpegVersion = preg_match('/version\s+(\S+)/', $ffmpegOut, $fv) ? $fv[1] : 'unknown';
        }
    } else {
        $ffmpeg = null;
    }

    // Single-line binary info for both log and browser
    $ffmpegLabel = $ffmpeg ? "ffmpeg {$ffmpegVersion} ({$ffmpegSource})" : 'ffmpeg not found';
    $binInfo = "yt-dlp {$ytdlpVersion} ({$ytdlpSource}) | {$ffmpegLabel}";
    logMsg($binInfo);
    sseEvent('log', $binInfo);

    // ── Validate & sanitise user input ────────────────────────────────
    $url      = trim($_GET['url'] ?? '');
    $format   = $_GET['format'] ?? 'both';
    $quality  = $_GET['quality'] ?? 'best';
    $filetype = $_GET['filetype'] ?? 'mp4';

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        sseFail('Invalid URL');
    }

    // Whitelist every accepted value to prevent injection
    $allowedFormats   = ['both', 'video', 'audio'];
    $allowedQualities = ['best', '1080', '720', '480', '360'];
    $allowedTypes     = ['mp4', 'mkv', 'webm', 'mp3', 'm4a', 'ogg'];

    if (!in_array($format, $allowedFormats) ||
        !in_array($quality, $allowedQualities) ||
        !in_array($filetype, $allowedTypes)) {
        sseFail('Invalid options');
    }

    // ── Build the yt-dlp command ──────────────────────────────────────
    // Create a unique token and temp directory for this download
    $token  = bin2hex(random_bytes(16));
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ytdl_' . $token;
    mkdir($tmpDir, 0755, true);
    $output = $tmpDir . DIRECTORY_SEPARATOR . '%(title)s.%(ext)s';

    // Build the argument list as a plain array (no shell escaping).
    // proc_open with an array bypasses the shell entirely, so % and
    // other special characters are passed verbatim to yt-dlp.  This
    // prevents Windows cmd.exe from stripping the % in templates like
    // %(title)s and %(progress._percent_str)s.
    $args = [
        $ytdlp,
        '-o', $output,
        '--no-playlist',
        '--no-warnings',
        '--newline',
        '--progress-template', 'download:%(progress._percent_str)s %(progress._speed_str)s ETA %(progress._eta_str)s',
    ];

    // Point yt-dlp to the ffmpeg binary if we found one.
    // Pass the exact binary path rather than the directory, because the
    // bin/ folder may contain both Linux (ffmpeg) and Windows (ffmpeg.exe)
    // builds; passing the directory lets yt-dlp pick the wrong one.
    if ($ffmpeg) {
        $args[] = '--ffmpeg-location';
        $args[] = $ffmpeg;
    }

    // yt-dlp requires a JS runtime (e.g. deno, node) for YouTube extraction.
    // When PHP runs as www-data under Apache/Nginx (or as a local dev
    // server on Windows), the PATH is minimal and may not include the
    // directory where the JS runtime is installed.
    // Explicitly pass the runtime path so yt-dlp can always find it.
    $jsRuntime = null;

    if ($isWindows) {
        // Windows: check well-known install paths, project bin/, then PATH.
        // Deno installs to %USERPROFILE%\.deno\bin\deno.exe by default.
        // Node.js installs to %ProgramFiles%\nodejs\node.exe (or x86 variant).
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        $progFiles    = getenv('ProgramFiles') ?: 'C:\\Program Files';
        $progFilesX86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';

        $windowsCandidates = [
            'deno' => array_filter([
                $home ? "{$home}\\.deno\\bin\\deno.exe" : null,
            ]),
            'node' => [
                "{$progFiles}\\nodejs\\node.exe",
                "{$progFilesX86}\\nodejs\\node.exe",
            ],
        ];

        foreach ($windowsCandidates as $rtName => $paths) {
            // 1. Check well-known install locations
            foreach ($paths as $candidate) {
                if ($candidate && file_exists($candidate)) {
                    $jsRuntime = ['name' => $rtName, 'path' => $candidate];
                    break 2;
                }
            }
            // 2. Fall back to findBinary (project bin/ + where)
            $rtInfo = findBinary($rtName, true);
            if ($rtInfo) {
                $jsRuntime = ['name' => $rtName, 'path' => $rtInfo['path']];
                break;
            }
        }
    } else {
        // Linux: check well-known install locations, then PATH
        foreach (['deno', 'node'] as $rt) {
            $wellKnown = "/usr/local/bin/{$rt}";
            if (is_executable($wellKnown)) {
                $jsRuntime = ['name' => $rt, 'path' => $wellKnown];
                break;
            }
            $rtInfo = findBinary($rt, false);
            if ($rtInfo) {
                $jsRuntime = ['name' => $rt, 'path' => $rtInfo['path']];
                break;
            }
        }
    }

    if ($jsRuntime) {
        $args[] = '--js-runtimes';
        $args[] = "{$jsRuntime['name']}:{$jsRuntime['path']}";
        sseEvent('log', "Using {$jsRuntime['name']}: {$jsRuntime['path']}");
    } else {
        sseEvent('log', 'WARNING: No JS runtime (deno/node) found — YouTube downloads may fail');
        logMsg('WARNING: No JS runtime found. Searched well-known paths, bin/, and PATH.');
    }

    // Audio-only: extract audio and convert to the requested format
    // yt-dlp expects "vorbis" not "ogg" for --audio-format
    $audioFormat = ($filetype === 'ogg') ? 'vorbis' : $filetype;
    if ($format === 'audio') {
        $args[] = '-x';
        $args[] = '--audio-format';
        $args[] = $audioFormat;
        $args[] = '-f';
        $args[] = 'bestaudio';
    } else {
        // Video (with or without audio): build the format selector string
        // based on the requested quality cap
        if ($quality === 'best') {
            $fmtStr = ($format === 'video') ? 'bestvideo' : 'bestvideo+bestaudio/best';
        } else {
            $fmtStr = ($format === 'video')
                ? "bestvideo[height<={$quality}]"
                : "bestvideo[height<={$quality}]+bestaudio/best[height<={$quality}]";
        }
        $args[] = '-f';
        $args[] = $fmtStr;
        $args[] = '--merge-output-format';
        $args[] = $filetype;
        $args[] = '--remux-video';
        $args[] = $filetype;
    }

    $args[] = $url;

    $logPrefix = "{$filetype} {$quality} {$url}";
    sseEvent('log', 'starting download...');

    // Track how many download streams to expect
    // (video+audio = 2 separate downloads, otherwise 1)
    $totalStreams = ($format === 'both') ? 2 : 1;
    $currentStream = 0;  // incremented each time we see a new destination
    $merging = false;

    // ── Execute yt-dlp via proc_open for real-time output ───────────
    // Redirect stderr into the stdout pipe so we only read one stream.
    // This avoids the Windows deadlock where stream_set_blocking()
    // silently fails on pipes and fgets() blocks forever on an empty
    // stderr pipe.
    $proc = proc_open($args, [
        1 => ['pipe', 'w'],    // stdout
        2 => ['redirect', 1],  // merge stderr → stdout
    ], $pipes);

    if (!is_resource($proc)) {
        sseFail('Failed to start yt-dlp process');
    }

    // ── Main output-parsing loop ─────────────────────────────────────
    // Reads yt-dlp output line-by-line (blocking), classifies each
    // line, and emits the appropriate SSE event to the client.
    // Blocking reads on a single merged pipe work reliably on all OS.
    $allOutput = '';
    while (($rawLine = fgets($pipes[1])) !== false) {
        $line = trim($rawLine);
        if ($line === '') continue;
        $allOutput .= $line . "\n";

        // New download stream starting (e.g. "[download] Destination: ...")
        if (preg_match('/^\[download\]\s+Destination:/', $line)) {
            $currentStream++;
            $streamLabel = ($totalStreams === 2)
                ? ($currentStream === 1 ? 'video' : 'audio')
                : ($format === 'audio' ? 'audio' : 'video');
            sseEvent('phase', json_encode([
                'stream'       => $currentStream,
                'totalStreams'  => $totalStreams,
                'label'        => $streamLabel,
            ]));
            sseEvent('log', "downloading {$streamLabel}...");
        }
        // File was already downloaded — treat as a completed stream
        elseif (preg_match('/^\[download\]\s+.+has already been downloaded/', $line)) {
            $currentStream++;
            $streamIdx   = max($currentStream - 1, 0);
            $overallPct  = (($streamIdx + 1) * 100.0) / $totalStreams;
            sseEvent('progress', json_encode([
                'percent'      => round($overallPct, 1),
                'streamPct'    => 100.0,
                'speed'        => '',
                'eta'          => '00:00',
                'stream'       => $currentStream,
                'totalStreams'  => $totalStreams,
            ]));
            sseEvent('log', 'already downloaded, skipping');
        }
        // FFmpeg merge / mux / fixup phase
        elseif (preg_match('/^\[Merger\]|^\[Mux\]|^\[FixupM/', $line)) {
            if (!$merging) {
                $merging = true;
                sseEvent('phase', json_encode(['label' => 'merging']));
                sseEvent('log', 'merging streams...');
            }
        }
        // In-progress line from our --progress-template:
        //   "  50.0%  5.00MiB/s ETA 00:10"
        elseif (preg_match('/^\s*(\d+\.?\d*)%\s+(.+?)\s+ETA\s+(\S+)/', $line, $m)) {
            $streamPct = (float) $m[1];
            $speed     = trim($m[2]);
            $eta       = $m[3];

            // Calculate overall percentage across all streams
            $streamIdx   = max($currentStream - 1, 0); // 0-based
            $overallPct  = ($streamIdx * 100.0 + $streamPct) / $totalStreams;

            sseEvent('progress', json_encode([
                'percent'  => round($overallPct, 1),
                'streamPct'=> round($streamPct, 1),
                'speed'    => $speed,
                'eta'      => $eta,
                'stream'   => $currentStream,
                'totalStreams' => $totalStreams,
            ]));
        }
        // 100 % completion line (no ETA present)
        elseif (preg_match('/^\s*100(\.0)?%/', $line)) {
            $streamIdx   = max($currentStream - 1, 0);
            $overallPct  = (($streamIdx + 1) * 100.0) / $totalStreams;

            sseEvent('progress', json_encode([
                'percent'  => round($overallPct, 1),
                'streamPct'=> 100.0,
                'speed'    => '',
                'eta'      => '00:00',
                'stream'   => $currentStream,
                'totalStreams' => $totalStreams,
            ]));
        }
        // Anything else — only forward actual yt-dlp errors
        elseif (preg_match('/^ERROR:/', $line)) {
            sseEvent('log', $line);
        }
    }

    // ── Clean up process resources ──────────────────────────────────
    fclose($pipes[1]);
    $status   = proc_get_status($proc);
    $exitCode = proc_close($proc);
    // proc_close returns -1 on Windows; prefer proc_get_status exitcode
    if ($status['exitcode'] !== -1) {
        $exitCode = $status['exitcode'];
    }

    // Non-zero exit means yt-dlp reported an error
    if ($exitCode !== 0) {
        cleanup($tmpDir);
        sseFail('yt-dlp failed', "{$logPrefix} — ERROR:\n{$allOutput}");
    }

    // ── Signal completion — send the one-time download token ────────
    $files = glob($tmpDir . '/*');
    if (empty($files)) {
        cleanup($tmpDir);
        sseFail('No file was downloaded', "{$logPrefix} — ERROR: no output file");
    }

    $filename = basename($files[0]);
    logMsg("{$logPrefix} — SUCCESS");

    sseEvent('done', json_encode([
        'token'    => $token,
        'filename' => $filename,
    ]));

    // Exit immediately so the (possibly single-threaded) PHP dev
    // server is free to handle the follow-up serve.php request.
    exit(0);

} catch (Throwable $e) {
    logMsg(($logPrefix ?? 'unknown') . " — ERROR: {$e->getMessage()}");
    sseEvent('error', 'Server error — check logs');
}

// ── Helpers ─────────────────────────────────────────────────────────

/**
 * Removes all files inside a directory and then the directory itself.
 */
function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
}