@echo off
REM Rebuild the served stylesheet from source: resources\css\app.css -> public\assets\css\app.css (minified).
REM
REM You do NOT need Node/npm. This downloads the pinned Tailwind CSS standalone binary into tools\ the first
REM time, then reuses it. The compiled CSS already ships with the template — you only need this if you edit
REM resources\css\app.css and want to regenerate the served file.
REM
REM Pinned so a rebuild always matches the version the template was built with. Bump BOTH this and build-css.sh.
setlocal
set TAILWIND_VERSION=3.4.17
set TAILWIND=%~dp0tools\tailwindcss.exe

if not exist "%TAILWIND%" (
    echo Downloading Tailwind CSS v%TAILWIND_VERSION% ...
    if not exist "%~dp0tools" mkdir "%~dp0tools"
    powershell -NoProfile -Command "Invoke-WebRequest -Uri 'https://github.com/tailwindlabs/tailwindcss/releases/download/v%TAILWIND_VERSION%/tailwindcss-windows-x64.exe' -OutFile '%TAILWIND%'"
    if not exist "%TAILWIND%" (
        echo Failed to download Tailwind. Download it manually to tools\tailwindcss.exe from:
        echo   https://github.com/tailwindlabs/tailwindcss/releases/tag/v%TAILWIND_VERSION%
        exit /b 1
    )
)

"%TAILWIND%" -i "%~dp0resources\css\app.css" -o "%~dp0public\assets\css\app.css" --config "%~dp0tailwind.config.js" --minify
echo Built public\assets\css\app.css
