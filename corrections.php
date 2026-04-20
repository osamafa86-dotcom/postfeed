<?php
/**
 * نيوز فيد — سياسة التصحيح.
 *
 * A separate page because Google News evaluates the corrections
 * process specifically: how are errors flagged, who fixes them, and
 * how is the fix communicated to the reader?
 */
require_once __DIR__ . '/includes/static_page.php';

static_page_open(
    'سياسة التصحيح',
    'كيف يستقبل نيوز فيد بلاغات الأخطاء، يراجعها، ويُصحّحها بشفافية. خطوات الإبلاغ وأوقات الاستجابة.',
    'corrections',
    '١٩ نيسان/أبريل ٢٠٢٦'
);
?>
<span class="sp-eyebrow">سياسة التصحيح</span>

<p>الخطأ وارد في أيّ عمل صحفي، والمهم أن نعترف به ونُصحّحه بشفافية. هذه السياسة تصف كيف نتعامل مع البلاغات من لحظة استلامها حتى نشر التصحيح.</p>

<h2>١. ما الذي نعتبره "خطأ"</h2>
<ul>
  <li><strong>خطأ وقائعي</strong> — اسم خاطئ، تاريخ خاطئ، رقم غير صحيح، نسب تصريح لشخص لم يقله.</li>
  <li><strong>خطأ في الملخّص الذكي</strong> — معلومة أضافها الذكاء الاصطناعي ولا توجد في الخبر الأصلي، أو تحريف للمعنى.</li>
  <li><strong>تصنيف خاطئ</strong> — خبر مُصنَّف في القسم الخطأ (مثل رياضة في السياسة).</li>
  <li><strong>عنوان مُضلِّل</strong> — عنوان لا يعكس محتوى الخبر الحقيقي.</li>
  <li><strong>مصدر خاطئ</strong> — نسب خبر إلى مصدر لم ينشره.</li>
</ul>

<h2>٢. ما الذي لا يُعتَبر خطأً تحريريًا</h2>
<ul>
  <li>الاختلاف في الرأي أو التفسير.</li>
  <li>وجهة نظر مصدر لا تُعجبك (الحلّ: نُضيف مصدرًا مقابلًا).</li>
  <li>معلومة وردت حرفيًا في المصدر الأصلي — في هذه الحالة، يجب مراسلة المصدر مباشرة.</li>
</ul>

<h2>٣. كيف تُبلّغنا</h2>
<p>راسلنا على عنوان قسم التحرير: <a href="mailto:editor@feedsnews.net"><span dir="ltr">editor@feedsnews.net</span></a> — واذكر في الرسالة:</p>
<ul>
  <li><strong>رابط الخبر</strong> الذي تقصده على نيوز فيد.</li>
  <li><strong>وصف الخطأ</strong> بدقّة.</li>
  <li><strong>الصواب</strong> من وجهة نظرك.</li>
  <li><strong>المصدر/الدليل</strong> الذي يُثبت الصواب (اختياري، لكن يسرّع المعالجة كثيرًا).</li>
</ul>

<h2>٤. مدّة الاستجابة</h2>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <thead>
    <tr style="background:#f1f5f9;">
      <th style="padding:12px;border:1px solid var(--border);text-align:right;">نوع الخطأ</th>
      <th style="padding:12px;border:1px solid var(--border);text-align:right;">مدة المعالجة</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:12px;border:1px solid var(--border);">خطأ جوهري في خبر عاجل</td>
      <td style="padding:12px;border:1px solid var(--border);">خلال ساعتين</td>
    </tr>
    <tr>
      <td style="padding:12px;border:1px solid var(--border);">خطأ وقائعي في ملخّص</td>
      <td style="padding:12px;border:1px solid var(--border);">خلال ٢٤ ساعة</td>
    </tr>
    <tr>
      <td style="padding:12px;border:1px solid var(--border);">تصنيف أو عنوان خاطئ</td>
      <td style="padding:12px;border:1px solid var(--border);">خلال ٤٨ ساعة</td>
    </tr>
    <tr>
      <td style="padding:12px;border:1px solid var(--border);">طلبات حذف أرشيف</td>
      <td style="padding:12px;border:1px solid var(--border);">خلال ٧٢ ساعة</td>
    </tr>
  </tbody>
</table>

<h2>٥. كيف يظهر التصحيح</h2>
<p>بعد المراجعة، نتّخذ إحدى هذه الإجراءات حسب الحالة:</p>
<ul>
  <li><strong>تعديل فوري للنصّ</strong> إذا كان الخطأ واضحًا، مع إضافة ملاحظة في أسفل الخبر: <em>"تمّ تصحيح … بتاريخ …"</em>.</li>
  <li><strong>حذف الملخّص الذكي</strong> إذا كان مصدر الخطأ هو الملخّص فقط، مع إبقاء الخبر الأصلي.</li>
  <li><strong>حذف الخبر كاملًا</strong> من نيوز فيد في الحالات الخطيرة (خبر زائف بالكامل أو يُشكّل ضررًا).</li>
  <li><strong>نشر توضيح</strong> بارز إذا كان الخطأ قد انتشر واسعًا.</li>
</ul>

<h2>٦. الشفافية</h2>
<p>نحتفظ بسجلّ داخلي لجميع التصحيحات الجوهرية ونعيد مراجعته دوريًا لتحسين عملياتنا. نلتزم بعدم إخفاء التصحيحات ولا حذفها من أرشيف الصفحة ذاتها إلا في الحالات المذكورة أعلاه.</p>

<h2>٧. تصعيد البلاغ</h2>
<p>إذا لم تتلقَّ ردًا على بلاغك خلال المدّة المذكورة، أو لم تكن راضيًا عن طريقة المعالجة، يمكنك التصعيد إلى رئاسة التحرير عبر <a href="/contact">صفحة الاتصال</a> مع الإشارة صراحةً إلى <strong>"طلب تصعيد"</strong>. سيُراجع الملف مسؤول مختلف عن من تعامل مع البلاغ الأصلي.</p>

<div class="sp-callout">
  الصحافة الجيّدة تعترف بأخطائها بوضوح. نقدّر تعاونك في الحفاظ على دقّة ما يُنشر على نيوز فيد.
</div>

<?php
static_page_close();
