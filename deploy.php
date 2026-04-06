<?php
/**
 * Auto-deploy from GitHub webhook
 */

$secret = 'postfeed_deploy_2026';

// Verify webhook secret
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');
$hash = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($hash, $signature)) {
    http_response_code(403);
    die('Unauthorized');
}

// Run git pull
$output = shell_exec('cd ' . __DIR__ . ' && git pull origin main 2>&1');

echo "Deploy done:\n" . $output;
