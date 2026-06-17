<?php

namespace wideweb\aiseoaudit\models;

use craft\base\Model;

class Settings extends Model
{
    public string $llmProvider = 'openrouter';

    public string $apiKey = '';

    public string $apiBaseUrl = '';

    public string $modelVersion = 'deepseek/deepseek-v4-flash:free';

    public string $openAiOrganization = '';

    public string $openAiProject = '';

    public string $anthropicVersion = '2023-06-01';

    public string $geminiApiVersion = 'v1beta';

    /** Seconds between LLM requests (helps free-tier rate limits). */
    public int $requestDelaySeconds = 20;

    /** Max tokens in the LLM response. */
    public int $maxResponseTokens = 512;

    /** Max characters of SEO snapshot sent to the LLM. */
    public int $maxSeoPayloadChars = 3500;

    /** When false, only fetch pages and build SEO snapshots (no API calls). */
    public bool $enableLlm = false;

    /** @var int[] */
    public array $selectedSectionIds = [];

    public bool $enableSitemapDiscovery = false;

    public string $sitemapUrl = '';

    public int $maxDiscoveredUrls = 5000;

    public string $pageSpeedApiKey = '';

    public function rules(): array
    {
        return [
            [['llmProvider', 'apiKey', 'apiBaseUrl', 'modelVersion', 'openAiOrganization', 'openAiProject', 'anthropicVersion', 'geminiApiVersion', 'sitemapUrl', 'pageSpeedApiKey'], 'string'],
            [['requestDelaySeconds', 'maxResponseTokens', 'maxSeoPayloadChars', 'maxDiscoveredUrls'], 'integer', 'min' => 0],
            [['enableLlm', 'enableSitemapDiscovery'], 'boolean'],
            [['selectedSectionIds'], 'safe'],
            [['llmProvider'], 'in', 'range' => ['openrouter', 'openai', 'anthropic', 'gemini']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'llmProvider' => 'LLM provider',
            'apiKey' => 'LLM API Key',
            'apiBaseUrl' => 'API base URL',
            'modelVersion' => 'Language model',
            'openAiOrganization' => 'OpenAI organization (optional)',
            'openAiProject' => 'OpenAI project (optional)',
            'anthropicVersion' => 'Anthropic API version',
            'geminiApiVersion' => 'Gemini API version',
            'requestDelaySeconds' => 'Delay between pages (seconds)',
            'maxResponseTokens' => 'Max response tokens',
            'maxSeoPayloadChars' => 'Max SEO data sent to LLM (chars)',
            'enableLlm' => 'Enable LLM analysis',
            'selectedSectionIds' => 'Sections to analyze',
            'enableSitemapDiscovery' => 'Enable sitemap URL discovery',
            'sitemapUrl' => 'Sitemap URL',
            'maxDiscoveredUrls' => 'Max URLs from sitemap',
            'pageSpeedApiKey' => 'PageSpeed Insights API key',
        ];
    }
}
