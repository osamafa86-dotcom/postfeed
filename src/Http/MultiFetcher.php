<?php
declare(strict_types=1);

namespace NewsFlow\Http;

/**
 * Parallel HTTP fetcher built on curl_multi, with a concurrency cap.
 */
final class MultiFetcher
{
    public function __construct(
        private int $timeout = 12,
        private int $connectTimeout = 6,
        private string $userAgent = 'Mozilla/5.0 (compatible; NewsFlow/1.0)'
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
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ];
    }
}
