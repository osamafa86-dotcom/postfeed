<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
$u = api_require_user();
api_ok(['user' => api_user_public($u)]);
