<?php
$theme = current_theme();
$siteName = defined('SITE_NAME') ? SITE_NAME : 'نيوزفلو';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= e($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'حساب') ?> - <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/user.css?v=4">
</head>
<body class="auth-body">
<div class="auth-wrap">
