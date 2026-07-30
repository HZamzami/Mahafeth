#!/bin/bash
# One clean job: pull chrome-headless-shell from Google CDN, then record the
# 30s demo flow with Playwright pointed at it.
set -u
mkdir -p /tmp/cft && cd /tmp/cft || exit 1

VER=$(curl -s https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions.json \
  | node -e 'let s="";process.stdin.on("data",d=>s+=d);process.stdin.on("end",()=>{try{console.log(JSON.parse(s).channels.Stable.version)}catch(e){console.log("")}})')
echo "VER=$VER"
[ -z "$VER" ] && { echo "NO_VERSION"; exit 1; }

URL="https://storage.googleapis.com/chrome-for-testing-public/$VER/linux64/chrome-headless-shell-linux64.zip"
echo "Downloading $URL"
curl -L -C - --retry 5 --retry-delay 3 -s -o chs.zip "$URL"
echo "downloaded_MB=$(( $(stat -c%s chs.zip) / 1024 / 1024 ))"

unzip -q -o chs.zip || { echo "UNZIP_FAILED"; exit 1; }
BIN=$(find /tmp/cft -name chrome-headless-shell -type f | head -1)
chmod +x "$BIN"
echo "CHS_BIN=$BIN"
[ -z "$BIN" ] && { echo "NO_BINARY"; exit 1; }

echo "===== RECORDING 30s DEMO ====="
export PW_EXE="$BIN"
cd /tmp/mq || exit 1
node record.js 2>&1
echo "===== VIDEO FILES ====="
ls -la /tmp/mq/video/ 2>/dev/null
echo "ALL_DONE"
