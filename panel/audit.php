<?php
/**
 * نيوز فيد - سجل التدقيق (Audit Log)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
requireAdmin();

$db = getDB();
audit_ensure_table();

// Filters
$filterAction = trim($_GET['action_filter'] ?? '');
$filterUser   = trim($_GET['user'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($filterAction !== '') { $where[] = 'action LIKE ?'; $params[] = '%' . $filterAction . '%'; }
if ($filterUser !== '')   { $where[] = 'user_name LIKE ?'; $params[] = '%' . $filterUser . '%'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $totalStmt = $db->prepare("SELECT COUNT(*) FROM audit_log $whereSql");
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();

    // Bind LIMIT/OFFSET as integers rather than interpolating — they come
    // from $_GET['page'], so even though we cast to int, parameterising
    // keeps us defensive if the derivation ever changes.
    $stmt = $db->prepare("SELECT * FROM audit_log $whereSql ORDER BY id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $i => $v) {
        $stmt->bindValue($i + 1, $v);
    }
    $stmt->bindValue(':limit',  (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    $rows = [];
    $total = 0;
}

$pages = max(1, (int)ceil($total / $perPage));

$pageTitle  = 'سجل التدقيق - نيوز فيد';
$activePage = 'audit';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <div class="page-header">
        <div>
            <h2>سجل التدقيق</h2>
            <p>آخر إجراءات المسؤولين — <?php echo number_format($total); ?> سجل</p>
        </div>
    </div>

    <div class="form-card">
        <form method="GET" class="form-row">
            <div class="form-group">
                <label>نوع الإجراء</label>
                <input type="text" name="action_filter" class="form-control"
                       value="<?php echo e($filterAction); ?>" placeholder="مثل: article.delete">
            </div>
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="user" class="form-control"
                       value="<?php echo e($filterUser); ?>">
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
                <button type="submit" class="btn-primary">تصفية</button>
                <a href="audit.php" class="btn-outline">إلغاء</a>
            </div>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>الوقت</th>
                    <th>المستخدم</th>
                    <th>الإجراء</th>
                    <th>الكيان</th>
                    <th>IP</th>
                    <th>التفاصيل</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:30px;">لا توجد سجلات</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td style="white-space:nowrap;direction:ltr;text-align:right;"><?php echo e($r['created_at']); ?></td>
                            <td><?php echo e($r['user_name'] ?: '—'); ?></td>
                            <td>
                                <?php
                                $ac = $r['action'];
                                $cls = 'badge-muted';
                                if (strpos($ac, 'delete') !== false) $cls = 'badge-danger';
                                elseif (strpos($ac, 'create') !== false) $cls = 'badge-success';
                                elseif (strpos($ac, 'update') !== false) $cls = 'badge-warning';
                                elseif (strpos($ac, 'login.success') !== false) $cls = 'badge-primary';
                                elseif (strpos($ac, 'login.fail') !== false) $cls = 'badge-danger';
                                ?>
                                <span class="badge <?php echo $cls; ?>"><?php echo e($ac); ?></span>
                            </td>
                            <td>
                                <?php if ($r['entity_type']): ?>
                                    <?php echo e($r['entity_type']); ?>#<?php echo (int)$r['entity_id']; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td style="font-family:monospace;font-size:11px;"><?php echo e($r['ip'] ?: '—'); ?></td>
                            <td style="font-size:11px;color:var(--text-muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($r['meta'] ?: ''); ?>">
                                <?php echo e($r['meta'] ?: '—'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                <?php
                $q = $_GET; $q['page'] = $p;
                $url = 'audit.php?' . http_build_query($q);
                ?>
                <?php if ($p === $page): ?>
                    <span class="active"><?php echo $p; ?></span>
                <?php else: ?>
                    <a href="<?php echo e($url); ?>"><?php echo $p; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

</main>
</body>
</html>
