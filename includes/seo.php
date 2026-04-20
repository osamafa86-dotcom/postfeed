<?php
/**
 * نيوز فيد — SEO / structured data helpers
 *
 * One place for every `<meta property="og:…">`, Twitter Card, canonical,
 * and JSON-LD (Schema.org) block. Individual pages just call one of
 * the render_* functions below from the <head>; they do the URL
 * absolutisation and escaping internally.
 *
 * Why a helper and not per-page snippets:
 *   - Single source of truth for publisher.logo / sameAs array, so
 *     Google's rich-results validator doesn't see drift between
 *     NewsArticle (on /article) and Organization (on /).
 *   - Keeps the actual page templates readable.
 *   - Makes it easy to add new page types (category, source, cluster)
 *     without copy-pasting 40 lines of meta tags.
 */

/**
 * Build an absolute URL from a site-relative path.
 * Accepts anything from "/foo" to "https://other.example/x" and
 * normalises it against SITE_URL.
 */
function abs_url(string $path): string {
    if ($path === '') return SITE_URL;
    if (preg_match('#^https?://#i', $path)) return $path;
    if (strpos($path, 'data:') === 0) return $path;
    $base = rtrim(SITE_URL, '/');
    if ($path[0] !== '/') $path = '/' . $path;
    return $base . $path;
}

/** Twitter / X handle config — normalised to the @handle form. */
function seo_twitter_handle(): string {
    $raw = trim((string) getSetting('twitter_handle', ''));
    if ($raw === '') return '';
    return '@' . ltrim($raw, '@');
}

/**
 * sameAs URLs for Organization JSON-LD — built from the admin-configured
 * social profiles. Anything blank is dropped so Google doesn't see
 * empty entries.
 */
function seo_same_as(): array {
    $urls = [];
    $fb = trim((string) getSetting('facebook_page', ''));
    if ($fb) $urls[] = $fb;
    $tw = trim((string) getSetting('twitter_handle', ''));
    if ($tw) $urls[] = 'https://x.com/' . ltrim($tw, '@');
    $ig = trim((string) getSetting('instagram_handle', ''));
    if ($ig) $urls[] = 'https://www.instagram.com/' . ltrim($ig, '@');
    $yt = trim((string) getSetting('youtube_channel', ''));
    if ($yt) $urls[] = $yt;
    return $urls;
}

/**
 * Default og:image for pages that don't have a per-page hero. Uses
 * the configured default, falls back to the site logo.
 */
function seo_default_og_image(): string {
    $custom = trim((string) getSetting('default_og_image', ''));
    return $custom ?: abs_url('/assets/logo.svg');
}

/**
 * The Publisher Organization shape referenced from NewsArticle JSON-LD
 * and also emitted standalone on the homepage. Returned as a PHP array
 * so the caller can json_encode it inside a larger payload.
 */
function seo_publisher_organization(): array {
    return [
        '@type' => 'Organization',
        'name'  => getSetting('site_name', SITE_NAME),
        'url'   => rtrim(SITE_URL, '/') . '/',
        'logo'  => [
            '@type'  => 'ImageObject',
            'url'    => abs_url('/assets/logo.svg'),
            'width'  => 600,
            'height' => 60,
        ],
    ];
}

/**
 * Render the full SEO head block for the homepage:
 *   - canonical
 *   - OG (type=website) + Twitter Card
 *   - WebSite JSON-LD with SearchAction (enables the Sitelinks
 *     searchbox in Google results)
 *   - Organization JSON-LD with logo + sameAs (social profiles)
 *
 * Prints directly to output. Safe to call inside <head>.
 */
function render_home_seo(): void {
    $siteName = getSetting('site_name', SITE_NAME);
    $tagline  = getSetting('site_tagline', SITE_TAGLINE);
    $desc     = 'مجمع الأخبار العربية الأول — أحدث الأخبار من مصادر موثوقة في السياسة، الاقتصاد، الرياضة والتكنولوجيا.';
    $title    = $siteName . ' — ' . $tagline;
    $canonical = rtrim(SITE_URL, '/') . '/';
    $ogImage  = seo_default_og_image();
    $twitter  = seo_twitter_handle();
    $sameAs   = seo_same_as();
    ?>
    <link rel="canonical" href="<?php echo e($canonical); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo e($siteName); ?>">
    <meta property="og:title" content="<?php echo e($title); ?>">
    <meta property="og:description" content="<?php echo e($desc); ?>">
    <meta property="og:url" content="<?php echo e($canonical); ?>">
    <meta property="og:image" content="<?php echo e($ogImage); ?>">
    <meta property="og:locale" content="ar_AR">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($title); ?>">
    <meta name="twitter:description" content="<?php echo e($desc); ?>">
    <meta name="twitter:image" content="<?php echo e($ogImage); ?>">
    <?php if ($twitter): ?>
    <meta name="twitter:site" content="<?php echo e($twitter); ?>">
    <?php endif; ?>
    <?php
    // WebSite with SearchAction — powers Google's sitelinks searchbox.
    // The search URL template must include "{search_term_string}" exactly.
    $website = [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => $siteName,
        'url'      => $canonical,
        'inLanguage' => 'ar',
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => rtrim(SITE_URL, '/') . '/search?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
    // Organization — standalone knowledge-panel-friendly block with
    // logo + social profiles.
    $org = array_merge([
        '@context' => 'https://schema.org',
    ], seo_publisher_organization());
    if ($sameAs) $org['sameAs'] = array_values($sameAs);
    ?>
    <script type="application/ld+json"><?php echo json_encode($website, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <script type="application/ld+json"><?php echo json_encode($org, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <?php
}

/**
 * Generic OG+Twitter block for list pages (category, source, cluster,
 * gallery, timelines). The page-specific data comes from the caller;
 * this centralises the tag shape.
 *
 * @param string $title      Page title (without site name suffix)
 * @param string $desc       Meta description (<= 160 chars ideally)
 * @param string $canonical  Canonical URL (absolute)
 * @param string $image      Absolute URL to an image (optional)
 * @param string $ogType     e.g. 'website', 'article' — default 'website'
 */
function render_list_seo(string $title, string $desc, string $canonical,
                         string $image = '', string $ogType = 'website'): void {
    $siteName = getSetting('site_name', SITE_NAME);
    $fullTitle = $title . ' — ' . $siteName;
    $img       = $image !== '' ? abs_url($image) : seo_default_og_image();
    $twitter   = seo_twitter_handle();
    ?>
    <link rel="canonical" href="<?php echo e($canonical); ?>">
    <meta property="og:type" content="<?php echo e($ogType); ?>">
    <meta property="og:site_name" content="<?php echo e($siteName); ?>">
    <meta property="og:title" content="<?php echo e($fullTitle); ?>">
    <meta property="og:description" content="<?php echo e($desc); ?>">
    <meta property="og:url" content="<?php echo e($canonical); ?>">
    <meta property="og:image" content="<?php echo e($img); ?>">
    <meta property="og:locale" content="ar_AR">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($fullTitle); ?>">
    <meta name="twitter:description" content="<?php echo e($desc); ?>">
    <meta name="twitter:image" content="<?php echo e($img); ?>">
    <?php if ($twitter): ?>
    <meta name="twitter:site" content="<?php echo e($twitter); ?>">
    <?php endif; ?>
    <?php
}

/**
 * BreadcrumbList JSON-LD.
 *
 * Google uses this to render breadcrumb rows in search results instead
 * of the raw URL. The last item should have a name but no href (it's
 * the current page) — callers pass it that way.
 *
 * @param array $items  Ordered list of ['name' => ..., 'url' => ...].
 *                      The last entry can omit 'url' (current page).
 */
function render_breadcrumb(array $items): void {
    if (!$items) return;
    $list = [];
    foreach (array_values($items) as $i => $it) {
        $node = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => (string)($it['name'] ?? ''),
        ];
        if (!empty($it['url'])) $node['item'] = abs_url((string)$it['url']);
        $list[] = $node;
    }
    $payload = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
    ?>
    <script type="application/ld+json"><?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <?php
}

/**
 * SpeakableSpecification — tells Google Assistant which CSS selectors
 * contain the spoken-summary-worthy text. Embedded as part of an
 * existing NewsArticle payload; callers drop the returned array into
 * the `speakable` key before json_encode'ing the article.
 *
 * Defaults cover the common h1 + summary / first paragraph shape.
 */
function seo_speakable_spec(array $cssSelectors = ['.article-title', '.article-summary', 'article h1', 'article p:first-of-type']): array {
    return [
        '@type'        => 'SpeakableSpecification',
        'cssSelector'  => array_values($cssSelectors),
    ];
}

/**
 * CollectionPage JSON-LD for list pages (category, source, topic,
 * cluster). Optionally embeds an ItemList of the first N articles so
 * Google can surface them directly.
 *
 * @param string $name       The page's display title
 * @param string $desc       Meta-description-length blurb
 * @param string $url        Canonical absolute URL
 * @param array  $articles   Optional — rows with id/slug/title/published_at
 */
function render_collection_ld(string $name, string $desc, string $url, array $articles = []): void {
    $payload = [
        '@context'   => 'https://schema.org',
        '@type'      => 'CollectionPage',
        'name'       => $name,
        'description'=> $desc,
        'url'        => $url,
        'inLanguage' => 'ar',
        'isPartOf'   => [
            '@type' => 'WebSite',
            'name'  => getSetting('site_name', SITE_NAME),
            'url'   => rtrim(SITE_URL, '/') . '/',
        ],
    ];
    if ($articles) {
        $items = [];
        $i = 0;
        foreach ($articles as $a) {
            if (empty($a['id']) || empty($a['title'])) continue;
            $i++;
            if ($i > 20) break;
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i,
                'url'      => abs_url('/' . articleUrl($a)),
                'name'     => (string)$a['title'],
            ];
        }
        if ($items) {
            $payload['mainEntity'] = [
                '@type'           => 'ItemList',
                'numberOfItems'   => count($items),
                'itemListElement' => $items,
            ];
        }
    }
    ?>
    <script type="application/ld+json"><?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <?php
}
