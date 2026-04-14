<?php
/**
 * GET /version.php → JSON with the currently-deployed build.
 *
 * Lets us curl the deploy from CI or the browser to confirm a push
 * landed:
 *
 *   curl -s https://postfeed.emdatra.org/version.php
 *   {"semver":"1.1.0","sha":"abc1234","full":"1.1.0+abc1234", …}
 *
 * No auth — the info is already exposed in the HTML <meta> tag and
 * console banner, so there's nothing to hide. Cached for one minute
 * so reload-mashing doesn't thrash disk.
 */

require_once __DIR__ . '/includes/version.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
echo json_encode(app_version(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
