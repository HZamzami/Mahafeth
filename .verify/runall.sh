#!/bin/bash
# Waits for the Playwright chromium binary, then runs the marquee geometry
# measurement and the 30s demo recording. Prints results to stdout.
EXE=""
for i in $(seq 1 400); do
  EXE=$(ls ~/.cache/ms-playwright/chromium-*/chrome-linux/chrome 2>/dev/null | head -1)
  if [ -n "$EXE" ]; then break; fi
  sleep 3
done
if [ -z "$EXE" ]; then
  echo "CHROMIUM_NOT_READY after wait"
  ls ~/.cache/ms-playwright/ 2>/dev/null
  exit 1
fi
echo "CHROMIUM_EXE=$EXE"
export PW_EXE="$EXE"
cd /tmp/mq || exit 1

echo "===== MARQUEE MEASURE ====="
node measure.js 2>&1 || echo "measure.js FAILED"

echo "===== DEMO RECORD ====="
node record.js 2>&1 || echo "record.js FAILED"

echo "===== ARTIFACTS ====="
ls -la /tmp/mq/video/ 2>/dev/null
echo "DONE"
