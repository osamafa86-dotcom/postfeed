<?php
/**
 * نيوزفلو — اتصل بنا.
 *
 * Required for Google News eligibility: a real, staffed contact
 * channel. We list the editorial and press emails plus social
 * channels, and keep response-time expectations realistic.
 */
require_once __DIR__ . '/includes/static_page.php';

$contactEmail   = trim((string) getSetting('contact_email', 'contact@feedsnews.net'));
$editorialEmail = trim((string) getSetting('editorial_email', 'editor@feedsnews.net'));
$pressEmail     = trim((string) getSetting('press_email', 'press@feedsnews.net'));
$twitterHandle  = trim((string) getSetting('twitter_handle', ''));
$facebookPage   = trim((string) getSetting('facebook_page', ''));

static_page_open(
    'اتصل بنا',
    'طرق التواصل مع فريق نيوزفلو — استفسارات عامة، بلاغات تصحيح، طلبات صحفية، واقتراح مصادر جديدة.',
    'contact',
    '١٩ نيسان/أبريل ٢٠٢٦'
);
?>
<span class="sp-eyebrow">اتصل بنا</span>

<p>يسعدنا أن نسمع منك. سواء كان لديك استفسار عام، بلاغ عن خطأ في خبر، أو اقتراح بمصدر جديد — تجد أدناه القناة الأنسب لطلبك.</p>

<h2>للاستفسارات العامة</h2>
<div class="sp-contact-row">
  <a href="mailto:<?php echo e($contactEmail); ?>" class="sp-pill">📧 <span dir="ltr"><?php echo e($contactEmail); ?></span></a>
</div>
<p>نستجيب عادة خلال ٢–٣ أيام عمل.</p>

<h2>لبلاغات التصحيح والأخطاء التحريرية</h2>
<div class="sp-contact-row">
  <a href="mailto:<?php echo e($editorialEmail); ?>" class="sp-pill">✏️ <span dir="ltr"><?php echo e($editorialEmail); ?></span></a>
</div>
<p>إذا رصدت خطأً وقائعيًا في خبر أو ملخّص، راسلنا على هذا العنوان ونراجع البلاغ خلال ٢٤ ساعة. اطّلع على <a href="/corrections">سياسة التصحيح</a> لمعرفة كيف نتعامل مع هذا النوع من البلاغات.</p>

<h2>للطلبات الصحفية والشراكات</h2>
<div class="sp-contact-row">
  <a href="mailto:<?php echo e($pressEmail); ?>" class="sp-pill">🗞️ <span dir="ltr"><?php echo e($pressEmail); ?></span></a>
</div>
<p>المقابلات، التعليقات الرسمية، والاستفسارات البحثية.</p>

<h2>لاقتراح مصدر إخباري</h2>
<p>إذا كنت تعرف مصدرًا عربيًا موثوقًا لا نغطّيه بعد، راسلنا على <a href="mailto:<?php echo e($editorialEmail); ?>"><span dir="ltr"><?php echo e($editorialEmail); ?></span></a> مع ذكر:</p>
<ul>
  <li>اسم الموقع ورابطه الرسمي.</li>
  <li>رابط خلاصة RSS إن وُجد.</li>
  <li>القسم المناسب (سياسة، اقتصاد، رياضة…).</li>
</ul>
<p>نراجع كلّ طلب يدويًا قبل إضافة المصدر — يستغرق هذا عادة ٥–٧ أيام عمل.</p>

<?php if ($twitterHandle || $facebookPage): ?>
<h2>على منصّات التواصل</h2>
<div class="sp-contact-row">
  <?php if ($twitterHandle): ?>
    <a href="https://x.com/<?php echo e(ltrim($twitterHandle, '@')); ?>" class="sp-pill" rel="noopener" target="_blank">𝕏 @<?php echo e(ltrim($twitterHandle, '@')); ?></a>
  <?php endif; ?>
  <?php if ($facebookPage): ?>
    <a href="<?php echo e($facebookPage); ?>" class="sp-pill" rel="noopener" target="_blank">f فيسبوك</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="sp-callout">
  <strong>ملاحظة:</strong> نيوزفلو منصّة تجميع أخبار، لا نحرّر أو ننشر الأخبار بأنفسنا. إذا كانت لديك شكوى عن محتوى خبر منشور من جهة أخرى، يُفضَّل التواصل مع المصدر الأصلي المذكور في أعلى بطاقة الخبر. أما إذا كانت المشكلة في العرض عندنا (ملخّص خاطئ، عنوان مُضلّل، تصنيف خاطئ) فراسلنا ونعالجها فورًا.
</div>

<?php
static_page_close();
