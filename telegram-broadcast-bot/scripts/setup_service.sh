#!/usr/bin/env bash
#
# Create a dedicated system user, working directories, env file, and
# install the systemd unit for telegram-bot-api.
#
# Run AFTER install_local_api.sh and AFTER getting api_id / api_hash
# from https://my.telegram.org/apps
#
# Usage:
#   sudo ./setup_service.sh

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
UNIT_SRC="$SCRIPT_DIR/telegram-bot-api.service"
ENV_FILE="/etc/telegram-bot-api.env"

if ! command -v telegram-bot-api >/dev/null 2>&1; then
    echo "telegram-bot-api binary not found. Run install_local_api.sh first." >&2
    exit 1
fi

echo "==> Creating system user 'telegram-bot-api'"
if ! id telegram-bot-api >/dev/null 2>&1; then
    useradd --system --home /var/lib/telegram-bot-api \
            --shell /usr/sbin/nologin telegram-bot-api
fi

echo "==> Creating data and log directories"
install -d -o telegram-bot-api -g telegram-bot-api -m 0750 \
    /var/lib/telegram-bot-api \
    /var/lib/telegram-bot-api/tmp \
    /var/log/telegram-bot-api

echo "==> Configuring environment file at $ENV_FILE"
if [[ ! -f "$ENV_FILE" ]]; then
    read -r -p "TELEGRAM_API_ID: " api_id
    read -r -p "TELEGRAM_API_HASH: " api_hash
    cat > "$ENV_FILE" <<EOF
TELEGRAM_API_ID=$api_id
TELEGRAM_API_HASH=$api_hash
EOF
    chmod 600 "$ENV_FILE"
    chown root:root "$ENV_FILE"
    echo "   Wrote $ENV_FILE (mode 600)"
else
    echo "   $ENV_FILE already exists; leaving as-is."
fi

echo "==> Installing systemd unit"
install -m 0644 "$UNIT_SRC" /etc/systemd/system/telegram-bot-api.service
systemctl daemon-reload
systemctl enable telegram-bot-api.service

echo "==> Starting service"
systemctl restart telegram-bot-api.service
sleep 2
systemctl --no-pager --full status telegram-bot-api.service | head -20 || true

echo ""
echo "✅ Local Bot API server is running on http://127.0.0.1:8081"
echo ""
echo "Next:"
echo "  1) python scripts/migrate_to_local.py   # one-time logOut from cloud"
echo "  2) Set USE_LOCAL_API=true in .env"
echo "  3) Restart your bot."
