#!/bin/bash
# Kill every downloader, then test one clean stream's speed.
pkill -9 -f "playwright" 2>/dev/null
pkill -9 -f "chrome-for-testing" 2>/dev/null
pkill -9 -f "curl -sL -o chs.zip" 2>/dev/null
pkill -9 -f "npm exec" 2>/dev/null
sleep 2
echo "remaining net procs:"
ps -eo pid,args | grep -iE "curl|wget|playwright|npm" | grep -v grep | head

echo "--- clean 6s speed test (10MB from Google CDN) ---"
cd /tmp && rm -f speedtest.bin
timeout 6 curl -s -o speedtest.bin "https://storage.googleapis.com/chrome-for-testing-public/LATEST_RELEASE_STABLE" 2>/dev/null
# tiny file; do a real one:
rm -f speedtest.bin
timeout 8 curl -s -o speedtest.bin "https://speed.cloudflare.com/__down?bytes=20000000" 2>/dev/null
if [ -f speedtest.bin ]; then
  echo "downloaded_KB=$(( $(stat -c%s speedtest.bin) / 1024 )) in ~8s"
else
  echo "no bytes"
fi
