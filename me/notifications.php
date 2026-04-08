<?php
$pageTitle = 'الإشعارات';
$pageSlug  = 'notifications';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['_csrf'] ?? '') && ($_POST['action'] ?? '') === 'mark_all_read') {
    user_notifications_mark_all_read($userId);
    header('Location: notifications.php'); exit;
}

$notifs = user_notifications($userId, 50);
?>
<div class="dash-topbar">
  <h1>🔔 الإشعارات</h1>
  <?php if ($notifs): ?>
    <form method="POST" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all_read">
      <button type="submit" class="btn">تعليم الكل كمقروء</button>
    </form>
  <?php endif; ?>
</div>

<div class="panel-card">
  <?php if (!$notifs): ?>
    <div class="u-empty">
      <div class="u-empty-ico">🔕</div>
      <p>لا توجد إشعارات حتى الآن</p>
    </div>
  <?php else: ?>
    <div class="notif-list">
      <?php foreach ($notifs as $n): ?>
        <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
          <div class="notif-ico"><?= e($n['icon'] ?: '🔔') ?></div>
          <div class="notif-body">
            <div class="notif-title">
              <?php if (!empty($n['link'])): ?>
                <a href="<?= e($n['link']) ?>" style="color:inherit;"><?= e($n['title']) ?></a>
              <?php else: ?>
                <?= e($n['title']) ?>
              <?php endif; ?>
            </div>
            <?php if (!empty($n['body'])): ?><div class="notif-text"><?= e($n['body']) ?></div><?php endif; ?>
            <div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
