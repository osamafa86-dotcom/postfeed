<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
$u = api_require_user();
api_ok([
    'token' => jwt_issue((int)$u['id']),
    'user'  => api_user_public($u),
]);
