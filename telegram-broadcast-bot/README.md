# بوت تحميل فيديوهات تلغرام مع اشتراك إجباري

بوت تلغرام يقوم بتحميل الفيديوهات من TikTok و Instagram و YouTube و X/Twitter و Facebook، مع اشتراط الاشتراك بقنواتك قبل الاستخدام (نظام رعايات). يدعم أيضاً البث الجماعي للرسائل (Broadcast) من قبل الأدمن.

## المميزات

- 🎬 تحميل فيديوهات من TikTok / Instagram / YouTube / Shorts / X / Facebook
- 🔒 اشتراك إجباري بقناة أو أكثر قبل التحميل (Force Subscribe)
- 👨‍💼 إدارة القنوات بأوامر بسيطة (إضافة / حذف / عرض)
- 📢 بث رسائل لكل المستخدمين المسجلين (نص، صور، فيديو، أي نوع)
- 📊 إحصائيات مفصلة للمستخدمين والتحميلات
- 🛡 معالجة آمنة للمستخدمين الذين يحظرون البوت
- ⚡ كتب باستخدام `python-telegram-bot` v21 (async) و `yt-dlp`

## المتطلبات

- Python 3.10+
- `ffmpeg` (مطلوب لـ yt-dlp لدمج الفيديو والصوت)
- توكن بوت من [@BotFather](https://t.me/BotFather)
- معرف المستخدم الرقمي للأدمن من [@userinfobot](https://t.me/userinfobot)

## التثبيت

```bash
cd telegram-broadcast-bot

# (موصى به) إنشاء بيئة افتراضية
python3 -m venv venv
source venv/bin/activate

# تثبيت المكتبات
pip install -r requirements.txt

# تثبيت ffmpeg
# Ubuntu/Debian:
sudo apt-get install -y ffmpeg
# macOS:
brew install ffmpeg
```

## الإعداد

1. انسخ `.env.example` إلى `.env`:

```bash
cp .env.example .env
```

2. عدّل القيم في `.env`:

```env
BOT_TOKEN=توكن_البوت_من_BotFather
ADMIN_IDS=معرفك_الرقمي,معرف_أدمن_آخر
```

3. شغّل البوت:

```bash
python bot.py
```

## إعداد قنوات الاشتراك الإجباري

### الخطوة 1: أضف البوت كأدمن في القناة
لكل قناة تريد إجبار الاشتراك بها:
1. افتح القناة → الإعدادات → المسؤولون
2. أضف البوت كمسؤول (تكفي صلاحية القراءة فقط، لكن يُفضل صلاحيات افتراضية)

### الخطوة 2: أضف القناة من داخل البوت
أرسل للبوت في الخاص:

```
/addchannel <chat_id_أو_@username> <رابط_الدعوة> <اسم_القناة>
```

أمثلة:

```
/addchannel @mychannel https://t.me/mychannel قناتي الرئيسية
/addchannel -1001234567890 https://t.me/+abcXYZ123 قناة خاصة
```

### الأوامر الإدارية

| الأمر | الوصف |
|------|------|
| `/stats` | إحصائيات المستخدمين والتحميلات |
| `/broadcast <نص>` | بث رسالة نصية لجميع المسجلين |
| `/broadcast` (ردًّا على رسالة) | بث الرسالة المردود عليها كما هي |
| `/addchannel ...` | إضافة قناة اشتراك إجباري |
| `/removechannel <chat_id>` | حذف قناة |
| `/listchannels` | عرض كل قنوات الاشتراك |

## كيف يستخدم المستخدمون البوت

1. يبدؤون البوت بـ `/start`
2. يرسلون أي رابط فيديو
3. إذا لم يكونوا مشتركين بكل القنوات: تظهر لهم الأزرار + زر «✅ تحققت من الاشتراك»
4. بعد الاشتراك: ينزل البوت الفيديو ويرسله لهم

## التشغيل كخدمة (Linux + systemd)

أنشئ ملف `/etc/systemd/system/tg-bot.service`:

```ini
[Unit]
Description=Telegram Video Downloader Bot
After=network.target

[Service]
Type=simple
User=YOUR_USER
WorkingDirectory=/path/to/telegram-broadcast-bot
ExecStart=/path/to/telegram-broadcast-bot/venv/bin/python bot.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

ثم:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tg-bot
sudo systemctl status tg-bot
```

## ملاحظات هامة

- **حد حجم الملف**: Bot API الرسمي يسمح بإرسال 50MB كحد أقصى. الافتراضي 48MB. إذا أردت ملفات أكبر (حتى 2GB) ركّب [Local Bot API Server](https://github.com/tdlib/telegram-bot-api).
- **سرعة التحميل**: تعتمد على سرعة الخادم وعلى المنصة. تيك توك أسرع، يوتيوب الطويل أبطأ.
- **Instagram / Facebook**: بعض المحتوى الخاص أو الذي يتطلب تسجيل دخول لا يمكن تحميله بدون كوكيز. يمكنك إضافة ملف cookies إلى `yt-dlp` إن لزم.
- **قاعدة البيانات**: ملف SQLite `bot.db` يُنشأ تلقائياً. خذ نسخة احتياطية منه دوريًا.

## الهيكل

```
telegram-broadcast-bot/
├── bot.py              # الملف الرئيسي - المعالجات والمنطق
├── downloader.py       # تغليف yt-dlp
├── database.py         # SQLite (users + channels)
├── config.py           # تحميل المتغيرات من .env
├── requirements.txt    # المكتبات
├── .env.example        # نموذج للإعدادات
├── .gitignore
└── README.md
```

## الترخيص

استخدام شخصي. التزم بسياسات المنصات وبشروط خدمة تلغرام.
