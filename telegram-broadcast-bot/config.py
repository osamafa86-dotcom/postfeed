import os
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).parent / ".env")


def _as_bool(value: str | None, default: bool = False) -> bool:
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


BOT_TOKEN = os.getenv("BOT_TOKEN", "").strip()
if not BOT_TOKEN:
    raise RuntimeError("BOT_TOKEN is missing. Set it in .env")

_admin_raw = os.getenv("ADMIN_IDS", "").strip()
ADMIN_IDS: set[int] = {
    int(x) for x in _admin_raw.split(",") if x.strip().lstrip("-").isdigit()
}
if not ADMIN_IDS:
    raise RuntimeError("ADMIN_IDS is missing. Set it in .env (comma-separated)")

BROADCAST_RATE_LIMIT = float(os.getenv("BROADCAST_RATE_LIMIT", "0.05"))
BROADCAST_BATCH_SIZE = int(os.getenv("BROADCAST_BATCH_SIZE", "25"))

MAX_FILE_SIZE_MB = int(os.getenv("MAX_FILE_SIZE_MB", "150"))
MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024

DOWNLOAD_TIMEOUT_SECONDS = int(os.getenv("DOWNLOAD_TIMEOUT_SECONDS", "300"))

USE_LOCAL_API = _as_bool(os.getenv("USE_LOCAL_API"), default=False)
LOCAL_API_BASE_URL = os.getenv(
    "LOCAL_API_BASE_URL", "http://localhost:8081/bot"
).rstrip("/")
LOCAL_API_FILE_URL = os.getenv(
    "LOCAL_API_FILE_URL", "http://localhost:8081/file/bot"
).rstrip("/")

if USE_LOCAL_API and MAX_FILE_SIZE_MB > 2000:
    raise RuntimeError("MAX_FILE_SIZE_MB cannot exceed 2000 (Telegram limit).")
if not USE_LOCAL_API and MAX_FILE_SIZE_MB > 50:
    raise RuntimeError(
        "MAX_FILE_SIZE_MB > 50 requires USE_LOCAL_API=true "
        "(cloud Bot API limit is 50 MB)."
    )
