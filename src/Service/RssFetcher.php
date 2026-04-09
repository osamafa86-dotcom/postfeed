<?php
declare(strict_types=1);

namespace NewsFlow\Service;

use PDO;
use NewsFlow\Database;
use NewsFlow\Http\MultiFetcher;
use NewsFlow\Repository\ArticleRepository;
use NewsFlow\Repository\SourceRepository;
use NewsFlow\Cache\FileCache;

/**
 * Fetches RSS feeds in parallel, parses them, deduplicates, then fetches
 * article source pages in a second parallel batch, then inserts.
 *
 * Procedural cron_rss.php delegates here after the refactor lands.
 */
final class RssFetcher
{
    public int $totalNew = 0;
    public int $totalErr = 0;

    public function __construct(
        private ArticleRepository $articles,
        private SourceRepository $sources,
        private MultiFetcher $http,
        private ?FileCache $cache = null,
        private ?PDO $db = null
    ) {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{new:int, errors:int, elapsed:float}
     */
    public function run(): array
    {
        $start = microtime(true);
        $sources = $this->sources->activeWithRss();
        if (empty($sources)) {
            return ['new' => 0, 'errors' => 0, 'elapsed' => 0.0];
        }

        // Phase 1: fetch all RSS feeds in parallel
        $urls = array_column($sources, 'rss_url');
        $bodies = $this->http->fetchMany($urls, 12);

        // Phase 2: parse and collect pending inserts
        $pending = [];
        $sourceErrors = [];
        $sourceCounts = [];

        foreach ($sources as $src) {
            $sid = (int) $src['id'];
            $sourceCounts[$sid] = 0;
            $body = $bodies[$src['rss_url']] ?? '';

            try {
                if ($body === '') {
                    throw new \RuntimeException('empty body');
                }
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($body);
                if ($xml === false) {
                    throw new \RuntimeException('XML parse failed');
                }

                $items = $this->extractItems($xml);
                foreach ($items as $item) {
                    if (empty($item['title'])) continue;
                    $title = trim(strip_tags($item['title']));
                    if ($this->articles->existsByTitleAndSource($title, $sid)) continue;

                    $excerpt = mb_substr(trim(strip_tags($item['description'] ?? '')), 0, 500);
                    $pending[] = [
                        'source_id'    => $sid,
                        'title'        => $title,
                        'slug'         => $this->slugify($title),
                        'excerpt'      => $excerpt,
                        'image_url'    => $item['image'] ?? '',
                        'source_url'   => trim((string)($item['link'] ?? '')),
                        'category_id'  => function_exists('detectCategory') ? detectCategory($this->db, $title . ' ' . $excerpt, (string)($item['category'] ?? '')) : 1,
                        'published_at' => $this->parseDate($item['pubDate'] ?? ''),
                    ];
                    $sourceCounts[$sid]++;
                }
            } catch (\Throwable $e) {
                $sourceErrors[$sid] = mb_substr($e->getMessage(), 0, 500);
                $this->totalErr++;
            }
        }

        // Phase 3: fetch article pages in parallel
        $pageUrls = array_filter(array_column($pending, 'source_url'));
        $pageHtmls = empty($pageUrls) ? [] : $this->http->fetchMany($pageUrls, 12);

        // Phase 4: insert
        foreach ($pending as $it) {
            $html = $pageHtmls[$it['source_url']] ?? '';
            $fullContent = '';
            $imageUrl = $it['image_url'];
            if ($html !== '' && function_exists('fetchArticleBodyFromHtml')) {
                $fullContent = fetchArticleBodyFromHtml($html);
                if ($imageUrl === '' && function_exists('extractArticleImage')) {
                    $imageUrl = extractArticleImage($html, $it['source_url']);
                }
            }
            // Retry once via the single-request fetcher: it has a SSL-off
            // fallback and different timing, and rescues sites that blanked
            // us on the parallel pass.
            if ($fullContent === '' && !empty($it['source_url']) && function_exists('fetchArticleBody')) {
                $fullContent = fetchArticleBody($it['source_url']);
            }
            if ($fullContent === '') {
                $fullContent = '<p>' . nl2br($it['excerpt']) . '</p>';
            }

            try {
                $this->articles->insert([
                    'title'        => $it['title'],
                    'slug'         => $it['slug'],
                    'excerpt'      => $it['excerpt'],
                    'content'      => $fullContent,
                    'image_url'    => $imageUrl,
                    'source_url'   => $it['source_url'],
                    'category_id'  => $it['category_id'],
                    'source_id'    => $it['source_id'],
                    'status'       => 'published',
                    'published_at' => $it['published_at'],
                ]);
                $this->totalNew++;
            } catch (\Throwable $e) {
                error_log('RssFetcher insert: ' . $e->getMessage());
            }
        }

        // Phase 5: update source tracking
        foreach ($sources as $src) {
            $sid = (int) $src['id'];
            $total = $this->articles->countBySource($sid);
            $this->sources->updateFetchState(
                $sid,
                $sourceErrors[$sid] ?? null,
                $sourceCounts[$sid] ?? 0,
                $total
            );
        }

        // Phase 6: invalidate cache
        if ($this->totalNew > 0 && $this->cache !== null) {
            $this->cache->flush();
        }

        return [
            'new'     => $this->totalNew,
            'errors'  => $this->totalErr,
            'elapsed' => round(microtime(true) - $start, 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function extractItems(\SimpleXMLElement $xml): array
    {
        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = [
                    'title'       => (string) $item->title,
                    'link'        => (string) $item->link,
                    'description' => (string) $item->description,
                    'pubDate'     => (string) $item->pubDate,
                    'image'       => function_exists('extractImage') ? extractImage($item) : '',
                    'category'    => (string) ($item->category ?? ''),
                ];
            }
        } elseif (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $link = '';
                if (isset($entry->link)) {
                    foreach ($entry->link as $l) {
                        if ((string) $l['rel'] === 'alternate' || $link === '') {
                            $link = (string) $l['href'];
                        }
                    }
                }
                $items[] = [
                    'title'       => (string) $entry->title,
                    'link'        => $link,
                    'description' => (string) ($entry->summary ?? $entry->content ?? ''),
                    'pubDate'     => (string) ($entry->published ?? $entry->updated ?? ''),
                    'image'       => '',
                    'category'    => '',
                ];
            }
        }
        return $items;
    }

    private function slugify(string $title): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\s-]/u', '', $title) ?? '';
        $slug = preg_replace('/\s+/', '-', trim($slug)) ?? '';
        return mb_substr($slug, 0, 200) . '-' . time() . rand(100, 999);
    }

    private function parseDate(string $raw): string
    {
        if ($raw !== '') {
            $ts = strtotime($raw);
            if ($ts) return date('Y-m-d H:i:s', $ts);
        }
        return date('Y-m-d H:i:s');
    }
}
