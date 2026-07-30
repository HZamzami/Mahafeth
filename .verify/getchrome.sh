#!/bin/bash
# Kill the throttled Playwright download and pull Chrome-for-Testing from
# Google's CDN instead (usually not throttled).
pkill -f "playwright install" 2>/dev/null
pkill -f "playwright-download" 2>/dev/null
sleep 1

mkdir -p /tmp/cft && cd /tmp/cft || exit 1

VER=$(curl -s https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions.json \
  | node -e 'let s="";process.stdin.on("data",d=>s+=d);process.stdin.on("end",()=>{console.log(JSON.parse(s).channels.Stable.version)})')
echo "STABLE_VERSION=$VER"

URL="https://storage.googleapis.com/chrome-for-testing-public/$VER/linux64/chrome-headless-shell-linux64.zip"
echo "URL=$URL"

# time-boxed download
curl -sL -o chs.zip "$URL" &
CPID=$!
sleep 8
if [ -f chs.zip ]; then echo "after_8s_MB=$(( $(stat -c%s chs.zip) / 1024 / 1024 ))"; fi
wait $CPID
echo "download_exit=$?"
echo "final_MB=$(( $(stat -c%s chs.zip) / 1024 / 1024 ))"

unzip -q -o chs.zip
BIN=$(find /tmp/cft -name chrome-headless-shell -type f | head -1)
chmod +x "$BIN" 2>/dev/null
echo "CHS_BIN=$BIN"
