import asyncio
import logging
from html import escape
from typing import Any

from telegram import (
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Update,
)
from telegram.constants import ChatAction, ChatMemberStatus, ParseMode
from telegram.error import BadRequest, Forbidden, RetryAfter, TelegramError, TimedOut
from telegram.ext import (
    Application,
    CallbackQueryHandler,
    CommandHandler,
    ContextTypes,
    MessageHandler,
    filters,
)

import config
import database as db
import downloader

logging.basicConfig(
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger("video-downloader-bot")
logging.getLogger("httpx").setLevel(logging.WARNING)


SUBSCRIBED_STATUSES = {
    ChatMemberStatus.MEMBER,
    ChatMemberStatus.ADMINISTRATOR,
    ChatMemberStatus.OWNER,
}


def is_admin(user_id: int) -> bool:
    return user_id in config.ADMIN_IDS


# ----------- Subscription gating -----------

async def check_subscription(
    context: ContextTypes.DEFAULT_TYPE, user_id: int
) -> list[dict]:
    """Return list of channels the user is NOT subscribed to."""
    channels = await db.list_channels()
    missing = []
    for ch in channels:
        try:
            member = await context.bot.get_chat_member(ch["chat_id"], user_id)
            if member.status not in SUBSCRIBED_STATUSES:
                missing.append(ch)
        except TelegramError as e:
            logger.warning(
                "Cannot check membership for %s on %s: %s",
                user_id, ch["chat_id"], e,
            )
            missing.append(ch)
    return missing


def subscription_keyboard(missing: list[dict]) -> InlineKeyboardMarkup:
    buttons = [
        [InlineKeyboardButton(f"📢 {ch['title']}", url=ch["invite_link"])]
        for ch in missing
    ]
    buttons.append(
        [InlineKeyboardButton("✅ تحققت من الاشتراك", callback_data="check_sub")]
    )
    return InlineKeyboardMarkup(buttons)


async def send_subscription_required(
    message_or_query, missing: list[dict], edit: bool = False
) -> None:
    text = (
        "🔒 <b>للاستخدام، اشترك في القنوات التالية أولاً:</b>\n\n"
        + "\n".join(f"• {escape(ch['title'])}" for ch in missing)
        + "\n\nثم اضغط <b>«تحققت من الاشتراك»</b>."
    )
    kb = subscription_keyboard(missing)
    if edit:
        await message_or_query.edit_message_text(
            text, parse_mode=ParseMode.HTML, reply_markup=kb
        )
    else:
        await message_or_query.reply_text(
            text, parse_mode=ParseMode.HTML, reply_markup=kb
        )


# ----------- Commands -----------

async def start_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    chat = update.effective_chat
    if user is None or chat is None:
        return

    is_new = await db.add_user(
        chat_id=chat.id,
        username=user.username,
        first_name=user.first_name,
        last_name=user.last_name,
        language=user.language_code,
    )

    text = (
        f"أهلاً {escape(user.first_name or '')} 👋\n\n"
        "أنا بوت تحميل الفيديوهات. أرسل لي أي رابط من:\n"
        "🎵 TikTok • 📸 Instagram • ▶️ YouTube\n"
        "🐦 X (Twitter) • 📘 Facebook\n\n"
        "وسأحمّله لك مباشرةً."
    )
    if is_new:
        text = "✨ <b>تم التسجيل بنجاح!</b>\n\n" + text
    await update.message.reply_text(text, parse_mode=ParseMode.HTML)


async def help_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None:
        return

    base = (
        "📖 <b>كيفية الاستخدام:</b>\n\n"
        "أرسل أي رابط فيديو من المواقع المدعومة وسأحمله لك.\n\n"
        "<b>الأوامر:</b>\n"
        "/start - بدء البوت\n"
        "/help - عرض هذه القائمة\n"
    )
    admin_extra = (
        "\n<b>أوامر الأدمن:</b>\n"
        "/stats - إحصائيات المستخدمين والتحميلات\n"
        "/broadcast - بث رسالة لكل المستخدمين (رد على رسالة أو نص)\n"
        "/addchannel <code>&lt;chat_id&gt; &lt;invite_link&gt; &lt;title...&gt;</code>\n"
        "/removechannel <code>&lt;chat_id&gt;</code>\n"
        "/listchannels - عرض قنوات الاشتراك\n"
    )
    text = base + (admin_extra if is_admin(user.id) else "")
    await update.message.reply_text(text, parse_mode=ParseMode.HTML)


async def stats_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or not is_admin(user.id):
        return

    stats = await db.get_stats()
    channels = await db.list_channels()
    text = (
        "📊 <b>إحصائيات البوت</b>\n\n"
        f"إجمالي المستخدمين: <b>{stats['total']}</b>\n"
        f"نشطين: <b>{stats['active']}</b>\n"
        f"حظروا البوت: <b>{stats['blocked']}</b>\n"
        f"إجمالي التحميلات: <b>{stats['downloads']}</b>\n"
        f"قنوات الاشتراك: <b>{len(channels)}</b>"
    )
    await update.message.reply_text(text, parse_mode=ParseMode.HTML)


async def add_channel_cmd(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    user = update.effective_user
    if user is None or not is_admin(user.id):
        return

    if not context.args or len(context.args) < 3:
        await update.message.reply_text(
            "الاستخدام:\n"
            "/addchannel <chat_id_or_username> <invite_link> <title...>\n\n"
            "مثال:\n"
            "/addchannel @mychannel https://t.me/mychannel قناتي الرئيسية"
        )
        return

    raw_id = context.args[0]
    invite_link = context.args[1]
    title = " ".join(context.args[2:])

    chat_id = raw_id if raw_id.startswith("@") else raw_id
    try:
        chat = await context.bot.get_chat(chat_id)
        canonical_id = str(chat.id)
        if not title:
            title = chat.title or chat.username or raw_id
    except TelegramError as e:
        await update.message.reply_text(
            f"⚠️ تعذر الوصول للقناة. تأكد أن البوت عضو فيها.\nالخطأ: {e}"
        )
        return

    await db.add_channel(canonical_id, title, invite_link)
    await update.message.reply_text(
        f"✅ تمت إضافة القناة: <b>{escape(title)}</b>\nID: <code>{canonical_id}</code>",
        parse_mode=ParseMode.HTML,
    )


async def remove_channel_cmd(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    user = update.effective_user
    if user is None or not is_admin(user.id):
        return

    if not context.args:
        await update.message.reply_text("الاستخدام: /removechannel <chat_id>")
        return

    raw_id = context.args[0]
    chat_id = raw_id
    try:
        chat = await context.bot.get_chat(raw_id)
        chat_id = str(chat.id)
    except TelegramError:
        pass

    removed = await db.remove_channel(chat_id)
    if removed:
        await update.message.reply_text("✅ تم حذف القناة.")
    else:
        await update.message.reply_text("⚠️ القناة غير موجودة في القائمة.")


async def list_channels_cmd(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    user = update.effective_user
    if user is None or not is_admin(user.id):
        return

    channels = await db.list_channels()
    if not channels:
        await update.message.reply_text(
            "لا توجد قنوات اشتراك. أضف قناة بـ /addchannel"
        )
        return

    lines = ["📋 <b>قنوات الاشتراك الإجباري:</b>\n"]
    for ch in channels:
        lines.append(
            f"• <b>{escape(ch['title'])}</b>\n"
            f"  ID: <code>{ch['chat_id']}</code>\n"
            f"  Link: {ch['invite_link']}"
        )
    await update.message.reply_text(
        "\n".join(lines), parse_mode=ParseMode.HTML, disable_web_page_preview=True
    )


# ----------- Broadcast -----------

async def _send_broadcast_one(
    context: ContextTypes.DEFAULT_TYPE,
    chat_id: int,
    source_message: Any,
    text_override: str | None,
) -> bool:
    try:
        if text_override is not None:
            await context.bot.send_message(
                chat_id=chat_id,
                text=text_override,
                parse_mode=ParseMode.HTML,
                disable_web_page_preview=False,
            )
        else:
            await context.bot.copy_message(
                chat_id=chat_id,
                from_chat_id=source_message.chat_id,
                message_id=source_message.message_id,
            )
        return True
    except Forbidden:
        await db.mark_blocked(chat_id)
        return False
    except RetryAfter as e:
        logger.warning("Rate limited, sleeping %s seconds", e.retry_after)
        await asyncio.sleep(e.retry_after + 1)
        return await _send_broadcast_one(
            context, chat_id, source_message, text_override
        )
    except TimedOut:
        await asyncio.sleep(1)
        return False
    except TelegramError as e:
        logger.warning("Failed to send to %s: %s", chat_id, e)
        return False


async def broadcast_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or not is_admin(user.id):
        return

    message = update.message
    if message is None:
        return

    reply_target = message.reply_to_message
    text_arg = " ".join(context.args) if context.args else ""

    if reply_target is None and not text_arg:
        await message.reply_text(
            "استخدام:\n"
            "• رد على رسالة بأمر /broadcast لبثها كما هي.\n"
            "• أو /broadcast نص الرسالة"
        )
        return

    user_ids = await db.get_active_user_ids()
    total = len(user_ids)
    if total == 0:
        await message.reply_text("لا يوجد مستخدمون مسجلون بعد.")
        return

    status_msg = await message.reply_text(
        f"⏳ جاري البث إلى {total} مستخدم..."
    )

    sent = 0
    failed = 0
    for i, chat_id in enumerate(user_ids, start=1):
        ok = await _send_broadcast_one(
            context,
            chat_id,
            source_message=reply_target,
            text_override=text_arg if reply_target is None else None,
        )
        if ok:
            sent += 1
        else:
            failed += 1
        await asyncio.sleep(config.BROADCAST_RATE_LIMIT)

        if i % config.BROADCAST_BATCH_SIZE == 0 or i == total:
            try:
                await status_msg.edit_text(
                    f"⏳ التقدم: {i}/{total}\n✅ تم: {sent} | ❌ فشل: {failed}"
                )
            except TelegramError:
                pass

    await status_msg.edit_text(
        "✅ <b>اكتمل البث</b>\n\n"
        f"المستهدفين: <b>{total}</b>\n"
        f"تم الإرسال: <b>{sent}</b>\n"
        f"فشل: <b>{failed}</b>",
        parse_mode=ParseMode.HTML,
    )


# ----------- Download flow -----------

async def process_download(
    update: Update, context: ContextTypes.DEFAULT_TYPE, url: str
) -> None:
    chat = update.effective_chat
    user = update.effective_user
    if chat is None or user is None:
        return

    if not downloader.is_supported_url(url):
        await update.effective_message.reply_text(
            "⚠️ هذا الرابط غير مدعوم.\n\n"
            "المواقع المدعومة: TikTok, Instagram, YouTube, X/Twitter, Facebook."
        )
        return

    status = await update.effective_message.reply_text("⏳ جاري التحميل...")
    await context.bot.send_chat_action(chat.id, ChatAction.UPLOAD_VIDEO)

    try:
        result = await downloader.download(
            url,
            max_size_bytes=config.MAX_FILE_SIZE_BYTES,
            timeout=config.DOWNLOAD_TIMEOUT_SECONDS,
        )
    except downloader.DownloadError as e:
        await status.edit_text(f"❌ فشل التحميل: {e}")
        return

    try:
        caption = f"🎬 <b>{escape(result.title)}</b>"
        if result.uploader:
            caption += f"\n👤 {escape(result.uploader)}"

        video_arg: Any
        if config.USE_LOCAL_API:
            video_arg = str(result.file_path.absolute())
            await context.bot.send_video(
                chat_id=chat.id,
                video=video_arg,
                caption=caption,
                parse_mode=ParseMode.HTML,
                supports_streaming=True,
                duration=result.duration,
                read_timeout=600,
                write_timeout=600,
            )
        else:
            with result.file_path.open("rb") as f:
                await context.bot.send_video(
                    chat_id=chat.id,
                    video=f,
                    caption=caption,
                    parse_mode=ParseMode.HTML,
                    supports_streaming=True,
                    duration=result.duration,
                    read_timeout=300,
                    write_timeout=300,
                )

        await status.delete()
        await db.increment_download_count(chat.id)
    except BadRequest as e:
        if "too large" in str(e).lower():
            await status.edit_text(
                f"❌ الملف أكبر من الحد المسموح ({config.MAX_FILE_SIZE_MB}MB)."
            )
        else:
            await status.edit_text(f"❌ خطأ في الإرسال: {e}")
    except TelegramError as e:
        await status.edit_text(f"❌ فشل الإرسال: {e}")
    finally:
        downloader.cleanup(result)


async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    chat = update.effective_chat
    message = update.effective_message
    if user is None or chat is None or message is None:
        return
    if chat.type != "private":
        return

    await db.add_user(
        chat_id=chat.id,
        username=user.username,
        first_name=user.first_name,
        last_name=user.last_name,
        language=user.language_code,
    )

    url = downloader.extract_url(message.text or "")
    if not url:
        await message.reply_text(
            "أرسل لي رابط فيديو من TikTok / Instagram / YouTube / X / Facebook."
        )
        return

    if not is_admin(user.id):
        missing = await check_subscription(context, user.id)
        if missing:
            context.user_data["pending_url"] = url
            await send_subscription_required(message, missing)
            return

    await process_download(update, context, url)


async def check_sub_callback(
    update: Update, context: ContextTypes.DEFAULT_TYPE
) -> None:
    query = update.callback_query
    user = update.effective_user
    if query is None or user is None:
        return

    await query.answer("جاري التحقق...")

    missing = await check_subscription(context, user.id)
    if missing:
        await send_subscription_required(query, missing, edit=True)
        return

    pending_url = context.user_data.pop("pending_url", None)
    if pending_url:
        await query.edit_message_text("✅ تم التحقق! جاري التحميل...")
        await process_download(update, context, pending_url)
    else:
        await query.edit_message_text(
            "✅ تم التحقق من اشتراكك!\nأرسل الرابط الآن وسأحمّله لك."
        )


# ----------- Bootstrap -----------

async def on_error(update: object, context: ContextTypes.DEFAULT_TYPE) -> None:
    logger.exception("Update error: %s", context.error)


async def post_init(application: Application) -> None:
    await db.init_db()
    logger.info("Database initialized at %s", db.DB_PATH)


def main() -> None:
    builder = (
        Application.builder()
        .token(config.BOT_TOKEN)
        .post_init(post_init)
    )
    if config.USE_LOCAL_API:
        builder = (
            builder
            .base_url(config.LOCAL_API_BASE_URL)
            .base_file_url(config.LOCAL_API_FILE_URL)
            .local_mode(True)
        )
        logger.info("Using LOCAL Bot API server at %s", config.LOCAL_API_BASE_URL)
    application = builder.build()

    application.add_handler(CommandHandler("start", start_cmd))
    application.add_handler(CommandHandler("help", help_cmd))
    application.add_handler(CommandHandler("stats", stats_cmd))
    application.add_handler(CommandHandler("broadcast", broadcast_cmd))
    application.add_handler(CommandHandler("addchannel", add_channel_cmd))
    application.add_handler(CommandHandler("removechannel", remove_channel_cmd))
    application.add_handler(CommandHandler("listchannels", list_channels_cmd))
    application.add_handler(
        CallbackQueryHandler(check_sub_callback, pattern=r"^check_sub$")
    )
    application.add_handler(
        MessageHandler(
            filters.ChatType.PRIVATE & filters.TEXT & ~filters.COMMAND,
            handle_message,
        )
    )
    application.add_error_handler(on_error)

    logger.info("Bot is starting...")
    application.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
