#!/bin/bash
FF=/home/hamzazamzami/.cache/ms-playwright/ffmpeg-1011/ffmpeg-linux
for t in 22 15 5; do
  if "$FF" -y -ss "$t" -i /var/www/html/.verify/mahafeth-demo.webm -frames:v 1 -f image2 /var/www/html/.verify/frame.png >/tmp/frame.log 2>&1; then
    echo "FRAME_OK at ${t}s"; ls -la /var/www/html/.verify/frame.png; exit 0
  fi
done
echo "FRAME_FAIL"; tail -4 /tmp/frame.log
