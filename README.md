# yt-dlp.p3k

A lightweight, self-hosted web front-end for downloading video and audio from YouTube and [thousands of other sites](https://github.com/yt-dlp/yt-dlp/blob/master/supportedsites.md). Powered by [yt-dlp](https://github.com/yt-dlp/yt-dlp), ffmpeg, and PHP.

---

## Features

- **Video + Audio, Video Only, or Audio Only** — pick the media streams you need
- **Quality selection** — Best, 1080p, 720p, or 480p
- **Multiple output formats** — mp4 / mkv / webm for video; mp3 / m4a / ogg for audio
- **Real-time progress** — live percentage, speed, and ETA streamed to the browser via Server-Sent Events (SSE)
- **One-time download tokens** — finished files are served through a single-use token and deleted immediately after delivery
- **Cross-platform** — runs on Windows and Linux with automatic yt-dlp and ffmpeg binary detection
- **No frameworks** — vanilla HTML / CSS / JS front-end with ES modules; no build step required

---

## Prerequisites

| Tool | Purpose |
|------|---------|
| [PHP 8.0+](https://www.php.net/) | Backend server & yt-dlp process management |
| [yt-dlp](https://github.com/yt-dlp/yt-dlp) | Media downloading engine |
| [ffmpeg + ffprobe](https://github.com/yt-dlp/FFmpeg-Builds?tab=readme-ov-file#ffmpeg-static-auto-builds) | Audio extraction, stream merging, and remuxing |
| [Deno](https://deno.land/) (or Node.js) | JS runtime required by yt-dlp to decrypt YouTube signatures |

> **Binary lookup order**
>
> The backend checks `bin/` first. If a binary isn't found there, Linux falls back to `/usr/local/bin/` and then `which`. Windows does **not** have this fallback for yt-dlp — only for ffmpeg/ffprobe if they are already installed and on `PATH`.
>
> **Recommendation:** Place all three binaries in the project's `bin/` folder for a portable, zero-config setup on any platform.
---

## Quick Start

### 1. Clone the repository

```bash
git clone https://github.com/p3k22/yt-dlp-p3ks-php-front-end.git
cd yt-dlp-p3ks-php-front-end
```

### 2. Install dependencies

#### Windows (localhost)

1. **PHP** — download from [windows.php.net](https://windows.php.net/download/) (Thread Safe zip). Extract somewhere and add the folder to your system PATH.
2. **yt-dlp** — download `yt-dlp.exe` from [yt-dlp releases](https://github.com/yt-dlp/yt-dlp/releases/latest) and place it in this project's `bin/` folder.
3. **ffmpeg** — download `ffmpeg-master-latest-win64-gpl.zip` from [yt-dlp/FFmpeg-Builds](https://github.com/yt-dlp/FFmpeg-Builds/releases/latest). Extract `ffmpeg.exe` and `ffprobe.exe` from the `bin/` inside the archive into this project's `bin/` folder.
4. **Deno** — install via PowerShell:
   ```powershell
   irm https://deno.land/install.ps1 | iex
   ```
   Alternatively, install [Node.js](https://nodejs.org/) if you already have it. yt-dlp needs a JS runtime to handle YouTube's encrypted signatures — without one, downloads will fail with *"This video is not available"*. Restart your terminal after installing so the new PATH takes effect.

Your `bin/` folder should contain:
```
bin/
├── yt-dlp.exe
├── ffmpeg.exe
└── ffprobe.exe
```

#### Linux server (Ubuntu/Debian x64)

**Option A — project-local binaries (no root required):**

```bash
# yt-dlp
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o bin/yt-dlp
chmod +x bin/yt-dlp

# ffmpeg + ffprobe
curl -L https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz -o /tmp/ffmpeg.tar.xz
tar -xf /tmp/ffmpeg.tar.xz --strip-components=2 -C bin/ --wildcards '*/bin/ffmpeg' '*/bin/ffprobe'
chmod +x bin/ffmpeg bin/ffprobe
rm /tmp/ffmpeg.tar.xz
```

Your `bin/` folder should contain:
```
bin/
├── yt-dlp
├── ffmpeg
└── ffprobe
```

**Option B — system-wide install to `/usr/local/bin` (requires root):**

```bash
# yt-dlp
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp

# ffmpeg + ffprobe
curl -L https://github.com/yt-dlp/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz -o /tmp/ffmpeg.tar.xz
sudo tar -xf /tmp/ffmpeg.tar.xz --strip-components=2 -C /usr/local/bin/ --wildcards '*/bin/ffmpeg' '*/bin/ffprobe'
sudo chmod a+rx /usr/local/bin/ffmpeg /usr/local/bin/ffprobe
rm /tmp/ffmpeg.tar.xz
```

**Deno** (JS runtime for yt-dlp YouTube extraction):
```bash
curl -fsSL https://deno.land/install.sh | sh
# or, if you prefer Node.js:
# sudo apt install nodejs
```

**PHP** (if not already installed):
```bash
sudo apt update && sudo apt install php-cli
```

### 3. Start the server

**Windows** — double-click `start-server.bat` or run:

```bat
php -S localhost:8000 -t public/yt-dlp
```

**Linux / macOS:**

```bash
php -S localhost:8000 -t public/yt-dlp
```

### 4. Open the UI

Navigate to [http://localhost:8000](http://localhost:8000) in your browser. Paste a URL, choose your format options, and hit **GET**.

---

## Project Structure

```
├── index.html                  Main UI
├── start-server.bat            Windows quick-start script
├── api/
│   ├── download.php            SSE endpoint — runs yt-dlp, streams progress
│   └── serve.php               One-time file download endpoint
├── bin/
│   ├── yt-dlp(.exe)            (optional) project-local yt-dlp binary
│   ├── ffmpeg(.exe)            (optional) project-local ffmpeg binary
│   └── ffprobe(.exe)           (optional) project-local ffprobe binary
├── css/
│   └── style.css               Stylesheet (dark theme, custom properties)
└── js/
    ├── main.js                 Entry point — wires up UI and download controller
    ├── controller/
    │   └── downloadController.js   Orchestrates download lifecycle and UI updates
    ├── network/
    │   └── downloadClient.js       SSE client wrapper with typed event hooks
    ├── ui/
    │   ├── progressBar.js          Horizontal progress bar component
    │   ├── progressRing.js         Circular SVG progress indicator
    │   └── toggleController.js     Mutually-exclusive toggle button logic
    └── utils/
        └── escapeHtml.js           HTML entity escaping utility
```

---

## How It Works

1. The user pastes a URL and selects format, quality, and file-type options in the browser.
2. The front-end opens an **SSE connection** to `api/download.php` with the chosen parameters.
3. The PHP backend validates input, locates the `yt-dlp` and `ffmpeg` binaries, and spawns yt-dlp via `proc_open()` with a progress template (passing `--ffmpeg-location` if ffmpeg was found locally).
4. yt-dlp output is parsed line-by-line and forwarded to the browser as typed SSE events (`log`, `phase`, `progress`, `done`, `error`).
5. On completion, the server emits a one-time hex token. The client uses this token to fetch the file from `api/serve.php`.
6. `serve.php` streams the file in 8 KB chunks, then deletes it and its temp directory immediately.

---

## Configuration

There is no configuration file — all behaviour is controlled through the UI toggles and server-side defaults:

| Setting | Default | Notes |
|---------|---------|-------|
| Listen port | `8000` | Change in `start-server.bat` or your PHP command |
| yt-dlp binary | `bin/yt-dlp` | Falls back to `/usr/local/bin` (Linux), then `which` / `where` |
| ffmpeg binary | `bin/ffmpeg` | Falls back to `/usr/local/bin` (Linux), then `which` / `where` |
| Temp directory | OS temp dir | `%TEMP%\ytdl_*` on Windows, `/tmp/ytdl_*` on Linux |
| PHP time limit | Unlimited | `set_time_limit(0)` during downloads |

---

## Deployment

For production use, deploy behind a PHP-capable web server such as **Apache** or **Nginx** with PHP-FPM. Ensure:

- `yt-dlp` and `ffmpeg` are installed and executable by the web server user
- The OS temp directory is writable
- Nginx users should add `X-Accel-Buffering: no` support (the API already sends this header) to prevent SSE buffering

---

## License

MIT