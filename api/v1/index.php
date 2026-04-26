<?php
/**
 * نيوز فيد — API v1 Index / Discovery
 * ====================================
 * Returns a JSON catalog of all available endpoints. Useful for
 * the mobile app's bootstrap probe and for manual smoke testing.
 */
require_once __DIR__ . '/_bootstrap.php';

api_method('GET');

api_ok([
    'name'     => 'feedsnews-api',
    'version'  => '1.0.0',
    'site'     => SITE_URL,
    'site_name' => SITE_NAME,
    'auth'     => 'Bearer JWT (HS256) — issued by /api/v1/auth/login',
    'endpoints' => [
        'auth' => [
            'POST /api/v1/auth/register',
            'POST /api/v1/auth/login',
            'POST /api/v1/auth/logout',
            'GET  /api/v1/auth/me',
            'POST /api/v1/auth/refresh',
            'POST /api/v1/auth/forgot',
            'POST /api/v1/auth/reset',
            'POST /api/v1/auth/oauth/google',
            'POST /api/v1/auth/oauth/apple',
        ],
        'content' => [
            'GET /api/v1/content/home',
            'GET /api/v1/content/articles',
            'GET /api/v1/content/article?id=|slug=',
            'GET /api/v1/content/article/related?id=',
            'GET /api/v1/content/categories',
            'GET /api/v1/content/sources',
            'GET /api/v1/content/trending',
            'GET /api/v1/content/breaking',
            'GET /api/v1/content/ticker',
            'GET /api/v1/content/search?q=',
            'GET /api/v1/content/clusters',
            'GET /api/v1/content/cluster?id=',
            'GET /api/v1/content/topic?slug=',
            'GET /api/v1/content/timeline?slug=',
            'GET /api/v1/content/timelines',
            'GET /api/v1/content/evolving-stories',
            'GET /api/v1/content/evolving-story?slug=',
            'GET /api/v1/content/evolving-story/quotes?slug=',
            'GET /api/v1/content/evolving-story/network?slug=',
            'GET /api/v1/content/evolving-story/book?slug=',
            'GET /api/v1/content/news-map',
            'GET /api/v1/content/sabah',
            'GET /api/v1/content/weekly-rewind',
            'GET /api/v1/content/editorial',
            'GET /api/v1/content/corrections',
            'GET /api/v1/content/about',
            'GET /api/v1/content/privacy',
            'GET /api/v1/content/contact',
        ],
        'media' => [
            'GET /api/v1/media/podcast/episodes',
            'GET /api/v1/media/podcast/latest',
            'GET /api/v1/media/podcast/episode?id=|date=',
            'GET /api/v1/media/gallery',
            'GET /api/v1/media/reels',
            'GET /api/v1/media/telegram',
            'GET /api/v1/media/twitter',
            'GET /api/v1/media/youtube',
            'POST /api/v1/media/tts',
            'POST /api/v1/media/ask',
        ],
        'user' => [
            'PATCH /api/v1/user/profile',
            'POST  /api/v1/user/password',
            'GET   /api/v1/user/bookmarks',
            'POST  /api/v1/user/bookmark',
            'GET   /api/v1/user/follows',
            'POST  /api/v1/user/follow/category',
            'POST  /api/v1/user/follow/source',
            'POST  /api/v1/user/follow/story',
            'GET   /api/v1/user/notifications',
            'POST  /api/v1/user/notifications/read',
            'GET   /api/v1/user/history',
            'POST  /api/v1/user/history',
            'POST  /api/v1/user/reaction',
            'POST  /api/v1/user/comment',
            'POST  /api/v1/user/share',
            'POST  /api/v1/user/newsletter/subscribe',
            'POST  /api/v1/user/newsletter/unsubscribe',
            'POST  /api/v1/user/device',
        ],
        'system' => [
            'GET /api/v1/system/config',
            'GET /api/v1/system/version',
            'GET /api/v1/system/health',
        ],
    ],
]);
