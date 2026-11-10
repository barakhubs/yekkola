#!/usr/bin/env sh
# Installs static FFmpeg + FFprobe into api/bin for lesson video/audio processing (R2 video driver).
# Laravel Cloud build commands run without root, so no apt-get: this downloads static Linux builds instead.
# Usage (from the api/ folder, e.g. as a Laravel Cloud build command): sh scripts/install-ffmpeg.sh
#
# The machine that builds may not be the kind that runs the app (e.g. x86_64 build, arm64 instances), so both
# architectures are downloaded, and bin/ffmpeg + bin/ffprobe are small wrappers that pick the right one at run
# time. Safe to run on every build: each architecture is skipped once present.
set -eu

cd "$(dirname "$0")/.."
mkdir -p bin

fetch() {
  arch="$1"
  dir="bin/$arch"
  if [ -s "$dir/ffmpeg" ] && [ -s "$dir/ffprobe" ]; then
    echo "FFmpeg ($arch) already present"
    return 0
  fi
  url="https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-${arch}-static.tar.xz"
  tmp="$(mktemp -d)"
  echo "Downloading FFmpeg ($arch)…"
  curl -fsSL --retry 3 "$url" -o "$tmp/ffmpeg.tar.xz"
  tar -xJf "$tmp/ffmpeg.tar.xz" -C "$tmp"
  mkdir -p "$dir"
  cp "$tmp"/ffmpeg-*-static/ffmpeg "$tmp"/ffmpeg-*-static/ffprobe "$dir"/
  chmod +x "$dir/ffmpeg" "$dir/ffprobe"
  rm -rf "$tmp"
}

fetch amd64
fetch arm64

# Wrappers: choose the binary for the machine actually running.
for tool in ffmpeg ffprobe; do
  cat > "bin/$tool" <<EOF
#!/usr/bin/env sh
case "\$(uname -m)" in
  aarch64 | arm64) arch=arm64 ;;
  *) arch=amd64 ;;
esac
exec "\$(dirname "\$0")/\$arch/$tool" "\$@"
EOF
  chmod +x "bin/$tool"
done

echo "Installed FFmpeg for amd64 and arm64 (bin/ffmpeg picks one at run time)."
if bin/ffmpeg -version >/dev/null 2>&1; then
  echo "Here: $(bin/ffmpeg -version | head -n 1)"
fi
