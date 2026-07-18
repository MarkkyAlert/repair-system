#!/usr/bin/env sh
# Rebuild the served stylesheet from source: resources/css/app.css -> public/assets/css/app.css (minified).
#
# You do NOT need Node/npm. This script downloads the pinned Tailwind CSS standalone binary (a single
# self-contained executable) into tools/ the first time, then reuses it. The compiled CSS already ships with
# the template — you only need this if you edit resources/css/app.css and want to regenerate the served file.
#
# Pinned so a rebuild always matches the version the template was built with. Bump BOTH this and build-css.bat.
set -eu

TAILWIND_VERSION="3.4.17"
DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BIN="$DIR/tools/tailwindcss"

if [ ! -x "$BIN" ]; then
    # Map this machine to the matching standalone build.
    os="$(uname -s)"
    arch="$(uname -m)"
    case "$os" in
        Linux)  platform="linux" ;;
        Darwin) platform="macos" ;;
        *) echo "Unsupported OS '$os'. Download tools/tailwindcss manually from https://github.com/tailwindlabs/tailwindcss/releases/tag/v$TAILWIND_VERSION" >&2; exit 1 ;;
    esac
    case "$arch" in
        x86_64|amd64) cpu="x64" ;;
        arm64|aarch64) cpu="arm64" ;;
        *) echo "Unsupported CPU '$arch'. Download tools/tailwindcss manually from https://github.com/tailwindlabs/tailwindcss/releases/tag/v$TAILWIND_VERSION" >&2; exit 1 ;;
    esac
    asset="tailwindcss-${platform}-${cpu}"
    url="https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/${asset}"

    echo "Downloading Tailwind CSS v$TAILWIND_VERSION ($asset) ..."
    mkdir -p "$DIR/tools"
    if command -v curl >/dev/null 2>&1; then
        curl -fL --retry 3 -o "$BIN" "$url"
    elif command -v wget >/dev/null 2>&1; then
        wget -O "$BIN" "$url"
    else
        echo "Need curl or wget to download the Tailwind binary. Install one, or download it manually to tools/tailwindcss:" >&2
        echo "  $url" >&2
        exit 1
    fi
    chmod +x "$BIN"
fi

"$BIN" -i "$DIR/resources/css/app.css" -o "$DIR/public/assets/css/app.css" --config "$DIR/tailwind.config.js" --minify
echo "Built public/assets/css/app.css"
