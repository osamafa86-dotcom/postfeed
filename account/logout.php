<?php
require_once __DIR__ . '/../includes/user_auth.php';
user_logout();
header('Location: ../index.php');
exit;
