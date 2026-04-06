<?php
/**
 * Auto-deploy from GitHub webhook
 */

// Rate limiting - max 1 deploy per minute
$lockFile = sys_get_temp_dir() . '/postfeed_deploy.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
    http_response_code(429);
    die('Too many requests');
}

// Verify webhook secret from environment or fallback
$secret = getenv('DEPLOY_SECRET') ?: 'postfeed_deploy_2026';

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');
$hash = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($hash, $signature)) {
    http_response_code(403);
    die('Unauthorized');
}

// Update lock file
touch($lockFile);

// Log deployment
$logFile = __DIR__ . '/deploy_log.txt';
$logEntry = date('Y-m-d H:i:s') . " - Deploy triggered from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

// Run git pull
$output = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git pull origin main 2>&1');

$logEntry .= $output . "\n---\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

echo "Deploy done:\n" . $output;
