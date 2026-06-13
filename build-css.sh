#!/usr/bin/env sh
set -eu

"$(dirname "$0")/tools/tailwindcss" -i "$(dirname "$0")/resources/css/app.css" -o "$(dirname "$0")/public/assets/css/app.css" --config "$(dirname "$0")/tailwind.config.js" --minify
