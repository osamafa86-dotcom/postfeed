<?php
/**
 * نيوزفلو - تسجيل الخروج
 */

require_once __DIR__ . '/../includes/config.php';

session_start();
session_destroy();

header('Location: login.php');
exit;
