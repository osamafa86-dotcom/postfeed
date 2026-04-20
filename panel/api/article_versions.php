<?php
/**
 * Article version history API.
 * Uses the audit_log table to reconstruct edit history.
 * Returns JSON {versions: [{time, changes}]}
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';
requireRole('editor');

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['versions' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();

try {
    // Ensure audit table exists
    if (function_exists('audit_ensure_table')) {
        audit_ensure_table($db);
    }

    $stmt = $db->prepare("
        SELECT action, performed_at, details, admin_name
        FROM audit_log
        WHERE entity_type = 'article' AND entity_id = ?
        ORDER BY performed_at DESC
        LIMIT 20
    ");
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $versions = [];
    foreach ($logs as $log) {
        $action = $log['action'] ?? '';
        $details = json_decode($log['details'] ?? '{}', true) ?: [];
        $admin = $log['admin_name'] ?? '';

        $actionLabels = [
            'article.create' => 'إنشاء',
            'article.update' => 'تعديل',
            'article.delete' => 'حذف'
        ];
        $actionLabel = $actionLabels[$action] ?? $action;

        $changeDesc = $actionLabel;
        if (!empty($details['status'])) {
            $statusLabels = ['published' => 'منشور', 'draft' => 'مسودة'];
            $changeDesc .= ' — ' . ($statusLabels[$details['status']] ?? $details['status']);
        }
        if ($admin) {
            $changeDesc .= ' بواسطة ' . htmlspecialchars($admin, ENT_QUOTES, 'UTF-8');
        }

        $time = $log['performed_at'] ?? '';
        if ($time) {
            $ts = strtotime($time);
            $diff = time() - $ts;
            if ($diff < 3600) {
                $time = 'منذ ' . max(1, (int)($diff / 60)) . ' دقيقة';
            } elseif ($diff < 86400) {
                $time = 'منذ ' . (int)($diff / 3600) . ' ساعة';
            } else {
                $time = date('Y/m/d H:i', $ts);
            }
        }

        $versions[] = [
            'time'    => $time,
            'changes' => $changeDesc
        ];
    }

    echo json_encode(['versions' => $versions], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['versions' => []], JSON_UNESCAPED_UNICODE);
}
