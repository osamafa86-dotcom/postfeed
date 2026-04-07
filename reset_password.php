<?php
require_once __DIR__ . '/includes/config.php';

$db = getDB();
$email = 'osama.fa.mayadmeh@gmail.com';
$newPassword = 'Admin@2026';
$hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
$result = $stmt->execute([$hash, $email]);

if ($result && $stmt->rowCount() > 0) {
    echo "تم تغيير كلمة المرور بنجاح!<br>";
    echo "البريد: " . $email . "<br>";
    echo "كلمة المرور الجديدة: " . $newPassword;
} else {
    echo "لم يتم العثور على المستخدم. جاري إنشاء حساب جديد...<br>";
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, avatar_letter, plan) VALUES (?, ?, ?, 'admin', 'أ', 'premium')");
    $stmt->execute(['أسامة', $email, $hash]);
    echo "تم إنشاء الحساب بنجاح!<br>";
    echo "البريد: " . $email . "<br>";
    echo "كلمة المرور: " . $newPassword;
}
