<?php
/**
 * GET /api/v1/system/config
 * Public app configuration (feature flags, force-update info, share urls).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('system:config', 240, 60);

$db = getDB();
$flags = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
    foreach ($rows as $r) $flags[$r['setting_key']] = $r['setting_value'];
} catch (Throwable $e) {}

api_ok([
    'site_name' => SITE_NAME,
    'tagline'   => SITE_TAGLINE,
    'site_url'  => SITE_URL,
    'app_name'  => 'فيد نيوز',
    'app_id_ios'     => 'net.feedsnews.app',
    'app_id_android' => 'net.feedsnews.app',
    'min_supported_version' => $flags['mobile_min_version'] ?? '1.0.0',
    'latest_version'        => $flags['mobile_latest_version'] ?? '1.0.0',
    'force_update'          => (bool)($flags['mobile_force_update'] ?? false),
    'features' => [
        'podcast'         => (bool)($flags['feature_podcast'] ?? true),
        'reels'           => (bool)($flags['feature_reels'] ?? true),
        'gallery'         => (bool)($flags['feature_gallery'] ?? true),
        'telegram'        => (bool)($flags['feature_telegram'] ?? true),
        'twitter'         => (bool)($flags['feature_twitter'] ?? true),
        'youtube'         => (bool)($flags['feature_youtube'] ?? true),
        'evolving_stories'=> (bool)($flags['feature_evolving'] ?? true),
        'news_map'        => (bool)($flags['feature_news_map'] ?? true),
        'sabah'           => (bool)($flags['feature_sabah'] ?? true),
        'weekly_rewind'   => (bool)($flags['feature_weekly_rewind'] ?? true),
        'ask'             => (bool)($flags['feature_ask'] ?? true),
        'tts'             => (bool)($flags['feature_tts'] ?? true),
    ],
    'support_email' => $flags['support_email'] ?? 'support@feedsnews.net',
    'privacy_url'   => SITE_URL . '/privacy.php',
    'terms_url'     => SITE_URL . '/about.php',
    'contact_url'   => SITE_URL . '/contact.php',
]);
