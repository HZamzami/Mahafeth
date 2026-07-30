#!/bin/bash
cd /tmp/mq || exit 1
# Get Playwright's small ffmpeg (needed to encode the webm).
npm i playwright >/dev/null 2>&1 && echo "playwright pkg installed"
npx --yes playwright install ffmpeg 2>&1 | tail -4
echo "--- ffmpeg present? ---"
find ~/.cache/ms-playwright -name "ffmpeg-linux" 2>/dev/null || echo "NO_FFMPEG"

echo "===== RECORDING 30s DEMO ====="
export PW_EXE=/tmp/cft/chrome-headless-shell-linux64/chrome-headless-shell
node record.js 2>&1

echo "===== VIDEO FILES ====="
ls -la /tmp/mq/video/ 2>/dev/null
echo "ALL_DONE"
