<?php

namespace wideweb\aiseoaudit\services;

use wideweb\aiseoaudit\Plugin;
use craft\base\Component;

class UrlDiscoveryService extends Component
{
    /**
     * @return string[]
     */
    public function discoverFromSitemap(string $sitemapUrl, int $maxUrls = 5000): array
    {
        $maxUrls = max(1, $maxUrls);
        $seenSitemaps = [];
        $collected = [];
        $rootHost = strtolower((string) (parse_url($sitemapUrl, PHP_URL_HOST) ?? ''));
        $this->crawlSitemap($sitemapUrl, $seenSitemaps, $collected, $maxUrls, $rootHost);

        return array_values(array_keys($collected));
    }

    /**
     * @param array<string, bool> $seenSitemaps
     * @param array<string, bool> $collectedUrls
     */
    private function crawlSitemap(
        string $sitemapUrl,
        array &$seenSitemaps,
        array &$collectedUrls,
        int $maxUrls,
        string $rootHost
    ): void {
        if (isset($seenSitemaps[$sitemapUrl]) || count($collectedUrls) >= $maxUrls) {
            return;
        }

        $seenSitemaps[$sitemapUrl] = true;

        try {
            $xml = Plugin::getInstance()->pageFetcher->fetch($sitemapUrl);
        } catch (\Throwable) {
            return;
        }

        if (trim($xml) === '') {
            return;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return;
        }

        $xpath = new \DOMXPath($document);
        $rootName = strtolower($document->documentElement?->localName ?? '');

        if ($rootName === 'sitemapindex') {
            $nodes = $xpath->query('/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]');
            if ($nodes === false) {
                return;
            }

            foreach ($nodes as $node) {
                $candidate = trim((string) $node->textContent);
                if ($candidate === '') {
                    continue;
                }
                $this->crawlSitemap($candidate, $seenSitemaps, $collectedUrls, $maxUrls, $rootHost);
                if (count($collectedUrls) >= $maxUrls) {
                    break;
                }
            }

            return;
        }

        $nodes = $xpath->query('/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (count($collectedUrls) >= $maxUrls) {
                break;
            }

            $candidate = trim((string) $node->textContent);
            if ($candidate === '') {
                continue;
            }

            if ($this->isCrawlablePageUrl($candidate, $rootHost)) {
                $collectedUrls[$candidate] = true;
            }
        }
    }

    private function isCrawlablePageUrl(string $url, string $rootHost): bool
    {
        $parts = parse_url($url);

        if (!$parts) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ($rootHost !== '' && $host !== $rootHost)) {
            return false;
        }

        $path = strtolower((string) ($parts['path'] ?? ''));
        if ($path === '') {
            return true;
        }

        if (str_contains($path, '/assets/') || str_contains($path, '/uploads/')) {
            return false;
        }

        $assetExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z',
            'mp4', 'mov', 'avi', 'webm', 'mp3', 'wav',
            'css', 'js', 'json', 'xml', 'txt', 'csv',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
        ];
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, $assetExtensions, true)) {
            return false;
        }

        return true;
    }
}
