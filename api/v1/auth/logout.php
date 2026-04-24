<?php
/**
 * POST /api/v1/auth/logout — revokes the current Bearer token.
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');

$auth = api_auth_lookup();
if (!$auth) api_error('auth_required', 'يجب تسجيل الدخول', 401);

api_token_revoke((int)$auth['token_id']);
api_json(['ok' => true]);
