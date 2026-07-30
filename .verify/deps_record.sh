#!/bin/bash
# Install chrome-headless-shell's missing shared libs (Debian 13 / trixie uses
# t64 variants for the sound/atk/atspi libs), then record the demo.
sudo apt-get update -qq
sudo apt-get install -y --no-install-recommends \
  libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libxkbcommon0 libgbm1 \
  libnspr4 libnss3 libasound2t64 libatk1.0-0t64 libatk-bridge2.0-0t64 libatspi2.0-0t64 \
  2>&1 | tail -3

echo "=== remaining missing libs (blank = good) ==="
ldd /tmp/cft/chrome-headless-shell-linux64/chrome-headless-shell 2>/dev/null | grep "not found" || echo "ALL_LIBS_PRESENT"

echo "===== RECORDING 30s DEMO ====="
export PW_EXE=/tmp/cft/chrome-headless-shell-linux64/chrome-headless-shell
cd /tmp/mq || exit 1
node record.js 2>&1

echo "===== VIDEO FILES ====="
ls -la /tmp/mq/video/ 2>/dev/null
echo "ALL_DONE"
