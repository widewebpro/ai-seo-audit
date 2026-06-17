<?php

namespace wideweb\aiseoaudit\services;

use wideweb\aiseoaudit\helpers\HttpClientHelper;
use wideweb\aiseoaudit\helpers\SeoHtmlExtractor;
use wideweb\aiseoaudit\Plugin;
use craft\base\Component;
use craft\helpers\App;
use yii\base\Exception;

class PageFetcherService extends Component
{
    /**
     * Fetches rendered frontend HTML for a URL.
     *
     * @throws Exception
     */
    public function fetch(string $url): string
    {
        $client = HttpClientHelper::create([
            'timeout' => 60,
            'connect_timeout' => 15,
            'headers' => [
                'User-Agent' => 'AtlasNetwork-AiSeoAudit/1.0',
                'Accept' => 'text/html,application/xhtml+xml',
            ],
            'verify' => App::env('AI_SEO_AUDIT_VERIFY_SSL') !== 'false',
        ]);

        $response = $client->get($url);

        if ($response->getStatusCode() >= 400) {
            throw new Exception('HTTP ' . $response->getStatusCode() . ' for ' . $url);
        }

        $html = (string) $response->getBody();

        if ($html === '') {
            throw new Exception('Empty response body for ' . $url);
        }

        return $html;
    }

    /**
     * Compact SEO facts only — not full page HTML.
     */
    public function buildSeoSnapshot(string $html): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $max = $settings->maxSeoPayloadChars > 0 ? $settings->maxSeoPayloadChars : 3500;

        return SeoHtmlExtractor::extract($html, $max);
    }
}
