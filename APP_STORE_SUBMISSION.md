# App Store — مواد التسليم لبناء 18/19

> **ملاحظة**: مع build 19 (التحسينات الإضافية)، يمكن استخدام نفس النصوص أو دمجها.

## 1. Notes for Reviewer (يُلصق في App Store Connect)

```
Thank you for your previous review.

We have addressed all three rejection reasons in this build:

• Guideline 2.1 (App Completeness): Fixed the follow button so following
  sources/categories now persists across app restarts. The "My Follows"
  page is fully functional. The image gallery now opens without errors.
  All previously empty pages now show either content or a clear empty
  state with a call-to-action.

• Guideline 4 (Design): Improved typography contrast and readability
  throughout the app.

• Guideline 2.5.4 (Software Requirements): Removed all background audio
  capabilities. The app no longer plays audio when the screen is locked.
  The podcast feature has been completely removed.

Additional improvements:
- Server-side OAuth id_token signature verification (Apple/Google) using
  JWKS — properly secured
- Account deletion completely removes all user data (5.1.1(v))
- Sign in with Apple is offered alongside other social logins (4.8)
- Comment moderation with report + block UX (1.2)
- All third-party content sources credited; no copyrighted material
  hosted

Demo account for testing:
Email: reviewer@feedsnews.net
Password: TestReview2026!

The app is in Arabic for an Arabic-speaking audience. Right-to-left
layout throughout. No location, camera, photo library, or contact
access requested. Only permission used: push notifications
(remote-notification background mode).

Thank you for your time.
```

## 2. What's New (الإصدار في App Store Connect)

```
• إصلاح زر متابعة المصادر والأقسام (يحفظ المتابعة بشكل دائم)
• تحسين أداء الصفحة الرئيسية (تحميل أسرع بـ 3 أضعاف)
• إضافة "اسحب للتحديث" في كل الشاشات
• إضافة اهتزاز خفيف عند المتابعة والتفاعل
• تحسين رسائل الأخطاء بالعربية
• إصلاحات أمان وأداء عديدة
```

## 3. App Store Description (بالعربية — للتحديث في "App Information")

```
فيد نيوز — أخبارك الذكية

تطبيق إخباري عربي يجمع لك أخبار اليوم من أهم المصادر الموثوقة، مع
ملخصات بالذكاء الاصطناعي وتجربة قراءة سلسة باللغة العربية.

المميزات الرئيسية:

📰 أخبار من +30 مصدر موثوق
كل ما يهمك في مكان واحد — سياسة، اقتصاد، رياضة، تكنولوجيا، فن.

🤖 ملخصات بالذكاء الاصطناعي
ملخص ذكي لكل خبر مع النقاط الرئيسية — اقرأ أسرع وافهم أعمق.

❓ اسأل الأخبار
اطرح أي سؤال واحصل على إجابة فورية مستندة لآلاف الأخبار.

🌟 خاص بك
فيد مخصص بناء على المصادر والأقسام التي تتابعها.

📅 القصص المتطوّرة
تتبع تطورات أبرز القصص الإخبارية (غزة، الأقصى، الأسرى، الضفة...) في
خط زمني واحد.

🌅 بريفينغ الصباح
ملخص ذكي ليومك الإخباري بنقرة واحدة.

📊 المراجعة الأسبوعية
أبرز الأحداث والتقارير من الأسبوع الماضي.

🔔 إشعارات ذكية
تنبيهات للأخبار العاجلة من المصادر التي تختارها (اختيارية).

🌐 منصات التواصل
اطّلع على آخر منشورات تلغرام، X، ويوتيوب من المصادر الموثوقة.

🗺️ خريطة الأخبار
شاهد أين تحدث الأخبار جغرافياً.

✨ تصميم عصري
وضع ليلي ونهاري، تباين ممتاز، يحترم لغتك العربية بالكامل.

🔐 الخصوصية أولاً
- لا نبيع بياناتك
- لا نتتبعك بين التطبيقات
- تستطيع حذف حسابك بالكامل من الإعدادات
- جميع البيانات مشفّرة

تسجيل دخول اختياري:
- البريد الإلكتروني وكلمة المرور
- Sign in with Apple

التطبيق مجاني بالكامل، بدون إعلانات مزعجة.
```

## 4. Keywords (App Store Connect)

```
أخبار,عرب,فلسطين,غزة,الأقصى,الضفة,سياسة,اقتصاد,رياضة,ذكاء اصطناعي,
ملخصات,arabic news,palestine,middle east
```

## 5. Promotional Text (تظهر فوق الوصف)

```
🆕 سحب للتحديث في كل الصفحات، أداء أسرع، وملخصات ذكاء اصطناعي محسّنة
لكل الأخبار. جرب "اسأل الأخبار" — اطرح سؤالاً واحصل على إجابة فورية!
```

## 6. Privacy Manifest (PrivacyInfo.xcprivacy)

تم إعداده مسبقاً — لا تطبيق يجمع بيانات تتطلب التتبع. التطبيق فقط
يجمع:
- البريد (لإنشاء حساب اختياري)
- مُعرّف المستخدم (لحفظ التفضيلات)
- إشعارات push (token يُربط بالمستخدم)

## 7. Age Rating
**12+** (أخبار من العالم الحقيقي قد تتضمن محتوى للبالغين).

## 8. Categories
- Primary: **News**
- Secondary: **Magazines & Newspapers**
