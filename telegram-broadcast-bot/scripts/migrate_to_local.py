"""One-time migration: log the bot out of the cloud Bot API so it can be
controlled by your local telegram-bot-api server.

Usage:
    python scripts/migrate_to_local.py

After running this, set USE_LOCAL_API=true in .env and start your bot.
You can return to the cloud API later by stopping the local server,
setting USE_LOCAL_API=false, and the bot will reconnect automatically
(no explicit log-in required).
"""
from __future__ import annotations

import asyncio
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from telegram import Bot  # noqa: E402

import config  # noqa: E402


async def main() -> None:
    if config.USE_LOCAL_API:
        print(
            "USE_LOCAL_API is already true in .env.\n"
            "Set it to false temporarily if you need to log out from the cloud API."
        )
        return

    bot = Bot(token=config.BOT_TOKEN)
    print("Logging bot out from the cloud Bot API...")
    try:
        await bot.log_out()
    except Exception as e:
        print(f"log_out call returned: {e}")
        print("This may be fine if the bot is already migrated.")
        return

    print("✅ Done. Now:")
    print("   1) Make sure the local server is running (systemctl status telegram-bot-api)")
    print("   2) Set USE_LOCAL_API=true in .env")
    print("   3) Restart your bot")


if __name__ == "__main__":
    asyncio.run(main())
