<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');

$ok = true;
$details = [];
try {
    $db = getDB();
    $details['db'] = (bool)$db->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    $ok = false;
    $details['db'] = false;
}

api_ok([
    'status' => $ok ? 'ok' : 'degraded',
    'version' => '1.0.0',
    'site_version' => trim((string)@file_get_contents(__DIR__ . '/../../../VERSION')),
    'time' => gmdate('c'),
    'details' => $details,
]);
