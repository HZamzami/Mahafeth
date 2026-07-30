#!/bin/bash
f=$(ls /tmp/playwright-download-*/*.zip 2>/dev/null | head -1)
if [ -z "$f" ]; then
  echo "no zip; checking if extracted:"
  ls ~/.cache/ms-playwright/ 2>/dev/null
  exit 0
fi
a=$(stat -c%s "$f")
sleep 6
b=$(stat -c%s "$f")
rate=$(( (b - a) / 6 ))
echo "zip: $f"
echo "size_MB: $(( b / 1024 / 1024 ))"
echo "rate_KB_per_s: $(( rate / 1024 ))"
