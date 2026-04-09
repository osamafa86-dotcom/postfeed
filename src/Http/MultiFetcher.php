<?php
declare(strict_types=1);

namespace NewsFlow\Http;

/**
 * Parallel HTTP fetcher built on curl_multi, with a concurrency cap.
 */
final class MultiFetcher
{
    /**
     * Browser-like defaults because many Arabic news sites block or serve
     * a stripped page to generic bot UAs. Timeouts bumped to match the
     * single-request fetcher in includes/article_fetch.php.
     */
    public function __construct(
        private int $timeout = 15,
        private int $connectTimeout = 8,
        private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
    ) {}

    /**
     * Fetch the HTML body for a single URL. Returns empty string on failure.
     */
    public function fetchOne(string $url): string
    {
        if ($url === '') return '';
        $ch = curl_init();
        curl_setopt_array($ch, $this->curlOptions($url));
        $html = curl_exec($ch);
        curl_close($ch);
        return is_string($html) ? $html : '';
    }

    /**
     * Fetch many URLs in parallel.
     *
     * @param string[] $urls
     * @return array<string, string> url => body (empty string on failure)
     */
    public function fetchMany(array $urls, int $concurrency = 10): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        $results = [];
        if (empty($urls)) return $results;

        $total = count($urls);
        $concurrency = max(1, min($concurrency, $total));
        $multi = curl_multi_init();
        $handles = [];
        $urlOf = [];
        $idx = 0;

        $addHandle = function (string $url) use (&$handles, &$urlOf, $multi) {
            $ch = curl_init();
            curl_setopt_array($ch, $this->curlOptions($url));
            $key = (int) $ch;
            $handles[$key] = $ch;
            $urlOf[$key] = $url;
            curl_multi_add_handle($multi, $ch);
        };

        while ($idx < $concurrency && $idx < $total) {
            $addHandle($urls[$idx++]);
        }

        $active = null;
        do {
            do {
                $status = curl_multi_exec($multi, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($active) curl_multi_select($multi, 1.0);

            while ($info = curl_multi_info_read($multi)) {
                $ch = $info['handle'];
                $key = (int) $ch;
                $url = $urlOf[$key] ?? '';
                $body = curl_multi_getcontent($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $results[$url] = ($http >= 200 && $http < 400 && $body) ? $body : '';

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                unset($handles[$key], $urlOf[$key]);

                if ($idx < $total) {
                    $addHandle($urls[$idx++]);
                    $active = 1;
                }
            }
        } while ($active && $status === CURLM_OK);

        curl_multi_close($multi);
        return $results;
    }

    /** @return array<int, mixed> */
    private function curlOptions(string $url): array
    {
        return [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ar,en-US;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
            ],
            // Many Arabic news sites ship old/misconfigured certs. We fall
            // back to verify-off rather than lose the whole page.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
        ];
    }
}
