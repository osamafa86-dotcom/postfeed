<?php
/**
 * نيوزفلو - TTS settings (cloud text-to-speech)
 * ==============================================
 * Lets admins:
 *   - turn cloud TTS on/off (off = browser Web Speech fallback)
 *   - pick provider (ElevenLabs / Google / OpenAI)
 *   - save API keys and voice IDs per provider
 *   - generate a test clip against the current settings
 *   - see cache stats and clear cached MP3s
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tts.php';
requireAdmin();

$db = getDB();
$success = '';
$error   = '';
$testAudioUrl = '';

// requireAdmin() above already rejects POSTs with a bad/missing CSRF
// token, so the handlers below can trust $_POST.
// ---------------------------------------------------------------------
// Save settings
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $fields = [
        'tts_enabled'           => isset($_POST['tts_enabled']) ? '1' : '0',
        'tts_provider'          => in_array($_POST['tts_provider'] ?? '', ['elevenlabs','google','openai'], true) ? $_POST['tts_provider'] : 'google',
        'tts_voice_elevenlabs'  => trim((string)($_POST['tts_voice_elevenlabs'] ?? '')),
        'tts_voice_google'      => trim((string)($_POST['tts_voice_google'] ?? '')),
        'tts_voice_openai'      => trim((string)($_POST['tts_voice_openai'] ?? '')),
        'tts_elevenlabs_key'    => trim((string)($_POST['tts_elevenlabs_key'] ?? '')),
        'tts_elevenlabs_model'  => trim((string)($_POST['tts_elevenlabs_model'] ?? '')),
        'tts_google_key'        => trim((string)($_POST['tts_google_key'] ?? '')),
        'tts_openai_key'        => trim((string)($_POST['tts_openai_key'] ?? '')),
        'tts_openai_model'      => trim((string)($_POST['tts_openai_model'] ?? '')),
    ];

    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value)
                          VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($fields as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    // Same cache-busting ritual as panel/ai.php — getSetting() memoizes
    // the whole table under 'settings_all' so without this the new
    // values would be invisible to tts.php until the TTL expires.
    cache_forget('settings_all');
    $success = 'تم حفظ الإعدادات';
}

// ---------------------------------------------------------------------
// Clear cache
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cache'])) {
    $n = tts_cache_clear();
    $success = 'تم حذف ' . $n . ' ملف صوتي من الذاكرة المؤقتة';
}

// ---------------------------------------------------------------------
// Test generate — uses the current saved settings. We don't pull an
// article out of the DB because the admin usually just wants to hear
// the voice on a short sample sentence.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_generate'])) {
    if (!tts_is_enabled()) {
        $error = 'الخدمة معطّلة — فعّلها أولاً ثم احفظ ثم جرّب الاختبار.';
    } else {
        $sample = trim((string)($_POST['test_text'] ?? ''));
        if ($sample === '') {
            $sample = 'مرحباً، هذه تجربة صوتية لنظام نيوزفلو. الخبر التالي من آخر التحديثات.';
        }
        // Fake article shape so tts_build_text + tts_get_or_generate
        // work without changes. We use ai_summary as the body so
        // tts_build_text picks it up directly.
        $fakeArticle = [
            'title'      => 'اختبار صوت نيوزفلو',
            'ai_summary' => $sample,
        ];
        try {
            $result = tts_get_or_generate($fakeArticle);
        } catch (Throwable $e) {
            $result = null;
            $error  = 'فشل الاتصال بمزوّد الصوت: ' . $e->getMessage();
        }
        if ($result && is_file($result['path'])) {
            // Serve the generated clip inline via a data: URL so we
            // don't need a second endpoint just for the admin preview.
            $mp3  = @file_get_contents($result['path']);
            if ($mp3 !== false) {
                $testAudioUrl = 'data:audio/mpeg;base64,' . base64_encode($mp3);
                $success = 'تم توليد المقطع الصوتي بنجاح (' . number_format($result['bytes']) . ' بايت، '
                         . ($result['cached'] ? 'من الذاكرة المؤقتة' : 'طلب جديد من ' . $result['provider']) . ')';
            }
        } elseif (!$error) {
            $error = 'فشل توليد الصوت — تحقّق من مفتاح API ومعرف الصوت في السجلات.';
        }
    }
}

// ---------------------------------------------------------------------
// Read current settings
// ---------------------------------------------------------------------
$vals = [
    'tts_enabled'          => (int)getSetting('tts_enabled', '0') === 1,
    'tts_provider'         => tts_provider(),
    'tts_voice_elevenlabs' => (string)getSetting('tts_voice_elevenlabs', ''),
    'tts_voice_google'     => (string)getSetting('tts_voice_google', ''),
    'tts_voice_openai'     => (string)getSetting('tts_voice_openai', ''),
    'tts_elevenlabs_key'   => (string)getSetting('tts_elevenlabs_key', ''),
    'tts_elevenlabs_model' => (string)getSetting('tts_elevenlabs_model', 'eleven_multilingual_v2'),
    'tts_google_key'       => (string)getSetting('tts_google_key', ''),
    'tts_openai_key'       => (string)getSetting('tts_openai_key', ''),
    'tts_openai_model'     => (string)getSetting('tts_openai_model', 'tts-1-hd'),
];

$stats = tts_cache_stats();
$mbCached = $stats['bytes'] > 0 ? number_format($stats['bytes'] / 1048576, 2) : '0.00';

$pageTitle  = 'الصوت والقراءة - نيوزفلو';
$activePage = 'tts';
include __DIR__ . '/includes/panel_layout_head.php';
?>
<div class="content">
  <div class="page-header">
    <div>
      <h2>🎙 الصوت والقراءة (Text-to-Speech)</h2>
      <p>قراءة المقالات بأصوات عالية الجودة عبر ElevenLabs / Google / OpenAI — مع تخزين مؤقت لكل مقطع.</p>
    </div>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:0 0 20px;">
    <div class="card" style="padding:20px;text-align:center;">
      <div style="font-size:30px;font-weight:900;color:<?php echo $vals['tts_enabled'] ? '#16a34a' : '#94a3b8'; ?>;">
        <?php echo $vals['tts_enabled'] ? 'مُفعّل' : 'معطّل'; ?>
      </div>
      <div style="color:var(--text-muted);font-size:13px;">حالة الخدمة</div>
    </div>
    <div class="card" style="padding:20px;text-align:center;">
      <div style="font-size:30px;font-weight:900;color:var(--primary);"><?php echo (int)$stats['count']; ?></div>
      <div style="color:var(--text-muted);font-size:13px;">مقاطع محفوظة</div>
    </div>
    <div class="card" style="padding:20px;text-align:center;">
      <div style="font-size:30px;font-weight:900;color:#ea580c;"><?php echo e($mbCached); ?> MB</div>
      <div style="color:var(--text-muted);font-size:13px;">حجم الذاكرة المؤقتة</div>
    </div>
  </div>

  <form method="POST" class="form-card">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="save_settings" value="1">

    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">⚙️ الإعدادات العامة</h3>

    <div class="form-group">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
        <input type="checkbox" name="tts_enabled" value="1" <?php echo $vals['tts_enabled'] ? 'checked' : ''; ?> style="width:18px;height:18px;">
        <span>تفعيل الصوت السحابي (عند الإطفاء يعود الموقع للصوت المدمج في المتصفح)</span>
      </label>
    </div>

    <div class="form-group">
      <label>مزوّد الخدمة</label>
      <select name="tts_provider" class="form-control" id="ttsProviderSelect">
        <option value="google"     <?php echo $vals['tts_provider']==='google'?'selected':''; ?>>Google Cloud TTS (أرخص، جودة عالية)</option>
        <option value="elevenlabs" <?php echo $vals['tts_provider']==='elevenlabs'?'selected':''; ?>>ElevenLabs (أفضل جودة، أغلى)</option>
        <option value="openai"     <?php echo $vals['tts_provider']==='openai'?'selected':''; ?>>OpenAI TTS (متوسط)</option>
      </select>
      <small style="color:var(--text-muted);font-size:11px;">
        التوصية: ابدأ بـ Google (ar-XA-Wavenet-B) للتكلفة المنخفضة، ثم جرّب ElevenLabs لأفضل نطق عربي.
      </small>
    </div>

    <hr style="margin:24px 0;border:none;border-top:1px solid var(--border);">

    <!-- Google -->
    <h3 style="font-size:15px;font-weight:700;margin-bottom:14px;">🟢 Google Cloud TTS</h3>
    <div class="form-group">
      <label>مفتاح API (Google Cloud)</label>
      <input type="password" name="tts_google_key" class="form-control" value="<?php echo e($vals['tts_google_key']); ?>" placeholder="AIza...">
      <small style="color:var(--text-muted);font-size:11px;">
        احصل عليه من <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> — فعّل "Cloud Text-to-Speech API" أولاً.
      </small>
    </div>
    <div class="form-group">
      <label>اسم الصوت</label>
      <input type="text" name="tts_voice_google" class="form-control" value="<?php echo e($vals['tts_voice_google']); ?>" placeholder="ar-XA-Wavenet-B">
      <small style="color:var(--text-muted);font-size:11px;">
        أمثلة: <code>ar-XA-Wavenet-A</code> (أنثى) · <code>ar-XA-Wavenet-B</code> (ذكر) · <code>ar-XA-Wavenet-C</code> (ذكر) · <code>ar-XA-Wavenet-D</code> (أنثى)
      </small>
    </div>

    <hr style="margin:24px 0;border:none;border-top:1px solid var(--border);">

    <!-- ElevenLabs -->
    <h3 style="font-size:15px;font-weight:700;margin-bottom:14px;">🎯 ElevenLabs</h3>
    <div class="form-group">
      <label>مفتاح API (ElevenLabs)</label>
      <input type="password" name="tts_elevenlabs_key" class="form-control" value="<?php echo e($vals['tts_elevenlabs_key']); ?>" placeholder="xi-...">
      <small style="color:var(--text-muted);font-size:11px;">
        احصل عليه من <a href="https://elevenlabs.io/app/settings/api-keys" target="_blank">elevenlabs.io</a>
      </small>
    </div>
    <div class="form-group">
      <label>معرّف الصوت (Voice ID)</label>
      <input type="text" name="tts_voice_elevenlabs" class="form-control" value="<?php echo e($vals['tts_voice_elevenlabs']); ?>" placeholder="21m00Tcm4TlvDq8ikWAM">
      <small style="color:var(--text-muted);font-size:11px;">
        اتركه فارغاً لاستخدام الصوت الافتراضي (Rachel). للحصول على أصوات عربية مخصصة، استخدم Voice Library.
      </small>
    </div>
    <div class="form-group">
      <label>النموذج (Model)</label>
      <input type="text" name="tts_elevenlabs_model" class="form-control" value="<?php echo e($vals['tts_elevenlabs_model']); ?>" placeholder="eleven_multilingual_v2">
      <small style="color:var(--text-muted);font-size:11px;">
        <code>eleven_multilingual_v2</code> يدعم العربية جيداً. <code>eleven_turbo_v2_5</code> أسرع وأرخص.
      </small>
    </div>

    <hr style="margin:24px 0;border:none;border-top:1px solid var(--border);">

    <!-- OpenAI -->
    <h3 style="font-size:15px;font-weight:700;margin-bottom:14px;">🤖 OpenAI TTS</h3>
    <div class="form-group">
      <label>مفتاح API (OpenAI)</label>
      <input type="password" name="tts_openai_key" class="form-control" value="<?php echo e($vals['tts_openai_key']); ?>" placeholder="sk-...">
    </div>
    <div class="form-group">
      <label>اسم الصوت</label>
      <input type="text" name="tts_voice_openai" class="form-control" value="<?php echo e($vals['tts_voice_openai']); ?>" placeholder="alloy">
      <small style="color:var(--text-muted);font-size:11px;">
        أسماء الأصوات: <code>alloy</code> · <code>echo</code> · <code>fable</code> · <code>onyx</code> · <code>nova</code> · <code>shimmer</code>
      </small>
    </div>
    <div class="form-group">
      <label>النموذج</label>
      <input type="text" name="tts_openai_model" class="form-control" value="<?php echo e($vals['tts_openai_model']); ?>" placeholder="tts-1-hd">
      <small style="color:var(--text-muted);font-size:11px;">
        <code>tts-1</code> أسرع وأرخص، <code>tts-1-hd</code> جودة أعلى.
      </small>
    </div>

    <button type="submit" class="btn-primary">💾 حفظ الإعدادات</button>
  </form>

  <!-- Test -->
  <div class="form-card" style="margin-top:18px;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">🧪 اختبار المزوّد الحالي</h3>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="test_generate" value="1">
      <div class="form-group">
        <label>نص التجربة</label>
        <textarea name="test_text" class="form-control" rows="3" placeholder="مرحباً، هذه تجربة صوتية لنظام نيوزفلو."></textarea>
        <small style="color:var(--text-muted);font-size:11px;">
          اترك الحقل فارغاً للاستماع إلى جملة افتراضية. هذا المقطع يُحفظ في الذاكرة المؤقتة لتفادي التكلفة المزدوجة.
        </small>
      </div>
      <button type="submit" class="btn-primary">🔊 توليد + استماع</button>
    </form>

    <?php if ($testAudioUrl): ?>
      <div style="margin-top:16px;padding:14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;">
        <audio controls style="width:100%;" src="<?php echo e($testAudioUrl); ?>"></audio>
      </div>
    <?php endif; ?>
  </div>

  <!-- Cache management -->
  <div class="form-card" style="margin-top:18px;">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">🗑 إدارة الذاكرة المؤقتة</h3>
    <p style="color:var(--text-muted);font-size:13px;margin-bottom:14px;">
      كل مقالة يتم توليد صوتها مرة واحدة فقط ثم تُخزَّن هنا. حذف الذاكرة المؤقتة سيجبر النظام على إعادة توليد الصوت في الطلب التالي لكل مقالة (ويُعاد شحن مزوّد الخدمة).
    </p>
    <form method="POST" onsubmit="return confirm('حذف جميع المقاطع الصوتية المخزّنة؟ سيتم إعادة توليدها تلقائياً عند الطلب التالي.');">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="clear_cache" value="1">
      <button type="submit" class="btn-outline" style="color:#dc2626;border-color:#dc2626;">🗑 حذف الذاكرة المؤقتة</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
