#!/usr/bin/env sh
# Installs static FFmpeg + FFprobe into api/bin for lesson video/audio processing (R2 video driver).
# Laravel Cloud build commands run without root, so no apt-get: this downloads a static Linux build instead.
# Usage (from the api/ folder, e.g. as a Laravel Cloud build command): sh scripts/install-ffmpeg.sh
# Safe to run on every build: it skips the download when bin/ffmpeg already works.
set -eu

cd "$(dirname "$0")/.."
mkdir -p bin

if [ -x bin/ffmpeg ] && [ -x bin/ffprobe ] && bin/ffmpeg -version >/dev/null 2>&1; then
  echo "FFmpeg already installed: $(bin/ffmpeg -version | head -n 1)"
  exit 0
fi

case "$(uname -m)" in
  x86_64 | amd64) arch=amd64 ;;
  aarch64 | arm64) arch=arm64 ;;
  *)
    echo "install-ffmpeg: unsupported architecture $(uname -m)" >&2
    exit 1
    ;;
esac

url="https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-${arch}-static.tar.xz"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "Downloading FFmpeg (${arch})…"
curl -fsSL --retry 3 "$url" -o "$tmp/ffmpeg.tar.xz"
tar -xJf "$tmp/ffmpeg.tar.xz" -C "$tmp"
cp "$tmp"/ffmpeg-*-static/ffmpeg "$tmp"/ffmpeg-*-static/ffprobe bin/
chmod +x bin/ffmpeg bin/ffprobe

echo "Installed: $(bin/ffmpeg -version | head -n 1)"
