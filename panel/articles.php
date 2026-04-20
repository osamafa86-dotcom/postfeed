<?php
/**
 * نيوز فيد - محرر الأخبار الاحترافي v2
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('editor');

$db = getDB();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$article = null;

// معالجة الحذف
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $t = $db->prepare("SELECT title FROM articles WHERE id = ?");
        $t->execute([$id]);
        $delTitle = (string)$t->fetchColumn();
        $stmt = $db->prepare("DELETE FROM articles WHERE id = ?");
        $stmt->execute([$id]);
        audit_log('article.delete', 'article', $id, ['title' => $delTitle]);
        $success = 'تم حذف الخبر بنجاح';
        $action = 'list';
    } catch (PDOException $e) {
        $error = 'خطأ في حذف الخبر';
    }
}

// معالجة الإضافة والتعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && $_POST['id'] ? (int)$_POST['id'] : null;
    $title = trim($_POST['title'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $content = $_POST['content'] ?? '';
    $image_url = trim($_POST['image_url'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $source_id = !empty($_POST['source_id']) ? (int)$_POST['source_id'] : null;
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_breaking = isset($_POST['is_breaking']) ? 1 : 0;
    $is_hero = isset($_POST['is_hero']) ? 1 : 0;
    $status = $_POST['status'] ?? 'draft';

    if (empty($title) || empty(trim(strip_tags($content)))) {
        $error = 'العنوان والمحتوى مطلوبان';
    } else {
        try {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE articles SET
                    title = ?, excerpt = ?, content = ?, image_url = ?,
                    category_id = ?, source_id = ?, is_featured = ?,
                    is_breaking = ?, is_hero = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $excerpt, $content, $image_url,
                    $category_id, $source_id, $is_featured,
                    $is_breaking, $is_hero, $status, $id
                ]);
                audit_log('article.update', 'article', $id, ['title' => $title, 'status' => $status]);
                $success = 'تم تحديث الخبر بنجاح';
            } else {
                $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\s-]/u', '', $title);
                $slug = preg_replace('/[\s]+/', '-', trim($slug));
                $slug = mb_substr($slug, 0, 200) . '-' . time();
                $stmt = $db->prepare("
                    INSERT INTO articles
                    (title, slug, excerpt, content, image_url, category_id, source_id,
                     is_featured, is_breaking, is_hero, status, published_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $title, $slug, $excerpt, $content, $image_url,
                    $category_id, $source_id, $is_featured,
                    $is_breaking, $is_hero, $status
                ]);
                $id = $db->lastInsertId();
                audit_log('article.create', 'article', $id, ['title' => $title, 'status' => $status]);
                $success = 'تم إضافة الخبر بنجاح';
            }
            $action = 'list';
        } catch (PDOException $e) {
            $error = 'خطأ في حفظ الخبر: ' . $e->getMessage();
        }
    }
}

// جلب الأخبار للتعديل
if (in_array($action, ['edit', 'delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if (!$article && $action === 'edit') {
        $error = 'الخبر غير موجود';
        $action = 'list';
    }
}

$categories = $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM sources WHERE is_active = 1 ORDER BY name")->fetchAll();

$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;

$statusFilter = $_GET['status'] ?? '';
$catFilter = $_GET['cat'] ?? '';
$searchFilter = trim($_GET['q'] ?? '');

$where = '1=1';
$params = [];
if ($statusFilter === 'published' || $statusFilter === 'draft') {
    $where .= ' AND a.status = ?';
    $params[] = $statusFilter;
}
if ($catFilter) {
    $where .= ' AND a.category_id = ?';
    $params[] = (int)$catFilter;
}
if ($searchFilter) {
    $where .= ' AND a.title LIKE ?';
    $params[] = '%' . $searchFilter . '%';
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM articles a WHERE $where");
$countStmt->execute($params);
$totalArticles = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalArticles / $perPage);

$params[] = $perPage;
$params[] = $offset;
$articles = $db->prepare("
    SELECT a.*, c.name as cat_name, s.name as source_name
    FROM articles a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN sources s ON a.source_id = s.id
    WHERE $where
    ORDER BY a.created_at DESC LIMIT ? OFFSET ?
");
$articles->execute($params);
$articlesList = $articles->fetchAll();

$pageTitle = ($action === 'add' ? 'إضافة خبر' : ($action === 'edit' ? 'تعديل خبر' : 'إدارة الأخبار')) . ' - نيوز فيد';
$activePage = 'articles';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<style>
  /* ===== Professional Editor v2 ===== */
  .editor-layout {
    display:grid; grid-template-columns:1fr 320px; gap:20px;
    align-items:start;
  }
  @media(max-width:1100px) { .editor-layout { grid-template-columns:1fr; } }

  .editor-main { display:flex; flex-direction:column; gap:16px; }

  .editor-title-wrap {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius-lg); padding:20px 24px;
    box-shadow:var(--shadow);
  }
  .editor-title-wrap input {
    width:100%; border:none; outline:none; background:none;
    font-family:'Tajawal',sans-serif; font-size:22px; font-weight:800;
    color:var(--text-primary); direction:rtl;
  }
  .editor-title-wrap input::placeholder { color:var(--text-muted); }

  .editor-content-wrap {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius-lg); overflow:hidden;
    box-shadow:var(--shadow);
  }

  /* Quill overrides */
  .ql-toolbar.ql-snow {
    border:none !important; border-bottom:1px solid var(--border) !important;
    padding:10px 16px !important;
    background:var(--bg-hover);
    font-family:'Tajawal',sans-serif !important;
  }
  .ql-container.ql-snow {
    border:none !important;
    font-family:'Tajawal',sans-serif !important;
    font-size:15px;
    direction:rtl;
  }
  .ql-editor {
    min-height:420px; padding:24px !important;
    line-height:1.9; color:var(--text-primary);
  }
  .ql-editor.ql-blank::before {
    font-style:normal !important;
    color:var(--text-muted) !important;
    right:24px !important; left:auto !important;
  }
  .ql-editor h1 { font-size:24px !important; font-weight:800 !important; }
  .ql-editor h2 { font-size:20px !important; font-weight:700 !important; }
  .ql-editor h3 { font-size:17px !important; font-weight:700 !important; }
  .ql-editor blockquote {
    border-right:4px solid var(--primary) !important;
    border-left:none !important;
    padding-right:16px !important;
    color:var(--text-secondary);
    background:var(--primary-soft); margin:12px 0; padding:12px 16px;
    border-radius:0 8px 8px 0;
  }
  .ql-editor img { max-width:100%; border-radius:10px; margin:12px 0; }
  .ql-editor a { color:var(--primary); }
  .ql-snow .ql-stroke { stroke:var(--text-secondary) !important; }
  .ql-snow .ql-fill { fill:var(--text-secondary) !important; }
  .ql-snow .ql-picker-label { color:var(--text-secondary) !important; }
  .ql-snow button:hover .ql-stroke { stroke:var(--primary) !important; }
  .ql-snow button:hover .ql-fill { fill:var(--primary) !important; }
  .ql-snow button.ql-active .ql-stroke { stroke:var(--primary) !important; }
  .ql-snow button.ql-active .ql-fill { fill:var(--primary) !important; }
  .ql-snow .ql-picker-item:hover { color:var(--primary) !important; }

  .editor-stats {
    display:flex; gap:20px; padding:12px 20px;
    border-top:1px solid var(--border-light);
    font-size:12px; color:var(--text-muted); font-weight:600;
  }
  .editor-stats .stat { display:flex; align-items:center; gap:5px; }

  /* Sidebar panels */
  .editor-sidebar { display:flex; flex-direction:column; gap:14px; }

  .sidebar-panel {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius-lg); overflow:hidden;
    box-shadow:var(--shadow);
  }
  .panel-header {
    padding:14px 18px; border-bottom:1px solid var(--border-light);
    display:flex; align-items:center; justify-content:space-between;
    cursor:pointer; user-select:none;
  }
  .panel-header h4 {
    font-size:13px; font-weight:700; color:var(--text-primary);
    display:flex; align-items:center; gap:7px;
  }
  .panel-toggle {
    font-size:11px; color:var(--text-muted);
    transition:transform 0.2s;
  }
  .panel-header.collapsed .panel-toggle { transform:rotate(-90deg); }
  .panel-body { padding:16px 18px; }
  .panel-body.collapsed { display:none; }

  .panel-field { margin-bottom:14px; }
  .panel-field:last-child { margin-bottom:0; }
  .panel-field label {
    display:block; font-size:12px; font-weight:600;
    color:var(--text-secondary); margin-bottom:5px;
  }
  .panel-field select,
  .panel-field input[type="text"],
  .panel-field input[type="url"],
  .panel-field textarea {
    width:100%; padding:9px 12px;
    border:1.5px solid var(--border); border-radius:10px;
    font-family:'Tajawal',sans-serif; font-size:13px;
    background:var(--bg-input); color:var(--text-primary);
    outline:none; transition:var(--transition);
  }
  .panel-field select:focus,
  .panel-field input:focus,
  .panel-field textarea:focus {
    border-color:var(--primary); background:#fff;
    box-shadow:0 0 0 3px var(--primary-light);
  }
  .panel-field textarea { min-height:70px; resize:vertical; }

  /* Image preview */
  .image-preview {
    margin-top:10px; border-radius:10px; overflow:hidden;
    border:1px solid var(--border); display:none;
    position:relative;
  }
  .image-preview img { width:100%; display:block; }
  .image-preview.active { display:block; }

  /* Toggle switches */
  .toggle-group { display:flex; flex-direction:column; gap:10px; }
  .toggle-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:8px 0;
  }
  .toggle-item label { font-size:13px; font-weight:600; color:var(--text-primary); margin:0; cursor:pointer; }
  .toggle-switch {
    position:relative; width:40px; height:22px;
    background:var(--border); border-radius:12px;
    cursor:pointer; transition:var(--transition);
  }
  .toggle-switch::after {
    content:''; position:absolute; top:3px; right:3px;
    width:16px; height:16px; border-radius:50%;
    background:#fff; transition:var(--transition);
    box-shadow:0 1px 3px rgba(0,0,0,0.15);
  }
  .toggle-switch.active { background:var(--primary); }
  .toggle-switch.active::after { right:auto; left:3px; }
  .toggle-switch input { display:none; }

  /* Publish button */
  .publish-actions { display:flex; flex-direction:column; gap:8px; }
  .btn-publish {
    width:100%; padding:12px; border-radius:10px; border:none;
    font-family:'Tajawal',sans-serif; font-size:14px; font-weight:700;
    cursor:pointer; transition:var(--transition);
    display:flex; align-items:center; justify-content:center; gap:7px;
  }
  .btn-publish.primary {
    background:var(--primary); color:#fff;
    box-shadow:0 2px 8px rgba(99,102,241,0.3);
  }
  .btn-publish.primary:hover { background:var(--primary-dark); transform:translateY(-1px); }
  .btn-publish.secondary {
    background:var(--bg-input); color:var(--text-secondary);
    border:1.5px solid var(--border);
  }
  .btn-publish.secondary:hover { border-color:var(--primary); color:var(--primary); }

  /* Autosave indicator */
  .autosave-badge {
    display:inline-flex; align-items:center; gap:5px;
    font-size:11px; font-weight:600; color:var(--text-muted);
    padding:4px 10px; border-radius:8px;
    background:var(--bg-hover);
  }
  .autosave-badge.saving { color:var(--warning); }
  .autosave-badge.saved  { color:var(--success); }

  /* Filters bar */
  .filters-bar {
    display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    margin-bottom:18px;
  }
  .filter-input {
    padding:8px 14px; border:1.5px solid var(--border);
    border-radius:10px; font-family:'Tajawal',sans-serif;
    font-size:13px; background:var(--bg-card); color:var(--text-primary);
    outline:none; transition:var(--transition); min-width:200px;
  }
  .filter-input:focus { border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-light); }
  .filter-select {
    padding:8px 14px; border:1.5px solid var(--border);
    border-radius:10px; font-family:'Tajawal',sans-serif;
    font-size:13px; background:var(--bg-card); color:var(--text-primary);
    outline:none; transition:var(--transition); cursor:pointer;
  }

  /* Article list card enhancements */
  .article-row {
    display:flex; align-items:center; gap:14px;
    padding:14px 20px; border-bottom:1px solid var(--border-light);
    transition:var(--transition);
  }
  .article-row:hover { background:var(--bg-hover); }
  .article-row:last-child { border:none; }
  .article-thumb {
    width:52px; height:52px; border-radius:10px; flex-shrink:0;
    background:linear-gradient(135deg, var(--primary), #818cf8);
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:800; font-size:16px; overflow:hidden;
  }
  .article-thumb img { width:100%; height:100%; object-fit:cover; }
  .article-body { flex:1; min-width:0; }
  .article-body-title {
    font-size:14px; font-weight:700; color:var(--text-primary);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    margin-bottom:4px;
  }
  .article-body-meta {
    font-size:12px; color:var(--text-muted);
    display:flex; gap:12px; flex-wrap:wrap; align-items:center;
  }
  .article-actions { display:flex; gap:6px; flex-shrink:0; }

  /* Keyboard hint */
  .kbd { display:inline-block; padding:1px 6px; border-radius:4px; background:var(--bg-page); border:1px solid var(--border); font-size:10px; font-family:monospace; color:var(--text-muted); }

  /* AI Assistant */
  .ai-actions { display:flex; flex-direction:column; gap:6px; }
  .ai-btn {
    display:flex; align-items:center; gap:9px;
    padding:9px 12px; border-radius:9px;
    background:#fff; border:1.5px solid var(--border);
    font-family:'Tajawal',sans-serif; font-size:12.5px; font-weight:600;
    color:var(--text-primary); cursor:pointer;
    transition:var(--transition); text-align:right; width:100%;
  }
  .ai-btn:hover {
    border-color:var(--primary); background:var(--primary-soft);
    transform:translateX(-2px);
  }
  .ai-btn.loading { opacity:0.6; cursor:wait; }
  .ai-btn.loading::after {
    content:''; width:12px; height:12px; margin-right:auto;
    border:2px solid var(--primary); border-top-color:transparent;
    border-radius:50%; animation:spin 0.7s linear infinite;
  }
  @keyframes spin { to{transform:rotate(360deg)} }
  .ai-result {
    margin-top:12px; padding:12px 14px; background:#fff;
    border:1.5px solid var(--primary-light); border-radius:10px;
    font-size:13px; line-height:1.8; color:var(--text-primary);
    white-space:pre-wrap; max-height:280px; overflow-y:auto;
    position:relative;
  }
  .ai-result-actions { display:flex; gap:6px; margin-top:8px; padding-top:8px; border-top:1px dashed var(--border-light); }
  .ai-result-actions button {
    padding:5px 11px; border-radius:7px; border:1px solid var(--border);
    background:#fff; font-family:'Tajawal',sans-serif; font-size:11px;
    font-weight:600; cursor:pointer; transition:var(--transition);
    color:var(--text-secondary);
  }
  .ai-result-actions button:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }

  /* Templates */
  .tpl-grid { display:grid; grid-template-columns:1fr 1fr; gap:7px; }
  .tpl-btn {
    display:flex; flex-direction:column; align-items:center; gap:5px;
    padding:14px 8px; border-radius:10px;
    background:var(--bg-page); border:1.5px solid var(--border);
    font-family:'Tajawal',sans-serif; font-size:12px; font-weight:600;
    color:var(--text-primary); cursor:pointer;
    transition:var(--transition);
  }
  .tpl-btn span:first-child { font-size:22px; }
  .tpl-btn:hover {
    border-color:var(--primary); background:var(--primary-soft);
    transform:translateY(-2px);
  }
</style>

<div class="content">
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" style="animation:slideIn 0.3s ease;"><?php echo e($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success" style="animation:slideIn 0.3s ease;"><?php echo e($success); ?></div>
    <?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
        <div class="page-header">
            <div>
                <h2><?php echo $action === 'add' ? '✏️ إضافة خبر جديد' : '📝 تعديل الخبر'; ?></h2>
                <p>
                  <?php echo $action === 'add' ? 'أنشئ محتوى جديد للنشر' : 'عدّل محتوى الخبر'; ?>
                  <span class="autosave-badge" id="autosaveBadge" style="margin-right:10px;">💾 الحفظ التلقائي مفعّل</span>
                </p>
            </div>
            <div class="page-actions">
                <span class="kbd">Ctrl+S</span>
                <a href="articles.php" class="btn-outline">↩ رجوع</a>
            </div>
        </div>

        <form method="POST" id="articleForm">
            <?php echo csrf_field(); ?>
            <?php if ($article): ?>
                <input type="hidden" name="id" value="<?php echo $article['id']; ?>">
            <?php endif; ?>

            <div class="editor-layout">
                <!-- MAIN EDITOR -->
                <div class="editor-main">
                    <div class="editor-title-wrap">
                        <input type="text" name="title" id="titleInput" placeholder="عنوان الخبر..." required value="<?php echo $article ? e($article['title']) : ''; ?>">
                    </div>

                    <div class="editor-content-wrap">
                        <div id="quillEditor"><?php echo $article ? $article['content'] : ''; ?></div>
                        <input type="hidden" name="content" id="contentInput">
                        <div class="editor-stats">
                            <div class="stat">📝 <span id="wordCount">0</span> كلمة</div>
                            <div class="stat">📖 <span id="charCount">0</span> حرف</div>
                            <div class="stat">⏱ <span id="readTime">0</span> دقيقة قراءة</div>
                        </div>
                    </div>

                    <!-- Excerpt + AI summarize -->
                    <div class="sidebar-panel">
                        <div class="panel-header" onclick="togglePanel(this)">
                            <h4>📋 الملخص <span style="color:var(--text-muted);font-size:11px;font-weight:500;">(يظهر في القوائم)</span></h4>
                            <span class="panel-toggle">▼</span>
                        </div>
                        <div class="panel-body">
                            <div class="panel-field">
                                <textarea name="excerpt" id="excerptInput" placeholder="ملخص قصير يظهر في قوائم الأخبار..."><?php echo $article ? e($article['excerpt']) : ''; ?></textarea>
                            </div>
                            <button type="button" class="btn-publish secondary" style="padding:8px 12px;font-size:12px;" onclick="aiAction('summarize', this, 'excerptInput')">
                                ✨ توليد ملخص بالذكاء الاصطناعي
                            </button>
                        </div>
                    </div>
                </div>

                <!-- SIDEBAR -->
                <div class="editor-sidebar">
                    <!-- Publish -->
                    <div class="sidebar-panel">
                        <div class="panel-header">
                            <h4>🚀 النشر</h4>
                        </div>
                        <div class="panel-body">
                            <div class="panel-field">
                                <label>الحالة</label>
                                <select name="status" id="statusSelect">
                                    <option value="draft" <?php echo !$article || $article['status']==='draft' ? 'selected' : ''; ?>>📝 مسودة</option>
                                    <option value="published" <?php echo $article && $article['status']==='published' ? 'selected' : ''; ?>>✅ منشور</option>
                                </select>
                            </div>
                            <div class="publish-actions">
                                <button type="submit" class="btn-publish primary" id="publishBtn">
                                    <?php echo $article ? '💾 حفظ التعديلات' : '🚀 نشر الخبر'; ?>
                                </button>
                                <button type="button" class="btn-publish secondary" onclick="saveDraft()">
                                    📋 حفظ كمسودة
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Category + Source -->
                    <div class="sidebar-panel">
                        <div class="panel-header" onclick="togglePanel(this)">
                            <h4>📂 التصنيف والمصدر</h4>
                            <span class="panel-toggle">▼</span>
                        </div>
                        <div class="panel-body">
                            <div class="panel-field">
                                <label>التصنيف</label>
                                <select name="category_id">
                                    <option value="">بدون تصنيف</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo $article && $article['category_id']==$cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="panel-field">
                                <label>المصدر</label>
                                <select name="source_id">
                                    <option value="">بدون مصدر</option>
                                    <?php foreach ($sources as $src): ?>
                                        <option value="<?php echo $src['id']; ?>" <?php echo $article && $article['source_id']==$src['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($src['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Featured Image -->
                    <div class="sidebar-panel">
                        <div class="panel-header" onclick="togglePanel(this)">
                            <h4>🖼 الصورة الرئيسية</h4>
                            <span class="panel-toggle">▼</span>
                        </div>
                        <div class="panel-body">
                            <div class="panel-field">
                                <input type="url" name="image_url" id="imageUrlInput" placeholder="رابط الصورة..." value="<?php echo $article ? e($article['image_url']) : ''; ?>">
                            </div>
                            <div class="image-preview <?php echo ($article && !empty($article['image_url'])) ? 'active' : ''; ?>" id="imagePreview">
                                <img id="imagePreviewImg" src="<?php echo $article && !empty($article['image_url']) ? e($article['image_url']) : ''; ?>" alt="">
                            </div>
                        </div>
                    </div>

                    <!-- AI Assistant -->
                    <div class="sidebar-panel" style="border:1px solid var(--primary);background:linear-gradient(180deg,var(--primary-soft) 0%,#fff 40%);">
                        <div class="panel-header">
                            <h4>✨ مساعد الذكاء الاصطناعي</h4>
                        </div>
                        <div class="panel-body">
                            <div class="ai-actions">
                                <button type="button" class="ai-btn" onclick="aiAction('title_suggestions', this)">
                                    <span>💡</span><span>اقتراح عناوين</span>
                                </button>
                                <button type="button" class="ai-btn" onclick="aiAction('improve', this)">
                                    <span>✍️</span><span>تحسين الصياغة</span>
                                </button>
                                <button type="button" class="ai-btn" onclick="aiAction('keywords', this)">
                                    <span>🏷</span><span>استخراج كلمات مفتاحية</span>
                                </button>
                                <button type="button" class="ai-btn" onclick="aiAction('key_points', this)">
                                    <span>📝</span><span>استخراج النقاط الرئيسية</span>
                                </button>
                            </div>
                            <div id="aiResult" class="ai-result" style="display:none;"></div>
                        </div>
                    </div>

                    <?php if ($action === 'add'): ?>
                    <!-- Templates -->
                    <div class="sidebar-panel">
                        <div class="panel-header" onclick="togglePanel(this)">
                            <h4>📄 قوالب جاهزة</h4>
                            <span class="panel-toggle">▼</span>
                        </div>
                        <div class="panel-body">
                            <div class="tpl-grid">
                                <button type="button" class="tpl-btn" onclick="applyTemplate('breaking')">
                                    <span>🔥</span><span>خبر عاجل</span>
                                </button>
                                <button type="button" class="tpl-btn" onclick="applyTemplate('report')">
                                    <span>📊</span><span>تقرير</span>
                                </button>
                                <button type="button" class="tpl-btn" onclick="applyTemplate('opinion')">
                                    <span>💭</span><span>رأي</span>
                                </button>
                                <button type="button" class="tpl-btn" onclick="applyTemplate('interview')">
                                    <span>🎤</span><span>مقابلة</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Options -->
                    <div class="sidebar-panel">
                        <div class="panel-header" onclick="togglePanel(this)">
                            <h4>⚙️ خيارات</h4>
                            <span class="panel-toggle">▼</span>
                        </div>
                        <div class="panel-body">
                            <div class="toggle-group">
                                <div class="toggle-item">
                                    <label for="tog_featured">اختيار المحرر</label>
                                    <div class="toggle-switch <?php echo $article && $article['is_featured'] ? 'active' : ''; ?>" onclick="toggleSwitch(this,'is_featured')">
                                        <input type="checkbox" id="tog_featured" name="is_featured" <?php echo $article && $article['is_featured'] ? 'checked' : ''; ?>>
                                    </div>
                                </div>
                                <div class="toggle-item">
                                    <label for="tog_breaking">🔥 خبر عاجل</label>
                                    <div class="toggle-switch <?php echo $article && $article['is_breaking'] ? 'active' : ''; ?>" onclick="toggleSwitch(this,'is_breaking')">
                                        <input type="checkbox" id="tog_breaking" name="is_breaking" <?php echo $article && $article['is_breaking'] ? 'checked' : ''; ?>>
                                    </div>
                                </div>
                                <div class="toggle-item">
                                    <label for="tog_hero">⭐ خبر بارز</label>
                                    <div class="toggle-switch <?php echo $article && $article['is_hero'] ? 'active' : ''; ?>" onclick="toggleSwitch(this,'is_hero')">
                                        <input type="checkbox" id="tog_hero" name="is_hero" <?php echo $article && $article['is_hero'] ? 'checked' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </form>

    <?php else: ?>
        <div class="page-header">
            <div>
                <h2>📰 إدارة الأخبار</h2>
                <p><?php echo number_format($totalArticles); ?> خبر</p>
            </div>
            <div class="page-actions">
                <a href="articles.php?action=add" class="btn-primary">✏️ إضافة خبر</a>
            </div>
        </div>

        <form class="filters-bar" method="GET">
            <input type="text" name="q" class="filter-input" placeholder="🔍 ابحث في العناوين..." value="<?php echo e($searchFilter); ?>">
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                <option value="published" <?php echo $statusFilter==='published'?'selected':''; ?>>منشور</option>
                <option value="draft" <?php echo $statusFilter==='draft'?'selected':''; ?>>مسودة</option>
            </select>
            <select name="cat" class="filter-select" onchange="this.form.submit()">
                <option value="">كل الأقسام</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $catFilter==$cat['id']?'selected':''; ?>><?php echo e($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($searchFilter || $statusFilter || $catFilter): ?>
                <a href="articles.php" class="btn-outline">✕ مسح الفلاتر</a>
            <?php endif; ?>
        </form>

        <div class="card">
            <?php if (!empty($articlesList)): ?>
                <?php foreach ($articlesList as $art): ?>
                    <div class="article-row">
                        <div class="article-thumb">
                            <?php if (!empty($art['image_url'])): ?>
                                <img src="<?php echo e($art['image_url']); ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <?php echo e(mb_substr($art['title'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="article-body">
                            <div class="article-body-title">
                                <?php if ($art['is_breaking']): ?><span class="badge badge-danger" style="font-size:10px;padding:1px 7px;margin-left:6px;">عاجل</span><?php endif; ?>
                                <?php if ($art['is_featured']): ?><span class="badge badge-purple" style="font-size:10px;padding:1px 7px;margin-left:6px;">مختار</span><?php endif; ?>
                                <?php echo e($art['title']); ?>
                            </div>
                            <div class="article-body-meta">
                                <?php if ($art['status']==='published'): ?>
                                    <span class="badge badge-success">منشور</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">مسودة</span>
                                <?php endif; ?>
                                <?php if (!empty($art['cat_name'])): ?><span>📂 <?php echo e($art['cat_name']); ?></span><?php endif; ?>
                                <?php if (!empty($art['source_name'])): ?><span>🌐 <?php echo e($art['source_name']); ?></span><?php endif; ?>
                                <span>👁 <?php echo number_format((int)$art['view_count']); ?></span>
                                <span><?php echo date('Y/m/d', strtotime($art['created_at'])); ?></span>
                            </div>
                        </div>
                        <div class="article-actions">
                            <a href="articles.php?action=edit&id=<?php echo $art['id']; ?>" class="action-btn">تعديل</a>
                            <a href="articles.php?action=delete&id=<?php echo $art['id']; ?>" class="btn-danger" onclick="return confirm('هل تريد حذف هذا الخبر؟')">حذف</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="padding:50px;">لا توجد أخبار مطابقة</div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $qp = [];
                    if ($statusFilter) $qp['status'] = $statusFilter;
                    if ($catFilter) $qp['cat'] = $catFilter;
                    if ($searchFilter) $qp['q'] = $searchFilter;
                    $qs = $qp ? '&'.http_build_query($qp) : '';
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="articles.php?page=1<?php echo $qs; ?>">الأولى</a>
                        <a href="articles.php?page=<?php echo $page-1; ?><?php echo $qs; ?>">السابقة</a>
                    <?php endif; ?>
                    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                        <?php if ($i===$page): ?><span class="active"><?php echo $i; ?></span>
                        <?php else: ?><a href="articles.php?page=<?php echo $i; ?><?php echo $qs; ?>"><?php echo $i; ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="articles.php?page=<?php echo $page+1; ?><?php echo $qs; ?>">التالية</a>
                        <a href="articles.php?page=<?php echo $totalPages; ?><?php echo $qs; ?>">الأخيرة</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<style>@keyframes slideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}</style>
<script>
(function(){
  // Panel toggle
  window.togglePanel = function(header) {
    header.classList.toggle('collapsed');
    var body = header.nextElementSibling;
    body.classList.toggle('collapsed');
  };

  // Toggle switch
  window.toggleSwitch = function(el, name) {
    el.classList.toggle('active');
    var inp = el.querySelector('input');
    inp.checked = !inp.checked;
  };

  // Image preview
  var imgInput = document.getElementById('imageUrlInput');
  var imgPreview = document.getElementById('imagePreview');
  var imgPreviewImg = document.getElementById('imagePreviewImg');
  if (imgInput) {
    imgInput.addEventListener('input', function() {
      if (this.value) {
        imgPreviewImg.src = this.value;
        imgPreview.classList.add('active');
        imgPreviewImg.onerror = function() { imgPreview.classList.remove('active'); };
      } else {
        imgPreview.classList.remove('active');
      }
    });
  }

  // Only init editor on add/edit pages
  var editorEl = document.getElementById('quillEditor');
  if (!editorEl) return;

  // Quill init
  var quill = new Quill('#quillEditor', {
    theme: 'snow',
    placeholder: 'ابدأ كتابة المحتوى هنا...',
    modules: {
      toolbar: [
        [{'header': [1, 2, 3, false]}],
        ['bold', 'italic', 'underline', 'strike'],
        [{'list': 'ordered'}, {'list': 'bullet'}],
        ['blockquote', 'code-block'],
        ['link', 'image', 'video'],
        [{'align': []}],
        [{'direction': 'rtl'}],
        ['clean']
      ]
    }
  });

  var contentInput = document.getElementById('contentInput');
  var wordCountEl = document.getElementById('wordCount');
  var charCountEl = document.getElementById('charCount');
  var readTimeEl = document.getElementById('readTime');
  var badge = document.getElementById('autosaveBadge');

  function updateStats() {
    var text = quill.getText().trim();
    var words = text ? text.split(/\s+/).length : 0;
    var chars = text.length;
    var readMin = Math.max(1, Math.ceil(words / 200));
    wordCountEl.textContent = words;
    charCountEl.textContent = chars;
    readTimeEl.textContent = readMin;
  }

  function syncContent() {
    contentInput.value = quill.root.innerHTML;
  }

  quill.on('text-change', function() {
    updateStats();
    syncContent();
    scheduleAutosave();
  });

  updateStats();
  syncContent();

  // Autosave to localStorage every 30s
  var autosaveTimer = null;
  var articleId = '<?php echo $article ? (int)$article['id'] : 'new'; ?>';
  var storageKey = 'nf_draft_' + articleId;

  function scheduleAutosave() {
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(doAutosave, 30000);
  }

  function doAutosave() {
    var data = {
      title: document.getElementById('titleInput').value,
      content: quill.root.innerHTML,
      ts: Date.now()
    };
    try {
      localStorage.setItem(storageKey, JSON.stringify(data));
      badge.textContent = '✅ تم الحفظ التلقائي';
      badge.className = 'autosave-badge saved';
      setTimeout(function() {
        badge.textContent = '💾 الحفظ التلقائي مفعّل';
        badge.className = 'autosave-badge';
      }, 3000);
    } catch(e) {}
  }

  // Restore draft if newer than article
  try {
    var saved = JSON.parse(localStorage.getItem(storageKey));
    if (saved && articleId === 'new' && saved.content && saved.title) {
      if (confirm('يوجد مسودة محفوظة تلقائياً. هل تريد استعادتها؟')) {
        document.getElementById('titleInput').value = saved.title;
        quill.root.innerHTML = saved.content;
        syncContent();
        updateStats();
      }
    }
  } catch(e) {}

  // Clear draft on successful save
  var form = document.getElementById('articleForm');
  form.addEventListener('submit', function() {
    syncContent();
    try { localStorage.removeItem(storageKey); } catch(e) {}
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      syncContent();
      form.submit();
    }
  });

  // Save as draft button
  window.saveDraft = function() {
    document.getElementById('statusSelect').value = 'draft';
    syncContent();
    form.submit();
  };

  // Expose quill and helpers for AI/templates
  window._nfQuill = quill;
  window._nfSyncContent = syncContent;

  // AI assistant
  window.aiAction = function(task, btn, targetInputId) {
    var title = document.getElementById('titleInput').value || '';
    var text  = quill.getText();
    if (text.trim().length < 20 && task !== 'title_suggestions') {
      if (window.nfToast) nfToast('اكتب محتوى أطول أولاً (20 حرف على الأقل)', 'warn');
      return;
    }
    btn.classList.add('loading');
    var orig = btn.innerHTML;
    var fd = new FormData();
    fd.append('_csrf', '<?php echo csrf_token(); ?>');
    fd.append('task', task);
    fd.append('title', title);
    fd.append('text', text);

    fetch('api/ai_assist.php', { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(data){
        btn.classList.remove('loading');
        if (!data || !data.ok) {
          if (window.nfToast) nfToast(data && data.error ? data.error : 'فشل الاتصال بـ AI', 'error');
          return;
        }
        showAiResult(data.result, task, targetInputId);
        if (window.nfToast) nfToast('تم توليد النتيجة ✨', 'success');
      })
      .catch(function(){
        btn.classList.remove('loading');
        if (window.nfToast) nfToast('فشل الاتصال بـ AI', 'error');
      });
  };

  function showAiResult(text, task, targetInputId) {
    var box = document.getElementById('aiResult');
    if (!box) return;
    box.style.display = 'block';
    box.innerHTML = '';
    var content = document.createElement('div');
    content.textContent = text;
    box.appendChild(content);

    var actions = document.createElement('div');
    actions.className = 'ai-result-actions';

    // Primary action based on task
    if (task === 'summarize' && targetInputId) {
      var applyBtn = document.createElement('button');
      applyBtn.textContent = '✓ تطبيق على الملخص';
      applyBtn.onclick = function() {
        document.getElementById(targetInputId).value = text;
        if (window.nfToast) nfToast('تم تطبيق الملخص', 'success');
      };
      actions.appendChild(applyBtn);
    }
    if (task === 'improve') {
      var replaceBtn = document.createElement('button');
      replaceBtn.textContent = '✓ استبدال المحتوى';
      replaceBtn.onclick = function() {
        if (confirm('هل تريد استبدال المحتوى الحالي بالنسخة المحسّنة؟')) {
          quill.setText(text);
          syncContent();
          if (window.nfToast) nfToast('تم تحسين المحتوى', 'success');
        }
      };
      actions.appendChild(replaceBtn);
    }
    if (task === 'title_suggestions') {
      var lines = text.split('\n').map(function(l){return l.trim();}).filter(Boolean).slice(0,3);
      lines.forEach(function(line){
        var b = document.createElement('button');
        b.textContent = '✓ ' + (line.length > 40 ? line.slice(0,40)+'…' : line);
        b.title = line;
        b.onclick = function() {
          document.getElementById('titleInput').value = line.replace(/^[-*•]\s*/,'');
          if (window.nfToast) nfToast('تم تطبيق العنوان', 'success');
        };
        actions.appendChild(b);
      });
    }

    var copyBtn = document.createElement('button');
    copyBtn.textContent = '📋 نسخ';
    copyBtn.onclick = function() {
      navigator.clipboard && navigator.clipboard.writeText(text);
      if (window.nfToast) nfToast('تم النسخ', 'success');
    };
    actions.appendChild(copyBtn);

    var closeBtn = document.createElement('button');
    closeBtn.textContent = '✕ إغلاق';
    closeBtn.onclick = function() { box.style.display = 'none'; };
    actions.appendChild(closeBtn);

    box.appendChild(actions);
  }

  // Templates
  var TEMPLATES = {
    breaking: {
      title: '',
      content: '<h2>عاجل</h2><p><strong>التوقيت:</strong> …</p><p><strong>الموقع:</strong> …</p><p><strong>الخلاصة:</strong></p><p>[اكتب التفاصيل هنا]</p><blockquote>مصدر أو تصريح</blockquote><p><strong>ما التالي:</strong> …</p>'
    },
    report: {
      title: '',
      content: '<h2>مقدمة</h2><p>[سياق الخبر والخلفية]</p><h2>التفاصيل</h2><p>[الوقائع الرئيسية]</p><h2>السياق</h2><p>[تحليل وخلفية]</p><h2>ردود الفعل</h2><blockquote>تصريح أو رد فعل</blockquote><h2>الخاتمة</h2><p>[ما التالي وتوقعات]</p>'
    },
    opinion: {
      title: '',
      content: '<h2>الرأي</h2><p>[الأطروحة الرئيسية في جملة أو جملتين]</p><h2>الحجج</h2><ol><li>[الحجة الأولى]</li><li>[الحجة الثانية]</li><li>[الحجة الثالثة]</li></ol><h2>الرد على المعارضين</h2><p>[تناول وجهات النظر المقابلة]</p><h2>الخلاصة</h2><p>[الاستنتاج والدعوة للتأمل]</p>'
    },
    interview: {
      title: '',
      content: '<h2>المقدمة</h2><p>[تعريف بالضيف وسياق اللقاء]</p><p><strong>س:</strong> [السؤال الأول]</p><p><strong>ج:</strong> [الإجابة]</p><p><strong>س:</strong> [السؤال الثاني]</p><p><strong>ج:</strong> [الإجابة]</p><p><strong>س:</strong> [السؤال الثالث]</p><p><strong>ج:</strong> [الإجابة]</p><h2>كلمة أخيرة</h2><blockquote>[اقتباس ختامي مميز]</blockquote>'
    }
  };
  window.applyTemplate = function(name) {
    var tpl = TEMPLATES[name];
    if (!tpl) return;
    if (quill.getText().trim().length > 5) {
      if (!confirm('سيتم استبدال المحتوى الحالي بالقالب. متأكد؟')) return;
    }
    quill.root.innerHTML = tpl.content;
    syncContent();
    if (window.nfToast) nfToast('تم تطبيق القالب', 'success');
  };

})();
</script>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
