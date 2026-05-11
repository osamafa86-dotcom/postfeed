import aiosqlite
from datetime import datetime
from pathlib import Path

DB_PATH = Path(__file__).parent / "bot.db"


async def init_db() -> None:
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute("""
            CREATE TABLE IF NOT EXISTS users (
                chat_id      INTEGER PRIMARY KEY,
                username     TEXT,
                first_name   TEXT,
                last_name    TEXT,
                language     TEXT,
                joined_at    TEXT NOT NULL,
                is_blocked   INTEGER NOT NULL DEFAULT 0,
                downloads    INTEGER NOT NULL DEFAULT 0
            )
        """)
        await db.execute("""
            CREATE TABLE IF NOT EXISTS channels (
                chat_id      TEXT PRIMARY KEY,
                title        TEXT NOT NULL,
                invite_link  TEXT NOT NULL,
                added_at     TEXT NOT NULL
            )
        """)
        await db.commit()


async def add_user(
    chat_id: int,
    username: str | None,
    first_name: str | None,
    last_name: str | None,
    language: str | None,
) -> bool:
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute(
            "SELECT 1 FROM users WHERE chat_id = ?", (chat_id,)
        )
        exists = await cursor.fetchone() is not None

        if exists:
            await db.execute(
                """UPDATE users
                   SET username = ?, first_name = ?, last_name = ?,
                       language = ?, is_blocked = 0
                   WHERE chat_id = ?""",
                (username, first_name, last_name, language, chat_id),
            )
        else:
            await db.execute(
                """INSERT INTO users
                   (chat_id, username, first_name, last_name, language, joined_at)
                   VALUES (?, ?, ?, ?, ?, ?)""",
                (
                    chat_id,
                    username,
                    first_name,
                    last_name,
                    language,
                    datetime.utcnow().isoformat(),
                ),
            )
        await db.commit()
        return not exists


async def get_active_user_ids() -> list[int]:
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute(
            "SELECT chat_id FROM users WHERE is_blocked = 0"
        )
        rows = await cursor.fetchall()
        return [row[0] for row in rows]


async def mark_blocked(chat_id: int) -> None:
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute(
            "UPDATE users SET is_blocked = 1 WHERE chat_id = ?", (chat_id,)
        )
        await db.commit()


async def increment_download_count(chat_id: int) -> None:
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute(
            "UPDATE users SET downloads = downloads + 1 WHERE chat_id = ?",
            (chat_id,),
        )
        await db.commit()


async def get_stats() -> dict[str, int]:
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute(
            """SELECT
                 COUNT(*) AS total,
                 SUM(CASE WHEN is_blocked = 0 THEN 1 ELSE 0 END) AS active,
                 SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) AS blocked,
                 COALESCE(SUM(downloads), 0) AS downloads
               FROM users"""
        )
        row = await cursor.fetchone()
        return {
            "total": row[0] or 0,
            "active": row[1] or 0,
            "blocked": row[2] or 0,
            "downloads": row[3] or 0,
        }


async def add_channel(chat_id: str, title: str, invite_link: str) -> None:
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute(
            """INSERT INTO channels (chat_id, title, invite_link, added_at)
               VALUES (?, ?, ?, ?)
               ON CONFLICT(chat_id) DO UPDATE SET
                 title = excluded.title,
                 invite_link = excluded.invite_link""",
            (chat_id, title, invite_link, datetime.utcnow().isoformat()),
        )
        await db.commit()


async def remove_channel(chat_id: str) -> bool:
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute(
            "DELETE FROM channels WHERE chat_id = ?", (chat_id,)
        )
        await db.commit()
        return cursor.rowcount > 0


async def list_channels() -> list[dict]:
    async with aiosqlite.connect(DB_PATH) as db:
        cursor = await db.execute(
            "SELECT chat_id, title, invite_link FROM channels ORDER BY added_at"
        )
        rows = await cursor.fetchall()
        return [
            {"chat_id": r[0], "title": r[1], "invite_link": r[2]}
            for r in rows
        ]
