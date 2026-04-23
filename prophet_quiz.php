<?php
/**
 * نيوز فيد — لعبة أسئلة عن النبي محمد ﷺ.
 *
 * صفحة تفاعلية ذاتية الاحتواء (HTML/CSS/JS فقط، بلا قاعدة بيانات) تعرض
 * ٢٠ سؤالًا متعدّد الخيارات عن السيرة النبوية بمستوى مناسب لعمر ١٠+.
 * لكل سؤال ٤ خيارات وشرح يظهر بعد الإجابة، مع تتبّع النقاط وشريط تقدّم
 * وشاشة نتيجة نهائية مع تقييم وإمكانية إعادة اللعب.
 */
require_once __DIR__ . '/includes/functions.php';
$siteName  = getSetting('site_name', SITE_NAME);
$canonical = rtrim(SITE_URL, '/') . '/prophet-quiz';
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>لعبة أسئلة السيرة النبوية — <?php echo e($siteName); ?></title>
<meta name="description" content="لعبة أسئلة تفاعلية عن سيرة النبي محمد ﷺ للأطفال والناشئة من عمر ١٠ سنوات فأكثر. ٢٠ سؤالًا مع الشرح والنقاط.">
<meta name="robots" content="index,follow">
<link rel="canonical" href="<?php echo e($canonical); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="لعبة أسئلة السيرة النبوية">
<meta property="og:description" content="اختبر معلوماتك عن سيرة النبي محمد ﷺ في ٢٠ سؤالًا ممتعًا.">
<meta property="og:url" content="<?php echo e($canonical); ?>">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Amiri:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root {
    --bg-grad-1: #0f766e;
    --bg-grad-2: #134e4a;
    --card: #ffffff;
    --border: #e0e3e8;
    --gold: #f59e0b;
    --gold-2: #d97706;
    --accent: #0f766e;
    --accent-2: #134e4a;
    --text: #1a1a2e;
    --muted: #6b7280;
    --success: #10b981;
    --error: #ef4444;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  html, body { height:100%; }
  body {
    font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif;
    background:linear-gradient(135deg,var(--bg-grad-1) 0%, var(--bg-grad-2) 100%);
    color:var(--text); line-height:1.7;
    min-height:100vh;
    background-attachment:fixed;
    padding:16px;
  }
  .q-wrap {
    max-width:680px; margin:24px auto;
  }

  /* الرأس */
  .q-header {
    text-align:center; color:#fff; margin-bottom:20px;
  }
  .q-logo {
    display:inline-flex; align-items:center; gap:10px;
    color:#fff; text-decoration:none; font-weight:700; font-size:14px;
    padding:6px 14px; border-radius:20px;
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2);
    margin-bottom:18px;
  }
  .q-logo:hover { background:rgba(255,255,255,.2); }
  .q-title {
    font-size:28px; font-weight:900; margin-bottom:6px;
    text-shadow:0 2px 10px rgba(0,0,0,.2);
  }
  .q-subtitle {
    font-size:15px; opacity:.85; font-weight:500;
  }

  /* البطاقة */
  .q-card {
    background:var(--card); border-radius:20px;
    box-shadow:0 20px 50px rgba(0,0,0,.25);
    padding:32px 28px; position:relative; overflow:hidden;
  }
  .q-card::before {
    content:""; position:absolute; top:0; right:0; left:0; height:5px;
    background:linear-gradient(90deg,var(--gold),var(--accent));
  }

  /* شاشة البداية */
  .q-intro { text-align:center; padding:12px 0; }
  .q-intro .q-icon {
    font-size:64px; margin-bottom:14px; display:block;
  }
  .q-intro h2 {
    font-size:24px; font-weight:900; margin-bottom:10px; color:var(--accent-2);
  }
  .q-intro p {
    color:var(--muted); font-size:16px; margin-bottom:22px;
  }
  .q-info-row {
    display:flex; justify-content:center; gap:12px; flex-wrap:wrap;
    margin-bottom:26px;
  }
  .q-info-pill {
    background:#f0fdfa; border:1px solid #99f6e4; color:var(--accent-2);
    padding:10px 18px; border-radius:12px; font-weight:700; font-size:14px;
    display:inline-flex; align-items:center; gap:8px;
  }

  /* الأزرار */
  .q-btn {
    display:inline-block; cursor:pointer; border:0; font-family:inherit;
    font-weight:800; font-size:17px; padding:14px 36px;
    border-radius:12px; transition:all .18s;
    background:linear-gradient(135deg,var(--gold),var(--gold-2)); color:#fff;
    box-shadow:0 6px 18px rgba(217,119,6,.35);
  }
  .q-btn:hover { transform:translateY(-2px); box-shadow:0 10px 22px rgba(217,119,6,.45); }
  .q-btn:active { transform:translateY(0); }
  .q-btn.q-btn-ghost {
    background:#f1f5f9; color:var(--text);
    box-shadow:none; border:1px solid var(--border);
  }
  .q-btn.q-btn-ghost:hover { background:#e2e8f0; box-shadow:none; }

  /* شريط التقدّم */
  .q-progress {
    display:flex; justify-content:space-between; align-items:center;
    margin-bottom:14px; font-size:13px; color:var(--muted); font-weight:600;
  }
  .q-progress .q-score { color:var(--gold-2); font-weight:800; }
  .q-bar {
    height:8px; background:#f1f5f9; border-radius:10px; overflow:hidden;
    margin-bottom:24px;
  }
  .q-bar-fill {
    height:100%; background:linear-gradient(90deg,var(--accent),var(--gold));
    width:0%; transition:width .35s ease;
    border-radius:10px;
  }

  /* السؤال */
  .q-question {
    font-size:20px; font-weight:800; color:var(--text);
    line-height:1.6; margin-bottom:22px;
    padding:16px 20px; background:#f8fafc; border-radius:12px;
    border-right:4px solid var(--accent);
  }

  /* الخيارات */
  .q-options {
    display:flex; flex-direction:column; gap:10px; margin-bottom:16px;
  }
  .q-option {
    display:flex; align-items:center; gap:12px;
    padding:14px 18px; border:2px solid var(--border);
    border-radius:12px; cursor:pointer; font-weight:600; font-size:16px;
    background:#fff; transition:all .18s;
    text-align:right; width:100%; font-family:inherit; color:var(--text);
  }
  .q-option:hover:not(:disabled) {
    border-color:var(--accent); background:#f0fdfa;
    transform:translateX(-3px);
  }
  .q-option:disabled { cursor:default; }
  .q-option .q-letter {
    width:32px; height:32px; border-radius:50%; display:inline-flex;
    align-items:center; justify-content:center;
    background:#e2e8f0; color:var(--text); font-weight:900; font-size:14px;
    flex-shrink:0;
  }
  .q-option.q-correct {
    border-color:var(--success); background:#d1fae5; color:#065f46;
  }
  .q-option.q-correct .q-letter {
    background:var(--success); color:#fff;
  }
  .q-option.q-wrong {
    border-color:var(--error); background:#fee2e2; color:#991b1b;
  }
  .q-option.q-wrong .q-letter {
    background:var(--error); color:#fff;
  }

  /* الشرح */
  .q-explain {
    margin-top:14px; padding:14px 18px;
    background:#fffbeb; border:1px solid #fde68a;
    border-radius:12px; color:#78350f; font-size:15px;
    display:none;
  }
  .q-explain.q-show { display:block; animation:fadeIn .3s; }
  .q-explain strong { color:#92400e; }
  @keyframes fadeIn {
    from { opacity:0; transform:translateY(6px); }
    to   { opacity:1; transform:translateY(0); }
  }

  .q-next-wrap { margin-top:18px; text-align:left; display:none; }
  .q-next-wrap.q-show { display:block; }

  /* شاشة النتيجة */
  .q-result { text-align:center; padding:10px 0; }
  .q-result .q-trophy { font-size:76px; margin-bottom:12px; display:block; }
  .q-result h2 {
    font-size:26px; font-weight:900; margin-bottom:8px; color:var(--accent-2);
  }
  .q-result .q-final-score {
    font-size:54px; font-weight:900;
    background:linear-gradient(135deg,var(--gold),var(--gold-2));
    -webkit-background-clip:text; background-clip:text;
    color:transparent; margin:10px 0;
  }
  .q-result .q-final-total { color:var(--muted); font-size:16px; font-weight:700; }
  .q-result .q-verdict {
    margin:18px 0 22px; font-size:17px; color:var(--text);
    padding:14px 20px; background:#f0fdfa; border-radius:12px;
    border:1px solid #99f6e4;
  }
  .q-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

  /* التذييل */
  .q-footer {
    text-align:center; color:rgba(255,255,255,.7); font-size:13px;
    margin-top:18px;
  }
  .q-footer a { color:#fff; text-decoration:underline; }

  /* موبايل */
  @media (max-width:560px) {
    .q-card { padding:24px 20px; border-radius:16px; }
    .q-title { font-size:24px; }
    .q-intro .q-icon { font-size:54px; }
    .q-question { font-size:17px; padding:14px 16px; }
    .q-option { padding:12px 14px; font-size:15px; }
    .q-result .q-final-score { font-size:42px; }
    .q-btn { width:100%; }
  }
</style>
</head>
<body>
<div class="q-wrap">
  <header class="q-header">
    <a href="/" class="q-logo">← العودة إلى <?php echo e($siteName); ?></a>
    <h1 class="q-title">🕌 أسئلة السيرة النبوية</h1>
    <p class="q-subtitle">لعبة تعليمية ممتعة عن حياة النبي ﷺ</p>
  </header>

  <main class="q-card">
    <!-- شاشة البداية -->
    <section id="screen-intro" class="q-intro">
      <span class="q-icon">📖</span>
      <h2>اختبر معلوماتك عن حبيبنا محمد ﷺ</h2>
      <p>٢٠ سؤالًا عن حياته وسيرته العطرة، مناسب لعمر ١٠ سنوات فأكثر.</p>
      <div class="q-info-row">
        <span class="q-info-pill">❓ ٢٠ سؤال</span>
        <span class="q-info-pill">⭐ لكل إجابة ١ نقطة</span>
        <span class="q-info-pill">💡 شرح بعد كل إجابة</span>
      </div>
      <button class="q-btn" id="btn-start">ابدأ اللعبة</button>
    </section>

    <!-- شاشة السؤال (مخفية في البداية) -->
    <section id="screen-quiz" style="display:none;">
      <div class="q-progress">
        <span>السؤال <strong id="q-current">1</strong> من <strong id="q-total">20</strong></span>
        <span class="q-score">النقاط: <span id="q-score">0</span></span>
      </div>
      <div class="q-bar"><div class="q-bar-fill" id="q-bar-fill"></div></div>
      <div class="q-question" id="q-text"></div>
      <div class="q-options" id="q-options"></div>
      <div class="q-explain" id="q-explain"></div>
      <div class="q-next-wrap" id="q-next-wrap">
        <button class="q-btn" id="btn-next">السؤال التالي ←</button>
      </div>
    </section>

    <!-- شاشة النتيجة -->
    <section id="screen-result" class="q-result" style="display:none;">
      <span class="q-trophy" id="q-trophy">🏆</span>
      <h2 id="q-result-title">أحسنت!</h2>
      <div>
        <span class="q-final-score"><span id="q-final-score">0</span></span>
        <span class="q-final-total">/ 20</span>
      </div>
      <div class="q-verdict" id="q-verdict"></div>
      <div class="q-actions">
        <button class="q-btn" id="btn-restart">🔁 العب من جديد</button>
        <a href="/" class="q-btn q-btn-ghost">الصفحة الرئيسية</a>
      </div>
    </section>
  </main>

  <footer class="q-footer">
    <p>صلّى الله عليه وسلّم — <a href="/about">عن <?php echo e($siteName); ?></a></p>
  </footer>
</div>

<script>
(function() {
  "use strict";

  // ٢٠ سؤالًا عن السيرة النبوية، بمستوى عمر ١٠+
  const QUESTIONS = [
    {
      q: "في أيّ مدينة وُلد النبي محمد ﷺ؟",
      options: ["المدينة المنورة", "مكة المكرمة", "الطائف", "القدس"],
      answer: 1,
      explain: "وُلد النبي ﷺ في مكة المكرمة في عام الفيل الموافق سنة ٥٧٠م تقريبًا."
    },
    {
      q: "ما اسم والد النبي ﷺ؟",
      options: ["أبو طالب", "عبد المطلب", "عبد الله", "الحارث"],
      answer: 2,
      explain: "والده هو عبد الله بن عبد المطلب، توفّي قبل ولادة النبي ﷺ."
    },
    {
      q: "ما اسم والدة النبي ﷺ؟",
      options: ["خديجة بنت خويلد", "آمنة بنت وهب", "حليمة السعدية", "فاطمة بنت أسد"],
      answer: 1,
      explain: "أمّه هي آمنة بنت وهب، وتوفّيت وعمر النبي ﷺ ست سنوات."
    },
    {
      q: "من هي المرأة التي أرضعت النبي ﷺ في البادية؟",
      options: ["آمنة بنت وهب", "ثويبة", "حليمة السعدية", "أم أيمن"],
      answer: 2,
      explain: "أرضعته حليمة السعدية من قبيلة بني سعد، وعاش عندها في البادية سنواته الأولى."
    },
    {
      q: "من كفل النبي ﷺ بعد وفاة جدّه عبد المطلب؟",
      options: ["حمزة", "أبو طالب", "العباس", "أبو لهب"],
      answer: 1,
      explain: "كفله عمّه أبو طالب، وكان يحبّه ويدافع عنه طوال حياته."
    },
    {
      q: "كم كان عمر النبي ﷺ حين نزل عليه الوحي؟",
      options: ["٣٠ سنة", "٣٥ سنة", "٤٠ سنة", "٥٠ سنة"],
      answer: 2,
      explain: "نزل عليه الوحي وعمره أربعون سنة، وهي سنّ اكتمال العقل والنضج."
    },
    {
      q: "ما اسم الغار الذي كان يتعبّد فيه النبي ﷺ قبل البعثة؟",
      options: ["غار ثور", "غار حراء", "غار الكهف", "غار طابا"],
      answer: 1,
      explain: "غار حراء في جبل النور قرب مكة، ونزل عليه فيه أوّل الوحي."
    },
    {
      q: "من هو المَلَك الذي نزل بالوحي على النبي ﷺ؟",
      options: ["ميكائيل", "إسرافيل", "جبريل", "عزرائيل"],
      answer: 2,
      explain: "جبريل عليه السلام هو المَلَك الموكل بالوحي إلى الأنبياء."
    },
    {
      q: "ما أوّل آيات نزلت من القرآن الكريم؟",
      options: ["بداية سورة الفاتحة", "بداية سورة العلق (اقرأ)", "بداية سورة المدثر", "آية الكرسي"],
      answer: 1,
      explain: "أوّل ما نزل: ﴿اقْرَأْ بِاسْمِ رَبِّكَ الَّذِي خَلَقَ﴾ من سورة العلق."
    },
    {
      q: "من هي أوّل زوجة للنبي ﷺ؟",
      options: ["عائشة بنت أبي بكر", "حفصة بنت عمر", "خديجة بنت خويلد", "سودة بنت زمعة"],
      answer: 2,
      explain: "خديجة بنت خويلد رضي الله عنها، وهي أوّل من آمن به من النساء."
    },
    {
      q: "من هو أوّل من آمن به من الرجال؟",
      options: ["أبو بكر الصديق", "عمر بن الخطاب", "عثمان بن عفان", "علي بن أبي طالب"],
      answer: 0,
      explain: "أبو بكر الصديق رضي الله عنه من الرجال، وعليّ بن أبي طالب من الصبيان، وخديجة من النساء."
    },
    {
      q: "من رافق النبي ﷺ في هجرته إلى المدينة؟",
      options: ["عمر بن الخطاب", "أبو بكر الصديق", "بلال بن رباح", "مصعب بن عمير"],
      answer: 1,
      explain: "رافقه أبو بكر الصديق رضي الله عنه، واختبآ في غار ثور ثلاث ليالٍ."
    },
    {
      q: "ما اسم المدينة التي هاجر إليها النبي ﷺ؟",
      options: ["الطائف", "يثرب (المدينة المنورة)", "خيبر", "الحبشة"],
      answer: 1,
      explain: "هاجر إلى يثرب وسمّاها النبي ﷺ «المدينة المنورة»."
    },
    {
      q: "ما اسم أوّل مسجد بناه النبي ﷺ بعد الهجرة؟",
      options: ["المسجد الحرام", "المسجد الأقصى", "مسجد قباء", "المسجد النبوي"],
      answer: 2,
      explain: "بنى مسجد قباء في طريقه إلى المدينة، وهو أوّل مسجد في الإسلام."
    },
    {
      q: "ما اسم أوّل غزوة كبرى انتصر فيها المسلمون؟",
      options: ["غزوة أُحد", "غزوة الخندق", "غزوة بدر", "غزوة تبوك"],
      answer: 2,
      explain: "غزوة بدر الكبرى وقعت في رمضان من السنة الثانية للهجرة، وانتصر فيها المسلمون رغم قلّة عددهم."
    },
    {
      q: "من هو مؤذّن النبي ﷺ المشهور؟",
      options: ["زيد بن حارثة", "بلال بن رباح", "أنس بن مالك", "سلمان الفارسي"],
      answer: 1,
      explain: "بلال بن رباح الحبشي رضي الله عنه، كان عبدًا فحرّره أبو بكر ثم صار مؤذّن النبي ﷺ."
    },
    {
      q: "من هي ابنة النبي ﷺ زوجة علي بن أبي طالب؟",
      options: ["زينب", "رقية", "أم كلثوم", "فاطمة"],
      answer: 3,
      explain: "فاطمة الزهراء رضي الله عنها، وأولادها الحسن والحسين سيّدا شباب أهل الجنة."
    },
    {
      q: "ما اسم ناقة النبي ﷺ التي ركبها في الهجرة؟",
      options: ["العَضْباء", "القَصْواء", "الجَدْعاء", "كلّ ما سبق من أسمائها"],
      answer: 3,
      explain: "القصواء والعضباء والجدعاء: ثلاثة أسماء لنفس الناقة الشريفة."
    },
    {
      q: "كم كان عمر النبي ﷺ حين توفّي؟",
      options: ["٥٠ سنة", "٦٠ سنة", "٦٣ سنة", "٧٠ سنة"],
      answer: 2,
      explain: "توفّي ﷺ وعمره ٦٣ سنة؛ ٤٠ قبل البعثة و٢٣ بعدها (١٣ في مكة و١٠ في المدينة)."
    },
    {
      q: "في أيّ مدينة دُفن النبي ﷺ؟",
      options: ["مكة المكرمة", "المدينة المنورة", "القدس", "الطائف"],
      answer: 1,
      explain: "دُفن ﷺ في حجرة عائشة رضي الله عنها في المدينة المنورة."
    }
  ];

  const $ = (id) => document.getElementById(id);
  let order = [];     // ترتيب عشوائي للأسئلة
  let idx = 0;        // السؤال الحالي
  let score = 0;      // النقاط
  let locked = false; // منع تكرار الضغط بعد الإجابة

  function shuffle(arr) {
    const a = arr.slice();
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
  }

  function show(id) {
    ["screen-intro","screen-quiz","screen-result"].forEach(s => {
      $(s).style.display = (s === id) ? "" : "none";
    });
  }

  function start() {
    order = shuffle(QUESTIONS.map((_,i)=>i));
    idx = 0; score = 0;
    $("q-total").textContent = order.length;
    show("screen-quiz");
    render();
  }

  function render() {
    locked = false;
    const q = QUESTIONS[order[idx]];
    $("q-current").textContent = idx + 1;
    $("q-score").textContent = score;
    $("q-bar-fill").style.width = ((idx) / order.length * 100) + "%";
    $("q-text").textContent = q.q;
    $("q-explain").classList.remove("q-show");
    $("q-explain").innerHTML = "";
    $("q-next-wrap").classList.remove("q-show");

    const letters = ["أ","ب","ج","د"];
    const opts = $("q-options");
    opts.innerHTML = "";
    q.options.forEach((text, i) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "q-option";
      btn.innerHTML = '<span class="q-letter">' + letters[i] + '</span><span>' + escapeHtml(text) + '</span>';
      btn.addEventListener("click", () => choose(btn, i, q));
      opts.appendChild(btn);
    });
  }

  function choose(btn, i, q) {
    if (locked) return;
    locked = true;
    const buttons = $("q-options").querySelectorAll(".q-option");
    buttons.forEach((b, bi) => {
      b.disabled = true;
      if (bi === q.answer) b.classList.add("q-correct");
    });
    if (i === q.answer) {
      score++;
      $("q-score").textContent = score;
      $("q-explain").innerHTML = "<strong>✅ إجابة صحيحة!</strong> " + escapeHtml(q.explain);
    } else {
      btn.classList.add("q-wrong");
      $("q-explain").innerHTML = "<strong>❌ إجابة خاطئة.</strong> " + escapeHtml(q.explain);
    }
    $("q-explain").classList.add("q-show");
    $("q-bar-fill").style.width = ((idx + 1) / order.length * 100) + "%";
    $("q-next-wrap").classList.add("q-show");
    $("btn-next").textContent = (idx + 1 >= order.length) ? "عرض النتيجة 🏆" : "السؤال التالي ←";
  }

  function next() {
    idx++;
    if (idx >= order.length) finish();
    else render();
  }

  function finish() {
    show("screen-result");
    $("q-final-score").textContent = score;
    const pct = score / QUESTIONS.length;
    let trophy = "🏆", title = "أحسنت!", verdict = "";
    if (pct === 1) {
      trophy = "🥇"; title = "ممتاز جدًا! درجة كاملة";
      verdict = "ما شاء الله! معلوماتك عن السيرة النبوية رائعة. استمرّ في القراءة عن حبيبنا محمد ﷺ.";
    } else if (pct >= 0.8) {
      trophy = "🏆"; title = "نتيجة رائعة!";
      verdict = "أحسنت! معلوماتك قويّة. راجع الأسئلة التي أخطأت فيها واطّلع على شرحها.";
    } else if (pct >= 0.6) {
      trophy = "⭐"; title = "نتيجة جيدة";
      verdict = "أداء جيد! عندك قاعدة معرفية حسنة عن السيرة. حاول قراءة كتاب مختصر في السيرة لتزيد من معلوماتك.";
    } else if (pct >= 0.4) {
      trophy = "📚"; title = "عندك فرصة للتعلّم أكثر";
      verdict = "لا بأس! السيرة النبوية كنز لا ينفد. ابدأ بقراءة كتاب «الرحيق المختوم» أو سيرة مختصرة للأطفال.";
    } else {
      trophy = "🌱"; title = "البداية دائمًا صعبة";
      verdict = "لا تحزن، كلّ عالِم كان يومًا مبتدئًا. جرّب مرة أخرى بعد قراءة بعض القصص عن النبي ﷺ وستلاحظ الفرق.";
    }
    $("q-trophy").textContent = trophy;
    $("q-result-title").textContent = title;
    $("q-verdict").textContent = verdict;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"
    }[c]));
  }

  $("btn-start").addEventListener("click", start);
  $("btn-next").addEventListener("click", next);
  $("btn-restart").addEventListener("click", start);
})();
</script>
</body>
</html>
