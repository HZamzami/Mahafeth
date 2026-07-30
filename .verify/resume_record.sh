#!/bin/bash
# Resiliently resume the chrome-headless-shell download until the zip validates,
# then record the 30s demo flow.
set -u
cd /tmp/cft || exit 1
VER=151.0.7922.34
URL="https://storage.googleapis.com/chrome-for-testing-public/$VER/linux64/chrome-headless-shell-linux64.zip"

ok=0
for i in $(seq 1 80); do
  curl -L -C - --retry 4 --retry-delay 2 --connect-timeout 15 -s -o chs.zip "$URL"
  if unzip -t chs.zip >/dev/null 2>&1; then
    ok=1; echo "ZIP_OK attempt=$i MB=$(( $(stat -c%s chs.zip) / 1024 / 1024 ))"; break
  fi
  echo "attempt $i incomplete: MB=$(( $(stat -c%s chs.zip) / 1024 / 1024 )), resuming"
  sleep 2
done
[ "$ok" != "1" ] && { echo "DOWNLOAD_INCOMPLETE"; exit 1; }

unzip -q -o chs.zip || { echo "UNZIP_FAILED"; exit 1; }
BIN=$(find /tmp/cft -name chrome-headless-shell -type f | head -1)
chmod +x "$BIN"
echo "CHS_BIN=$BIN"

echo "===== RECORDING 30s DEMO ====="
export PW_EXE="$BIN"
cd /tmp/mq || exit 1
node record.js 2>&1
echo "===== VIDEO FILES ====="
ls -la /tmp/mq/video/ 2>/dev/null
echo "ALL_DONE"
