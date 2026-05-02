import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/app_theme.dart';

// ═══════════════════════════════════════════════════════════════
// REUSABLE INFO PAGE SHELL
// ═══════════════════════════════════════════════════════════════

class _InfoPage extends StatelessWidget {
  const _InfoPage({required this.title, required this.children});
  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: children,
      ),
    );
  }
}

Widget _heading(String text, BuildContext context) {
  final isDark = Theme.of(context).brightness == Brightness.dark;
  return Padding(
    padding: const EdgeInsets.only(top: 24, bottom: 8),
    child: Text(text,
      style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800,
        color: isDark ? Colors.white : AppColors.textLight)),
  );
}

Widget _subheading(String text, BuildContext context) {
  final isDark = Theme.of(context).brightness == Brightness.dark;
  return Padding(
    padding: const EdgeInsets.only(top: 16, bottom: 6),
    child: Text(text,
      style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700,
        color: isDark ? Colors.white : AppColors.textLight)),
  );
}

Widget _para(String text, BuildContext context) {
  final isDark = Theme.of(context).brightness == Brightness.dark;
  return Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Text(text,
      style: TextStyle(fontSize: 14, height: 1.8,
        color: isDark ? Colors.white70 : AppColors.textLight)),
  );
}

Widget _bullet(String text, BuildContext context) {
  final isDark = Theme.of(context).brightness == Brightness.dark;
  return Padding(
    padding: const EdgeInsets.only(bottom: 6, right: 12),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Container(width: 5, height: 5,
            decoration: BoxDecoration(color: AppColors.primary, shape: BoxShape.circle)),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(text,
            style: TextStyle(fontSize: 14, height: 1.7,
              color: isDark ? Colors.white70 : AppColors.textLight)),
        ),
      ],
    ),
  );
}

Widget _callout(String text, BuildContext context) {
  final isDark = Theme.of(context).brightness == Brightness.dark;
  return Container(
    margin: const EdgeInsets.symmetric(vertical: 12),
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: AppColors.primary.withOpacity(0.06),
      borderRadius: BorderRadius.circular(12),
      border: Border(right: BorderSide(color: AppColors.primary, width: 4)),
    ),
    child: Text(text,
      style: TextStyle(fontSize: 13, height: 1.7,
        color: isDark ? Colors.white70 : AppColors.textLight)),
  );
}

Widget _emailButton(String label, String email, BuildContext context) {
  return Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: OutlinedButton.icon(
      onPressed: () => launchUrl(Uri.parse('mailto:$email')),
      icon: const Icon(Icons.email_outlined, size: 16),
      label: Text('$label — $email'),
      style: OutlinedButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
  );
}

// ═══════════════════════════════════════════════════════════════
// ABOUT PAGE
// ═══════════════════════════════════════════════════════════════

class AboutPage extends StatelessWidget {
  const AboutPage({super.key});

  @override
  Widget build(BuildContext context) {
    return _InfoPage(
      title: 'من نحن',
      children: [
        _para('نيوز فيد (feedsnews.net) منصّة عربية لتجميع الأخبار من مصادر إعلامية موثوقة ومتعددة، هدفها تقديم صورة شاملة ومتوازنة لأحدث الأحداث في مكان واحد — بدون حشو، وبدون إعلانات مزعجة.', context),

        _heading('رسالتنا', context),
        _para('نؤمن بأن القارئ العربي يستحقّ الوصول إلى خبر دقيق وسريع، مصدره واضح ومسؤولياته محدّدة.', context),
        _bullet('تجميع الأخبار من عشرات المصادر العربية والعالمية في واجهة واحدة منظّمة.', context),
        _bullet('عرض المصدر الأصلي لكل خبر بشفافية، مع رابط واضح للقارئ للرجوع إليه.', context),
        _bullet('تلخيص الأخبار الطويلة تلخيصاً أميناً بمساعدة الذكاء الاصطناعي يوفّر وقت القارئ.', context),
        _bullet('تسليط الضوء على القصص المتطوّرة والخطوط الزمنية، ليتابع القارئ سياق الحدث لا الخبر المنفرد فقط.', context),

        _heading('كيف نعمل', context),
        _para('نعتمد على خلاصات RSS الرسمية للمصادر المعتمدة (وكالات، صحف، مواقع إخبارية)، ونستورد الأخبار آلياً عدة مرات في الساعة. لا ننسخ المقالات كاملةً؛ نعرض العنوان والملخّص القصير مع رابط المصدر الأصلي.', context),

        _subheading('الأدوات التي نستخدمها', context),
        _bullet('قارئ RSS آلي — يجلب الأخبار الجديدة من المصادر المعتمدة فور نشرها.', context),
        _bullet('ذكاء اصطناعي للتلخيص — نستخدم نماذج لغة لتوليد ملخّص موجز دون تغيير المعنى الأصلي.', context),
        _bullet('تجميع القصص — نجمع التقارير المتعدّدة عن الحدث نفسه في مجموعة واحدة.', context),

        _heading('الاستقلالية والتمويل', context),
        _para('نيوز فيد مشروع مستقلّ غير ممول من جهة حكومية أو حزبية، ولا نتلقّى توجيهاً تحريرياً من أي مصدر نتعامل معه. لا نبيع بيانات القرّاء ولا نسمح لأي جهة بالتأثير على ترتيب الأخبار.', context),

        _callout('هل عندك مصدر إخباري موثوق تودّ أن يُضاف إلى المنصّة؟ تواصل معنا عبر صفحة الاتصال وسنراجع الطلب خلال ٧٢ ساعة.', context),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// PRIVACY POLICY
// ═══════════════════════════════════════════════════════════════

class PrivacyPolicyPage extends StatelessWidget {
  const PrivacyPolicyPage({super.key});

  @override
  Widget build(BuildContext context) {
    return _InfoPage(
      title: 'سياسة الخصوصية',
      children: [
        _para('نحترم خصوصيتك ونعتبرها أولويّة. توضّح هذه السياسة أنواع البيانات التي نجمعها حين تستخدم نيوز فيد، وكيف نستخدمها، وحقوقك في التحكّم بها.', context),

        _heading('١. المعلومات التي نجمعها', context),
        _subheading('أ. معلومات تقدّمها أنت', context),
        _bullet('عنوان بريدك الإلكتروني — فقط إذا اشتركت في النشرة أو أنشأت حساباً.', context),
        _bullet('اسم المستخدم — إذا سجّلت حساباً لمتابعة مصادر أو مواضيع.', context),
        _bullet('التفضيلات — المصادر/الأقسام التي تتابعها، المقالات المحفوظة.', context),

        _subheading('ب. معلومات تُجمع تلقائياً', context),
        _bullet('سجلّات الزيارات — عنوان IP، نوع المتصفّح، الصفحات التي تزورها. نحتفظ بها ٣٠ يوماً فقط.', context),
        _bullet('بيانات تحليلية مُجمّعة — إحصاءات لا تُعرّفك شخصياً.', context),

        _heading('٢. كيف نستخدم هذه المعلومات', context),
        _bullet('تشغيل التطبيق وإظهار الأخبار ذات الصلة بتفضيلاتك.', context),
        _bullet('إرسال النشرة اليومية (فقط إذا اشتركت).', context),
        _bullet('حماية التطبيق من إساءة الاستخدام.', context),

        _heading('٣. ماذا لا نفعل', context),
        _bullet('لا نبيع بياناتك لأيّ طرف ثالث، مطلقاً.', context),
        _bullet('لا نشارك عنوان بريدك مع المعلنين.', context),
        _bullet('لا نستخدم بياناتك لأغراض تسويقية خارج خدمات نيوز فيد.', context),

        _heading('٤. حقوقك', context),
        _bullet('تطلب نسخة من بياناتك لدينا.', context),
        _bullet('تطلب حذف حسابك وبياناتك نهائياً.', context),
        _bullet('تُلغي اشتراكك في النشرة.', context),
        _bullet('تعترض على أي استخدام لبياناتك لا تراه مناسباً.', context),

        _heading('٥. أمان البيانات', context),
        _para('نُخزّن جميع البيانات على خوادم آمنة، ونستخدم تشفير HTTPS لكلّ الاتصالات. نحتفظ بكلمات المرور مُجزَّأة بخوارزميات حديثة. في حال وقوع اختراق يمسّ بياناتك، سنُبلغك خلال ٧٢ ساعة.', context),

        const SizedBox(height: 12),
        _para('آخر تحديث: ١٩ نيسان/أبريل ٢٠٢٦', context),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// CONTACT PAGE
// ═══════════════════════════════════════════════════════════════

class ContactPage extends StatelessWidget {
  const ContactPage({super.key});

  @override
  Widget build(BuildContext context) {
    return _InfoPage(
      title: 'اتصل بنا',
      children: [
        _para('يسعدنا أن نسمع منك. سواء كان لديك استفسار عام، بلاغ عن خطأ في خبر، أو اقتراح بمصدر جديد — تجد أدناه القناة الأنسب لطلبك.', context),

        _heading('للاستفسارات العامة', context),
        _emailButton('استفسار عام', 'contact@feedsnews.net', context),
        _para('نستجيب عادة خلال ٢–٣ أيام عمل.', context),

        _heading('لبلاغات التصحيح والأخطاء', context),
        _emailButton('بلاغ تحريري', 'editor@feedsnews.net', context),
        _para('إذا رصدت خطأً وقائعياً في خبر أو ملخّص، راسلنا ونراجع البلاغ خلال ٢٤ ساعة.', context),

        _heading('للطلبات الصحفية والشراكات', context),
        _emailButton('طلب صحفي', 'press@feedsnews.net', context),
        _para('المقابلات، التعليقات الرسمية، والاستفسارات البحثية.', context),

        _heading('لاقتراح مصدر إخباري', context),
        _para('إذا كنت تعرف مصدراً عربياً موثوقاً لا نغطّيه بعد، راسلنا مع ذكر:', context),
        _bullet('اسم الموقع ورابطه الرسمي.', context),
        _bullet('رابط خلاصة RSS إن وُجد.', context),
        _bullet('القسم المناسب (سياسة، اقتصاد، رياضة…).', context),
        _para('نراجع كلّ طلب يدوياً — يستغرق هذا عادة ٥–٧ أيام عمل.', context),

        _callout('ملاحظة: نيوز فيد منصّة تجميع أخبار، لا نحرّر أو ننشر الأخبار بأنفسنا. إذا كانت لديك شكوى عن محتوى خبر منشور من جهة أخرى، يُفضَّل التواصل مع المصدر الأصلي.', context),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// EDITORIAL POLICY
// ═══════════════════════════════════════════════════════════════

class EditorialPolicyPage extends StatelessWidget {
  const EditorialPolicyPage({super.key});

  @override
  Widget build(BuildContext context) {
    return _InfoPage(
      title: 'السياسة التحريرية',
      children: [
        _para('نُنظّم عملنا التحريري وفق مبادئ واضحة تُعلَن للقارئ ونُحاسَب عليها.', context),

        _heading('١. الاستقلالية التحريرية', context),
        _para('القرارات التحريرية في نيوز فيد مستقلّة عن التمويل والإعلانات. لا يجوز لأيّ مُعلن أو شريك التأثير على اختيار الأخبار أو ترتيبها.', context),

        _heading('٢. اختيار المصادر', context),
        _para('لا نُضيف مصدراً إلى النظام قبل أن يستوفي الشروط التالية:', context),
        _bullet('الشفافية — هويّة المؤسّسة وفريق التحرير معروفة.', context),
        _bullet('المصداقية — سجلّ تحريري لا تقلّ مدّته عن سنة.', context),
        _bullet('الإفصاح — سياسة تصحيح أخطاء مُعلنة.', context),
        _bullet('عدم الاستعراض — نستبعد المواقع التي تعتمد على عناوين مضلّلة.', context),

        _heading('٣. اختيار الأخبار', context),
        _para('نُرتّب الأخبار تلقائياً بناءً على:', context),
        _bullet('تاريخ النشر (الأحدث أولاً).', context),
        _bullet('مدى تغطية الحدث عبر عدّة مصادر مستقلّة.', context),
        _bullet('تفاعل القرّاء (في قسم الأكثر قراءةً فقط).', context),

        _heading('٤. الفصل بين الخبر والرأي', context),
        _para('كلّ ما ينشر على نيوز فيد هو إمّا خبر أو ملخّص لخبر. لا ننشر مقالات رأي باسم المنصّة.', context),

        _heading('٥. الذكاء الاصطناعي والتلخيص', context),
        _bullet('الملخّص مُوسَم دائماً بعبارة "ملخّص ذكي".', context),
        _bullet('لا يُضيف الذكاء الاصطناعي معلومات غير موجودة في الخبر الأصلي.', context),
        _bullet('أيّ ملخّص غير دقيق يُزال فوراً عند إخطارنا.', context),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// CORRECTIONS POLICY
// ═══════════════════════════════════════════════════════════════

class CorrectionsPolicyPage extends StatelessWidget {
  const CorrectionsPolicyPage({super.key});

  @override
  Widget build(BuildContext context) {
    return _InfoPage(
      title: 'سياسة التصحيح',
      children: [
        _para('الخطأ وارد في أيّ عمل صحفي، والمهم أن نعترف به ونُصحّحه بشفافية.', context),

        _heading('١. ما الذي نعتبره خطأ', context),
        _bullet('خطأ وقائعي — اسم خاطئ، تاريخ خاطئ، رقم غير صحيح.', context),
        _bullet('خطأ في الملخّص الذكي — معلومة أضافها الذكاء الاصطناعي ولا توجد في الخبر.', context),
        _bullet('تصنيف خاطئ — خبر مُصنَّف في القسم الخطأ.', context),
        _bullet('عنوان مُضلِّل — عنوان لا يعكس محتوى الخبر.', context),
        _bullet('مصدر خاطئ — نسب خبر إلى مصدر لم ينشره.', context),

        _heading('٢. ما الذي لا يُعتَبر خطأً', context),
        _bullet('الاختلاف في الرأي أو التفسير.', context),
        _bullet('وجهة نظر مصدر لا تُعجبك.', context),
        _bullet('معلومة وردت حرفياً في المصدر الأصلي.', context),

        _heading('٣. كيف تُبلّغنا', context),
        _emailButton('بلاغ تصحيح', 'editor@feedsnews.net', context),
        _para('اذكر في الرسالة:', context),
        _bullet('رابط الخبر الذي تقصده.', context),
        _bullet('وصف الخطأ بدقّة.', context),
        _bullet('الصواب من وجهة نظرك.', context),
        _bullet('المصدر/الدليل الذي يُثبت الصواب (اختياري).', context),

        _heading('٤. مدّة الاستجابة', context),
        _bullet('خطأ جوهري في خبر عاجل — خلال ساعتين.', context),
        _bullet('خطأ في ملخّص ذكي — خلال ٤ ساعات.', context),
        _bullet('تصنيف خاطئ أو عنوان مُضلِّل — خلال ٢٤ ساعة.', context),
        _bullet('مسائل خلافية — خلال ٣ أيام عمل.', context),
      ],
    );
  }
}
