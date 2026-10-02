#!/bin/sh
# Starts the Vite dev server inside Docker (used by the `vite` compose service).
set -e
cd /var/www/html

# A `hot` file left behind by a stopped dev server makes Laravel load every asset
# from a Vite server that is not running yet, which renders a blank page.
rm -f public/hot

# `npm install` contacts the registry on every run, even when nothing changed.
# Only reinstall when package-lock.json differs from the last successful install.
marker=node_modules/.stocksense-lock-hash
current=$(sha1sum package-lock.json | cut -d' ' -f1)
if [ "$(cat "$marker" 2>/dev/null)" != "$current" ]; then
  echo "[vite] dependencies changed - running npm install"
  npm install --no-audit --no-fund
  echo "$current" > "$marker"
fi

exec npm run dev
