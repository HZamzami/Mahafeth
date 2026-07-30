#!/bin/bash
cp /tmp/mq/video/*.webm /var/www/html/.verify/mahafeth-demo.webm && echo "COPIED_WEBM"
FF=/home/hamzazamzami/.cache/ms-playwright/ffmpeg-1011/ffmpeg-linux
if "$FF" -y -i /var/www/html/.verify/mahafeth-demo.webm -c:v libx264 -pix_fmt yuv420p -movflags +faststart /var/www/html/.verify/mahafeth-demo.mp4 >/tmp/ff.log 2>&1; then
  echo "MP4_OK"
else
  echo "MP4_LIBX264_FAILED"
  tail -3 /tmp/ff.log
fi
ls -la /var/www/html/.verify/mahafeth-demo.* 2>/dev/null
