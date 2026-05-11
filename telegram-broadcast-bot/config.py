import os
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).parent / ".env")

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

MAX_FILE_SIZE_MB = int(os.getenv("MAX_FILE_SIZE_MB", "48"))
MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024

DOWNLOAD_TIMEOUT_SECONDS = int(os.getenv("DOWNLOAD_TIMEOUT_SECONDS", "180"))
