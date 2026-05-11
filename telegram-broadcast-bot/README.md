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

## رفع حد الإرسال إلى 150MB+ (Local Bot API Server)

Bot API الرسمي محدود بـ **50MB**. لرفع الحد إلى 2GB يجب تشغيل خادم تلغرام محلياً.

### الخطوات

#### 1) احصل على `api_id` و `api_hash`
- ادخل إلى https://my.telegram.org/apps بحساب تلغرامك
- أنشئ تطبيقاً وانسخ `App api_id` و `App api_hash`

#### 2) ركّب الخادم (compile من المصدر)
على Ubuntu / Debian:

```bash
cd telegram-broadcast-bot
./scripts/install_local_api.sh
```

السكربت يثبّت اعتماديات البناء، يستنسخ المستودع، ويبني البايناري في `/usr/local/bin/telegram-bot-api`. (يستغرق 10–20 دقيقة)

#### 3) إعداد المستخدم والخدمة

```bash
sudo ./scripts/setup_service.sh
```

السكربت رح:
- ينشئ مستخدم نظام `telegram-bot-api`
- ينشئ مجلدات `/var/lib/telegram-bot-api` و `/var/log/telegram-bot-api`
- يسألك عن `TELEGRAM_API_ID` و `TELEGRAM_API_HASH` ويحفظهم في `/etc/telegram-bot-api.env` (مغلّق على root، 600)
- يثبّت ويشغّل خدمة systemd

تحقق من الخدمة:

```bash
sudo systemctl status telegram-bot-api
curl http://127.0.0.1:8081/
# المتوقع: تجاوب بـ 404 NOT FOUND - هذا يعني الخادم شغّال
```

#### 4) سجّل خروج البوت من الخادم السحابي
**خطوة لمرة واحدة فقط:**

```bash
# تأكد إن USE_LOCAL_API=false في .env
source venv/bin/activate
python scripts/migrate_to_local.py
```

#### 5) فعّل الوضع المحلي في `.env`

```env
USE_LOCAL_API=true
MAX_FILE_SIZE_MB=150
LOCAL_API_BASE_URL=http://localhost:8081/bot
LOCAL_API_FILE_URL=http://localhost:8081/file/bot
```

#### 6) أعد تشغيل البوت

```bash
sudo systemctl restart tg-bot   # لو ركّبته كخدمة
# أو
python bot.py
```

من السطر الأول من الـ log رح تشوف:
```
Using LOCAL Bot API server at http://localhost:8081/bot
```

### العودة للخادم السحابي
- أوقف `telegram-bot-api`: `sudo systemctl stop telegram-bot-api`
- غيّر `.env`: `USE_LOCAL_API=false` و `MAX_FILE_SIZE_MB=48`
- أعد تشغيل البوت — سيعود تلقائياً للسحابي

### استكشاف الأخطاء
- **`429 Conflict`** بعد التبديل: لم يتم `logOut` بنجاح. أعد تشغيل `migrate_to_local.py`.
- **`Connection refused` على 8081**: الخدمة لا تعمل. راجع `journalctl -u telegram-bot-api -n 100`.
- **ملفات تكبر في `/var/lib/telegram-bot-api`**: الخادم يخزّن نسخاً مؤقتة. نظّف دورياً:
  ```bash
  sudo find /var/lib/telegram-bot-api -type f -mtime +7 -delete
  ```

## ملاحظات هامة

- **حد حجم الملف**: السحابي 50MB، المحلي حتى 2GB. الافتراضي هنا 150MB (يحتاج المحلي).
- **سرعة التحميل**: تعتمد على سرعة الخادم وعلى المنصة. تيك توك أسرع، يوتيوب الطويل أبطأ.
- **Instagram / Facebook**: بعض المحتوى الخاص يتطلب كوكيز. يمكنك إضافة `cookiefile` إلى `yt-dlp` إن لزم.
- **قاعدة البيانات**: ملف SQLite `bot.db` يُنشأ تلقائياً. خذ نسخة احتياطية منه دوريًا.

## الهيكل

```
telegram-broadcast-bot/
├── bot.py                     # الملف الرئيسي - المعالجات والمنطق
├── downloader.py              # تغليف yt-dlp
├── database.py                # SQLite (users + channels)
├── config.py                  # تحميل المتغيرات من .env
├── requirements.txt           # المكتبات
├── .env.example               # نموذج للإعدادات
├── .gitignore
├── README.md
└── scripts/
    ├── install_local_api.sh       # تركيب telegram-bot-api من المصدر
    ├── setup_service.sh           # إعداد المستخدم + systemd
    ├── telegram-bot-api.service   # قالب خدمة systemd
    └── migrate_to_local.py        # تسجيل خروج لمرة واحدة من السحابي
```

## الترخيص

استخدام شخصي. التزم بسياسات المنصات وبشروط خدمة تلغرام.
