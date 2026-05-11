#!/usr/bin/env bash
#
# Compile and install the official Telegram Bot API server from source.
# Tested on Ubuntu 20.04/22.04/24.04 and Debian 11/12.
#
# Usage:
#   ./install_local_api.sh
#
# Result:
#   /usr/local/bin/telegram-bot-api  (binary)

set -euo pipefail

SRC_DIR="${SRC_DIR:-$HOME/src/telegram-bot-api}"

echo "==> Installing build dependencies"
sudo apt-get update
sudo apt-get install -y \
    make git zlib1g-dev libssl-dev gperf cmake g++ ca-certificates

echo "==> Cloning telegram-bot-api into $SRC_DIR"
if [[ -d "$SRC_DIR/.git" ]]; then
    git -C "$SRC_DIR" fetch --recurse-submodules
    git -C "$SRC_DIR" pull --recurse-submodules
    git -C "$SRC_DIR" submodule update --init --recursive
else
    mkdir -p "$(dirname "$SRC_DIR")"
    git clone --recursive https://github.com/tdlib/telegram-bot-api.git "$SRC_DIR"
fi

echo "==> Building (this may take 10-20 minutes on modest hardware)"
cd "$SRC_DIR"
rm -rf build
mkdir build
cd build
cmake -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX:PATH=/usr/local ..
cmake --build . -j"$(nproc)"

echo "==> Installing to /usr/local/bin (requires sudo)"
sudo cmake --build . --target install

echo ""
echo "✅ Installed:"
command -v telegram-bot-api || { echo "Binary not in PATH"; exit 1; }
telegram-bot-api --version || true

echo ""
echo "Next:"
echo "  1) Get api_id and api_hash from https://my.telegram.org/apps"
echo "  2) Run:  sudo ./setup_service.sh"
