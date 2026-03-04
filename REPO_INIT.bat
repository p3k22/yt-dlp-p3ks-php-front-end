@echo off
setlocal ENABLEDELAYEDEXPANSION
cd /d "%~dp0"

echo Default: git@github.com:p3k22/yt-dlp-p3ks-php-front-end.git
set /p REPO_URL="Enter repo URL (or press Enter for default): "
if "%REPO_URL%"=="" set REPO_URL=git@github.com:p3k22/yt-dlp-p3ks-php-front-end.git

set COMMIT_MSG=%~1
if "%COMMIT_MSG%"=="" set COMMIT_MSG=Initial commit

set "PKG_VERSION="
for /f "usebackq delims=" %%v in (`
  powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$p='package.json'; if (Test-Path $p) { (Get-Content -Raw $p | ConvertFrom-Json).version }"
`) do set "PKG_VERSION=%%v"
if defined PKG_VERSION set "COMMIT_MSG=%COMMIT_MSG% [v%PKG_VERSION%]"

if exist ".git" rmdir /s /q .git >nul 2>&1
git init >nul 2>&1
git config core.autocrlf true >nul 2>&1
git config --global --add safe.directory "%cd%" >nul 2>&1

git add -A >nul 2>&1

rem Only show actual file paths, no blanks
for /f "skip=1 delims=" %%f in ('git diff --cached --name-only') do (
  echo Uploaded: %%f
)

git commit -m "%COMMIT_MSG%" >nul 2>&1
git branch -M main >nul 2>&1
git remote remove origin >nul 2>&1
git remote add origin %REPO_URL% >nul 2>&1
git push -f origin main >nul 2>&1

if defined PKG_VERSION (
  set "TAG=v%PKG_VERSION%"
  git tag -a "!TAG!" -m "!TAG!" >nul 2>&1
  git push -f origin "!TAG!" >nul 2>&1
  echo Tag pushed: !TAG!
)

echo.
echo Press any key to exit...
pause >nul
