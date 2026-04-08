<?php
/**
 * نيوزفلو - إدارة الأخبار
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
requireAdmin();

$db = getDB();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$article = null;

// معالجة الحذف
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        // fetch title for audit
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
    $content = trim($_POST['content'] ?? '');
    $image_url = trim($_POST['image_url'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $source_id = !empty($_POST['source_id']) ? (int)$_POST['source_id'] : null;
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_breaking = isset($_POST['is_breaking']) ? 1 : 0;
    $is_hero = isset($_POST['is_hero']) ? 1 : 0;
    $status = $_POST['status'] ?? 'draft';

    if (empty($title) || empty($content)) {
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

// جلب البيانات المساعدة
$categories = $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM sources WHERE is_active = 1 ORDER BY name")->fetchAll();

// جلب قائمة الأخبار للعرض
$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;
$articles = $db->prepare("
    SELECT a.*, c.name as cat_name, s.name as source_name
    FROM articles a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN sources s ON a.source_id = s.id
    ORDER BY a.created_at DESC LIMIT ? OFFSET ?
");
$articles->execute([$perPage, $offset]);
$articlesList = $articles->fetchAll();

$totalArticles = $db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalPages = ceil($totalArticles / $perPage);

$pageTitle = ($action === 'add' ? 'إضافة خبر' : ($action === 'edit' ? 'تعديل خبر' : 'إدارة الأخبار')) . ' - نيوزفلو';
$activePage = 'articles';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
    <?php endif; ?>

    <?php if (in_array($action, ['add', 'edit'])): ?>
        <div class="page-header">
            <div>
                <h2><?php echo $action === 'add' ? 'إضافة خبر جديد' : 'تعديل الخبر'; ?></h2>
                <p>أدخل بيانات الخبر بدقة</p>
            </div>
            <div class="page-actions">
                <a href="articles.php" class="btn-outline">رجوع</a>
            </div>
        </div>

        <div class="form-card">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <?php if ($article): ?>
                    <input type="hidden" name="id" value="<?php echo $article['id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="title">العنوان *</label>
                        <input type="text" id="title" name="title" class="form-control" required value="<?php echo $article ? e($article['title']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="image_url">رابط الصورة</label>
                        <input type="url" id="image_url" name="image_url" class="form-control" value="<?php echo $article ? e($article['image_url']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="category_id">التصنيف</label>
                        <select id="category_id" name="category_id" class="form-control">
                            <option value="">-- بدون تصنيف --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $article && $article['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="source_id">المصدر</label>
                        <select id="source_id" name="source_id" class="form-control">
                            <option value="">-- بدون مصدر --</option>
                            <?php foreach ($sources as $src): ?>
                                <option value="<?php echo $src['id']; ?>" <?php echo $article && $article['source_id'] == $src['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($src['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="status">الحالة</label>
                    <select id="status" name="status" class="form-control">
                        <option value="draft" <?php echo !$article || $article['status'] === 'draft' ? 'selected' : ''; ?>>مسودة</option>
                        <option value="published" <?php echo $article && $article['status'] === 'published' ? 'selected' : ''; ?>>منشور</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="excerpt">الملخص</label>
                    <textarea id="excerpt" name="excerpt" class="form-control"><?php echo $article ? e($article['excerpt']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="content">المحتوى *</label>
                    <textarea id="content" name="content" class="form-control" required style="min-height:280px;"><?php echo $article ? e($article['content']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label>الخيارات</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="is_featured" name="is_featured" <?php echo $article && $article['is_featured'] ? 'checked' : ''; ?>>
                            <label for="is_featured">اختيار المحرر</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="is_breaking" name="is_breaking" <?php echo $article && $article['is_breaking'] ? 'checked' : ''; ?>>
                            <label for="is_breaking">عاجل</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="is_hero" name="is_hero" <?php echo $article && $article['is_hero'] ? 'checked' : ''; ?>>
                            <label for="is_hero">خبر بارز</label>
                        </div>
                    </div>
                </div>

                <div class="page-actions">
                    <button type="submit" class="btn-primary">حفظ</button>
                    <a href="articles.php" class="btn-outline">إلغاء</a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <div class="page-header">
            <div>
                <h2>إدارة الأخبار</h2>
                <p>جميع الأخبار المنشورة والمسودات</p>
            </div>
            <div class="page-actions">
                <a href="articles.php?action=add" class="btn-primary">+ إضافة خبر</a>
            </div>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>العنوان</th>
                        <th>التصنيف</th>
                        <th>المصدر</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($articlesList)): ?>
                        <?php foreach ($articlesList as $art): ?>
                            <tr>
                                <td>
                                    <?php if ($art['is_breaking']): ?>
                                        <span class="badge badge-danger">عاجل</span>
                                    <?php endif; ?>
                                    <?php echo e(mb_substr($art['title'], 0, 50)); ?>
                                </td>
                                <td><?php echo e($art['cat_name'] ?? 'بدون'); ?></td>
                                <td><?php echo e($art['source_name'] ?? 'بدون'); ?></td>
                                <td>
                                    <?php if ($art['status'] === 'published'): ?>
                                        <span class="badge badge-success">منشور</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">مسودة</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="articles.php?action=edit&id=<?php echo $art['id']; ?>" class="action-btn">تعديل</a>
                                    <a href="articles.php?action=delete&id=<?php echo $art['id']; ?>" class="btn-danger" onclick="return confirm('هل تريد حذف هذا الخبر؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">لا توجد أخبار</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="articles.php?page=1">الأولى</a>
                        <a href="articles.php?page=<?php echo $page - 1; ?>">السابقة</a>
                    <?php else: ?>
                        <span class="disabled">الأولى</span>
                        <span class="disabled">السابقة</span>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="articles.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="articles.php?page=<?php echo $page + 1; ?>">التالية</a>
                        <a href="articles.php?page=<?php echo $totalPages; ?>">الأخيرة</a>
                    <?php else: ?>
                        <span class="disabled">التالية</span>
                        <span class="disabled">الأخيرة</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/panel_layout_foot.php'; ?>
