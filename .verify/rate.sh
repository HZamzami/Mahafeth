#!/bin/bash
f=/tmp/cft/chs.zip
[ -f "$f" ] || { echo "no zip"; exit 0; }
a=$(stat -c%s "$f")
sleep 6
b=$(stat -c%s "$f")
echo "now_MB=$(( b / 1024 / 1024 ))"
echo "rate_KB_s=$(( (b - a) / 6 / 1024 ))"
