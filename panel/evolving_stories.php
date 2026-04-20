<?php
/**
 * نيوز فيد — لوحة الإدارة: إدارة القصص المتطوّرة
 *
 * CRUD interface for admin-defined "evolving stories". Unlike the
 * cluster-based auto timelines, these are persistent topics that the
 * editorial team owns: they choose the name, the keywords, and which
 * article matches feed into each story.
 *
 * Actions supported:
 *   - list                  → table of all stories
 *   - add / edit            → form (name, slug, keywords, …)
 *   - delete                → remove a story + its links
 *   - backfill   (POST)     → rescan last N days of articles for one story
 *   - unlink     (POST)     → manually detach an article from a story
 *
 * Role: editor+ can manage everything, same as categories.php.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/evolving_stories.php';
require_once __DIR__ . '/../includes/evolving_stories_ai.php';
requireRole('editor');

$db = getDB();
evolving_stories_ensure_tables();

$action  = $_GET['action'] ?? 'list';
$error   = '';
$success = '';
$story   = null;

// ------------------------------------------------------------------
// Delete
// ------------------------------------------------------------------
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        // Article links cascade via FK, but drop them explicitly in
        // case an older DB without FKs somehow landed here.
        $db->prepare("DELETE FROM evolving_story_articles WHERE story_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM evolving_stories WHERE id = ?")->execute([$id]);
        $success = 'تم حذف القصة بنجاح';
    } catch (PDOException $e) {
        $error = 'خطأ في حذف القصة';
    }
    $action = 'list';
}

// ------------------------------------------------------------------
// Unlink article (POST) — manual detach from the edit page
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'unlink') {
    $sid = (int)($_POST['story_id'] ?? 0);
    $aid = (int)($_POST['article_id'] ?? 0);
    if ($sid > 0 && $aid > 0) {
        evolving_story_unlink_article($sid, $aid);
        $success = 'تم فك ربط المقال من القصة';
    }
    header('Location: evolving_stories.php?action=edit&id=' . $sid);
    exit;
}

// ------------------------------------------------------------------
// AI Extract (POST) — pump pending articles through Claude Haiku to
// populate evolving_story_entities + evolving_story_quotes. Caps at a
// small budget so a manual click never burns the API quota.
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'ai_extract') {
    $sid    = (int)($_POST['story_id'] ?? 0);
    $budget = max(1, min(30, (int)($_POST['budget'] ?? 8)));
    if ($sid > 0) {
        $res = evolving_stories_ai_extract_story($sid, $budget);
        $success = 'تم الاستخراج — ' . (int)$res['processed'] . ' مقال · '
                 . (int)$res['entities'] . ' كيان · '
                 . (int)$res['quotes']   . ' اقتباس'
                 . ((int)$res['failed'] > 0 ? ' (' . (int)$res['failed'] . ' فشل)' : '');
    }
    header('Location: evolving_stories.php?action=edit&id=' . $sid . '&msg=' . urlencode($success));
    exit;
}

// ------------------------------------------------------------------
// Backfill (POST) — rescan last 30 days and link matches
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'backfill') {
    $sid  = (int)($_POST['story_id'] ?? 0);
    $days = max(1, min(180, (int)($_POST['days'] ?? 30)));
    if ($sid > 0) {
        $added = evolving_story_backfill($sid, $days);
        $success = 'تم المسح: ' . $added . ' مقال جديد ارتبط بالقصة';
    }
    header('Location: evolving_stories.php?action=edit&id=' . $sid . '&msg=' . urlencode($success));
    exit;
}

// ------------------------------------------------------------------
// Save (add / edit)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['op'])) {
    $id              = isset($_POST['id']) && $_POST['id'] ? (int)$_POST['id'] : null;
    $name            = trim($_POST['name'] ?? '');
    $slug            = trim($_POST['slug'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $icon            = trim($_POST['icon'] ?? '');
    $coverImage      = trim($_POST['cover_image'] ?? '');
    $accentColor     = trim($_POST['accent_color'] ?? '#0d9488');
    $minScore        = max(1, (int)($_POST['min_match_score'] ?? 1));
    $sortOrder       = (int)($_POST['sort_order'] ?? 0);
    $isActive        = isset($_POST['is_active']) ? 1 : 0;

    // Keywords arrive as one keyword-per-line in a textarea; split,
    // trim, drop empties, drop duplicates.
    $keywordsRaw = (string)($_POST['keywords'] ?? '');
    $keywords = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $keywordsRaw)))));

    $excludesRaw = (string)($_POST['exclude_keywords'] ?? '');
    $excludes = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $excludesRaw)))));

    // Slug fallback: if the admin didn't type one, slugify the name
    // into an ASCII-ish form. Keep Arabic letters too so we never
    // produce an empty slug for a pure-Arabic name.
    if ($slug === '') {
        $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\s-]/u', '', $name);
        $slug = preg_replace('/\s+/u', '-', trim($slug));
        $slug = mb_strtolower($slug);
    }

    if ($name === '' || $slug === '' || empty($keywords)) {
        $error = 'الاسم والرابط الودود والكلمات المفتاحية مطلوبة';
        $action = $id ? 'edit' : 'add';
        $story = [
            'id' => $id, 'name' => $name, 'slug' => $slug,
            'description' => $description, 'icon' => $icon,
            'cover_image' => $coverImage, 'accent_color' => $accentColor,
            'keywords' => $keywords, 'exclude_keywords' => $excludes,
            'min_match_score' => $minScore, 'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];
    } else {
        try {
            $keywordsJson = json_encode($keywords, JSON_UNESCAPED_UNICODE);
            $excludesJson = json_encode($excludes, JSON_UNESCAPED_UNICODE);

            if ($id) {
                $stmt = $db->prepare("UPDATE evolving_stories
                    SET name=?, slug=?, description=?, icon=?, cover_image=?,
                        accent_color=?, keywords=?, exclude_keywords=?,
                        min_match_score=?, sort_order=?, is_active=?
                    WHERE id=?");
                $stmt->execute([
                    $name, $slug, $description, $icon, $coverImage,
                    $accentColor, $keywordsJson, $excludesJson,
                    $minScore, $sortOrder, $isActive, $id
                ]);
                $success = 'تم تحديث القصة بنجاح';
            } else {
                $stmt = $db->prepare("INSERT INTO evolving_stories
                    (name, slug, description, icon, cover_image, accent_color,
                     keywords, exclude_keywords, min_match_score, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $name, $slug, $description, $icon, $coverImage,
                    $accentColor, $keywordsJson, $excludesJson,
                    $minScore, $sortOrder, $isActive
                ]);
                $id = (int)$db->lastInsertId();
                $success = 'تم إضافة القصة — جارٍ مسح آخر 30 يوم تلقائياً…';
                // Instant backfill so the editor sees it populated.
                if ($isActive) {
                    $added = evolving_story_backfill($id, 30);
                    $success = 'تم إضافة القصة وربط ' . $added . ' مقال من آخر 30 يوم';
                }
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ في حفظ القصة: ' . $e->getMessage();
            $action = $id ? 'edit' : 'add';
        }
    }
}

// ------------------------------------------------------------------
// Load for edit / delete confirmation
// ------------------------------------------------------------------
if (in_array($action, ['edit', 'delete'], true) && isset($_GET['id']) && !$story) {
    $id = (int)$_GET['id'];
    $story = evolving_story_get_by_id($id);
    if (!$story && $action === 'edit') {
        $error = 'القصة غير موجودة';
        $action = 'list';
    }
}

if (isset($_GET['msg'])) {
    $success = $success ?: (string)$_GET['msg'];
}

// For the edit screen we also fetch the latest 40 linked articles so
// the admin can preview and unlink false positives.
$linkedArticles = [];
if ($action === 'edit' && $story) {
    $linkedArticles = evolving_story_articles((int)$story['id'], 40);
}

// Listing
$stories = evolving_stories_list(false);

$pageTitle = ($action === 'add' ? 'إضافة قصة متطوّرة' : ($action === 'edit' ? 'تعديل قصة' : 'القصص المتطوّرة')) . ' - نيوز فيد';
$activePage = 'evolving_stories';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'], true)): ?>
        <div class="page-header">
            <div>
                <h2><?php echo $action === 'add' ? 'إضافة قصة متطوّرة' : 'تعديل القصة'; ?></h2>
                <p>قصة دائمة يتغذّى عليها النظام تلقائياً من مطابقة الكلمات المفتاحية.</p>
            </div>
            <div class="page-actions">
                <a href="evolving_stories.php" class="btn-outline">رجوع</a>
            </div>
        </div>

        <div class="form-card">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <?php if ($story && !empty($story['id'])): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$story['id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="name">اسم القصة *</label>
                        <input type="text" id="name" name="name" class="form-control" required
                               value="<?php echo $story ? e($story['name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="slug">الرابط الودود (slug) *</label>
                        <input type="text" id="slug" name="slug" class="form-control" required
                               placeholder="al-aqsa"
                               value="<?php echo $story ? e($story['slug']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">وصف مختصر</label>
                    <textarea id="description" name="description" class="form-control" rows="2"
                              placeholder="سطر أو سطران لشرح ما تغطّيه هذه القصة."><?php echo $story ? e($story['description']) : ''; ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="icon">الأيقونة (إيموجي)</label>
                        <input type="text" id="icon" name="icon" class="form-control" maxlength="10"
                               placeholder="🕌"
                               value="<?php echo $story ? e($story['icon']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="accent_color">لون القصة</label>
                        <input type="color" id="accent_color" name="accent_color" class="form-control"
                               value="<?php echo $story ? e($story['accent_color']) : '#0d9488'; ?>">
                    </div>
                    <div class="form-group">
                        <label for="sort_order">ترتيب العرض</label>
                        <input type="number" id="sort_order" name="sort_order" class="form-control" min="0"
                               value="<?php echo $story ? (int)$story['sort_order'] : 0; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="cover_image">رابط صورة الغلاف (اختياري)</label>
                    <input type="url" id="cover_image" name="cover_image" class="form-control"
                           placeholder="https://..."
                           value="<?php echo $story ? e($story['cover_image']) : ''; ?>">
                    <small style="color:var(--text-muted);font-size:12px;">إذا تُرك فارغاً، ستُستخدم صورة آخر مقال في القصة.</small>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label for="keywords">الكلمات المفتاحية *</label>
                        <textarea id="keywords" name="keywords" class="form-control" rows="7"
                                  placeholder="كلمة في كل سطر&#10;مثل:&#10;الأقصى&#10;المسجد الأقصى&#10;باب العمود"
                                  required><?php
                            if ($story) echo e(implode("\n", $story['keywords'] ?? []));
                        ?></textarea>
                        <small style="color:var(--text-muted);font-size:12px;">
                            كلمة واحدة في كل سطر. يتم مطابقتها ضد عنوان ومختصر المقال (غير حسّاسة لحالة الأحرف).
                        </small>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label for="exclude_keywords">كلمات الاستبعاد (اختياري)</label>
                        <textarea id="exclude_keywords" name="exclude_keywords" class="form-control" rows="7"
                                  placeholder="كلمة في كل سطر&#10;أي مقال يحتوي على أيٍّ منها يُستبعد"><?php
                            if ($story) echo e(implode("\n", $story['exclude_keywords'] ?? []));
                        ?></textarea>
                        <small style="color:var(--text-muted);font-size:12px;">
                            أي مقال يحتوي على إحدى هذه الكلمات يُستبعد من القصة حتى لو طابق الكلمات الإيجابية.
                        </small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="min_match_score">الحد الأدنى لعدد المطابقات</label>
                        <input type="number" id="min_match_score" name="min_match_score" class="form-control" min="1" max="10"
                               value="<?php echo $story ? (int)$story['min_match_score'] : 1; ?>">
                        <small style="color:var(--text-muted);font-size:12px;">
                            ارفعه لتقليل الضوضاء (مثلاً 2 = لازم كلمتان مختلفتان على الأقل).
                        </small>
                    </div>
                    <div class="form-group">
                        <label>الحالة</label>
                        <div class="checkbox-item" style="padding-top:8px;">
                            <input type="checkbox" id="is_active" name="is_active" <?php echo (!$story || !empty($story['is_active'])) ? 'checked' : ''; ?>>
                            <label for="is_active">فعّال — يظهر للجمهور ويُغذّى تلقائياً</label>
                        </div>
                    </div>
                </div>

                <div class="page-actions">
                    <button type="submit" class="btn-primary">حفظ</button>
                    <a href="evolving_stories.php" class="btn-outline">إلغاء</a>
                </div>
            </form>
        </div>

        <?php if ($action === 'edit' && $story): ?>
            <div class="form-card" style="margin-top:20px;">
                <h3 style="margin-bottom:12px;">🔄 إعادة مسح الأرشيف</h3>
                <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px;">
                    عدّلت الكلمات؟ اضغط "ابدأ المسح" لإعادة مطابقة كل المقالات المنشورة خلال الفترة المختارة.
                </p>
                <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="op" value="backfill">
                    <input type="hidden" name="story_id" value="<?php echo (int)$story['id']; ?>">
                    <div class="form-group" style="margin:0;">
                        <label for="days">عدد الأيام</label>
                        <input type="number" id="days" name="days" class="form-control" min="1" max="180" value="30" style="width:120px;">
                    </div>
                    <button type="submit" class="btn-primary">ابدأ المسح</button>
                </form>
            </div>

            <?php
                // Show a live counter of how many linked articles have NOT
                // yet been through the AI extractor, so the editor can
                // decide whether it's worth clicking.
                $aiPending = count(evolving_stories_ai_pending((int)$story['id'], 500));
                $aiDash    = evolving_stories_ai_dashboard((int)$story['id'], 1);
            ?>
            <div class="form-card" style="margin-top:20px;">
                <h3 style="margin-bottom:12px;">🧠 استخراج AI (كيانات + اقتباسات)</h3>
                <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px;">
                    يمرّر آخر المقالات إلى Claude Haiku لاستخراج الأشخاص والأماكن والمنظمات والاقتباسات المباشرة.
                    تظهر نتائج هذا الاستخراج في لوحة "القصة بالأرقام" وصفحة "جدار الاقتباسات".
                </p>
                <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--text-muted);margin-bottom:14px;">
                    <span>⏳ <b><?php echo number_format($aiPending); ?></b> مقال في قائمة الانتظار</span>
                    <span>🏷️ <b><?php echo number_format((int)$aiDash['totals']['entities']); ?></b> كيان مخزّن</span>
                    <span>💬 <b><?php echo number_format((int)$aiDash['totals']['quotes']); ?></b> اقتباس مخزّن</span>
                </div>
                <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="op" value="ai_extract">
                    <input type="hidden" name="story_id" value="<?php echo (int)$story['id']; ?>">
                    <div class="form-group" style="margin:0;">
                        <label for="budget">عدد المقالات (هذه المرّة)</label>
                        <input type="number" id="budget" name="budget" class="form-control" min="1" max="30" value="8" style="width:120px;">
                    </div>
                    <button type="submit" class="btn-primary"<?php echo $aiPending === 0 ? ' disabled title="لا يوجد مقالات جديدة للاستخراج"' : ''; ?>>
                        🧠 استخرج الآن
                    </button>
                </form>
                <small style="display:block;margin-top:10px;color:var(--text-muted);font-size:11.5px;">
                    cron_evolving_ai.php يُشغَّل تلقائياً أيضاً — استخدم هذا الزر فقط عندما تحتاج نتيجة فورية.
                </small>
            </div>

            <div class="form-card" style="margin-top:20px;">
                <h3 style="margin-bottom:12px;">📰 آخر المقالات المرتبطة (<?php echo count($linkedArticles); ?>)</h3>
                <?php if (empty($linkedArticles)): ?>
                    <p style="color:var(--text-muted);">لا توجد مقالات مرتبطة بعد. اضغط "ابدأ المسح" أعلاه أو انتظر تشغيل cron التالي.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>العنوان</th>
                                <th>المصدر</th>
                                <th>التاريخ</th>
                                <th>الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linkedArticles as $a): ?>
                                <tr>
                                    <td style="max-width:400px;"><?php echo e(mb_substr($a['title'] ?? '', 0, 120)); ?></td>
                                    <td><?php echo e($a['source_name'] ?? '—'); ?></td>
                                    <td><?php echo !empty($a['published_at']) ? e(date('Y-m-d H:i', strtotime($a['published_at']))) : '—'; ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('فك ربط هذا المقال من القصة؟');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="op" value="unlink">
                                            <input type="hidden" name="story_id" value="<?php echo (int)$story['id']; ?>">
                                            <input type="hidden" name="article_id" value="<?php echo (int)$a['id']; ?>">
                                            <button type="submit" class="btn-danger" style="padding:4px 10px;font-size:12px;">فك</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="page-header">
            <div>
                <h2>📅 القصص المتطوّرة</h2>
                <p>مواضيع دائمة يُغذّيها النظام تلقائياً من الأخبار الواردة.</p>
            </div>
            <div class="page-actions">
                <a href="evolving_stories.php?action=add" class="btn-primary">+ إضافة قصة</a>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>&nbsp;</th>
                        <th>الاسم</th>
                        <th>الكلمات</th>
                        <th>المقالات</th>
                        <th>آخر مطابقة</th>
                        <th>الترتيب</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($stories)): ?>
                        <?php foreach ($stories as $st): ?>
                            <tr>
                                <td><span style="font-size:24px;"><?php echo e($st['icon'] ?: '📅'); ?></span></td>
                                <td>
                                    <strong><?php echo e($st['name']); ?></strong><br>
                                    <small style="color:var(--text-muted);"><?php echo e($st['slug']); ?></small>
                                </td>
                                <td><?php echo count($st['keywords']); ?></td>
                                <td>
                                    <span class="badge" style="background:<?php echo e($st['accent_color']); ?>;color:#fff;">
                                        <?php echo number_format($st['article_count']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($st['last_matched_at']) && $st['last_matched_at'] !== '0000-00-00 00:00:00'): ?>
                                        <small><?php echo e(date('Y-m-d H:i', strtotime($st['last_matched_at']))); ?></small>
                                    <?php else: ?>
                                        <small style="color:var(--text-muted);">—</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$st['sort_order']; ?></td>
                                <td>
                                    <?php if ($st['is_active']): ?>
                                        <span class="badge badge-success">فعّال</span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">معطّل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="evolving_stories.php?action=edit&id=<?php echo (int)$st['id']; ?>" class="action-btn">تعديل</a>
                                    <a href="/evolving-story/<?php echo e($st['slug']); ?>" target="_blank" class="action-btn">معاينة</a>
                                    <a href="evolving_stories.php?action=delete&id=<?php echo (int)$st['id']; ?>" class="btn-danger" onclick="return confirm('هل تريد حذف هذه القصة مع كل الروابط؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:40px;">
                            لا توجد قصص متطوّرة بعد. ابدأ بإضافة قصة.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
