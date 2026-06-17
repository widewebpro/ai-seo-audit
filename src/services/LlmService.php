<?php

namespace wideweb\aiseoaudit\services;

use wideweb\aiseoaudit\helpers\HttpClientHelper;
use wideweb\aiseoaudit\models\Settings;
use wideweb\aiseoaudit\Plugin;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Exception\ClientException;
use yii\base\Exception;

class LlmService extends Component
{
    private const DEFAULT_SYSTEM_PROMPT = 'SEO auditor. Reply with JSON only: {"score":0-100,"analysis":"brief markdown, max 5 bullets"}. No preamble.';

    /**
     * One LLM request per page. Input is a compact SEO snapshot, not full HTML.
     *
     * @return array{score: int|null, analysis: string}
     * @throws Exception
     */
    public function analyzePage(string $url, string $entryTitle, string $seoSnapshot): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $provider = $this->resolveProvider((string) (App::env('AI_SEO_AUDIT_LLM_PROVIDER') ?: $settings->llmProvider));
        $apiKey = trim((string) (App::env('AI_SEO_AUDIT_API_KEY') ?: $settings->apiKey));
        $modelVersion = trim((string) (App::env('AI_SEO_AUDIT_MODEL') ?: $settings->modelVersion));
        $apiBase = trim((string) (App::env('AI_SEO_AUDIT_API_BASE') ?: $settings->apiBaseUrl));
        $siteUrl = App::env('PRIMARY_SITE_URL') ?: App::env('CRAFT_WEB_URL') ?: 'https://example.com';
        $maxTokens = $settings->maxResponseTokens > 0 ? $settings->maxResponseTokens : 512;

        if ($apiKey === '') {
            throw new Exception('LLM API key is not configured.');
        }

        if ($modelVersion === '') {
            throw new Exception('LLM model is not configured.');
        }

        if ($apiBase === '') {
            $apiBase = $this->getDefaultApiBase($provider, $settings->geminiApiVersion);
        }

        $prompt = $this->buildPrompt($url, $entryTitle, $seoSnapshot);
        [$uri, $headers, $payload] = $this->buildProviderRequest(
            $provider,
            $apiBase,
            $apiKey,
            $modelVersion,
            $prompt,
            $siteUrl,
            $maxTokens,
            $settings
        );

        $client = HttpClientHelper::create([
            'timeout' => 120,
            'headers' => $headers,
        ]);

        $response = $this->requestWithRetry($client, $uri, $payload);

        $body = Json::decode((string) $response->getBody());
        $content = $this->extractResponseContent($provider, $body);

        if (!is_string($content) || $content === '') {
            throw new Exception($this->extractProviderError($body));
        }

        return $this->parseAnalysisResponse($content);
    }

    public function supportsPdfReportOutput(): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $provider = $this->resolveProvider((string) (App::env('AI_SEO_AUDIT_LLM_PROVIDER') ?: $settings->llmProvider));
        $modelVersion = strtolower(trim((string) (App::env('AI_SEO_AUDIT_MODEL') ?: $settings->modelVersion)));

        $forcePdf = App::env('AI_SEO_AUDIT_FORCE_PDF_EXPORT');
        if ($forcePdf !== null && $forcePdf !== '') {
            return filter_var($forcePdf, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        }

        $pdfModelsRaw = trim((string) App::env('AI_SEO_AUDIT_PDF_MODELS'));
        if ($pdfModelsRaw !== '') {
            $pdfModelHints = array_filter(array_map('trim', explode(',', strtolower($pdfModelsRaw))));
            foreach ($pdfModelHints as $hint) {
                if ($hint !== '' && str_contains($modelVersion, $hint)) {
                    return true;
                }
            }
        }

        if (str_contains($modelVersion, 'pdf')) {
            return true;
        }

        return $provider === 'gemini' && (
            str_contains($modelVersion, '2.5-pro')
            || str_contains($modelVersion, '2.5-flash')
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestWithRetry(\GuzzleHttp\Client $client, string $uri, array $payload, int $maxAttempts = 3): \Psr\Http\Message\ResponseInterface
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $client->post($uri, ['json' => $payload]);
            } catch (ClientException $e) {
                $lastException = $e;
                $status = $e->getResponse()?->getStatusCode();

                if ($status !== 429 || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $retryAfter = (int) ($e->getResponse()?->getHeaderLine('Retry-After') ?: 0);
                $wait = $retryAfter > 0 ? $retryAfter : 30 * $attempt;
                sleep($wait);
            }
        }

        throw $lastException ?? new Exception('LLM request failed.');
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: array<string, mixed>}
     * @throws Exception
     */
    private function buildProviderRequest(
        string $provider,
        string $apiBase,
        string $apiKey,
        string $modelVersion,
        string $prompt,
        string $siteUrl,
        int $maxTokens,
        Settings $settings
    ): array {
        $normalizedBase = rtrim($apiBase, '/');

        $sharedPayload = [
            'temperature' => 0.1,
            'max_tokens' => $maxTokens,
        ];

        switch ($provider) {
            case 'openrouter':
            case 'openai':
                $headers = [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ];

                if ($provider === 'openrouter') {
                    $headers['HTTP-Referer'] = $siteUrl;
                    $headers['X-Title'] = 'AI SEO Audit';
                } else {
                    if ($settings->openAiOrganization !== '') {
                        $headers['OpenAI-Organization'] = $settings->openAiOrganization;
                    }

                    if ($settings->openAiProject !== '') {
                        $headers['OpenAI-Project'] = $settings->openAiProject;
                    }
                }

                return [
                    $normalizedBase . '/chat/completions',
                    $headers,
                    [
                        'model' => $modelVersion,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => self::DEFAULT_SYSTEM_PROMPT,
                            ],
                            [
                                'role' => 'user',
                                'content' => $prompt,
                            ],
                        ],
                    ] + $sharedPayload,
                ];

            case 'anthropic':
                return [
                    $normalizedBase . '/messages',
                    [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => $settings->anthropicVersion ?: '2023-06-01',
                        'Content-Type' => 'application/json',
                    ],
                    [
                        'model' => $modelVersion,
                        'system' => self::DEFAULT_SYSTEM_PROMPT,
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $prompt,
                            ],
                        ],
                    ] + $sharedPayload,
                ];

            case 'gemini':
                return [
                    $normalizedBase . '/models/' . rawurlencode($modelVersion) . ':generateContent?key=' . rawurlencode($apiKey),
                    [
                        'Content-Type' => 'application/json',
                    ],
                    [
                        'systemInstruction' => [
                            'parts' => [
                                ['text' => self::DEFAULT_SYSTEM_PROMPT],
                            ],
                        ],
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => $maxTokens,
                        ],
                    ],
                ];
        }

        throw new Exception('Unsupported LLM provider: ' . $provider);
    }

    private function resolveProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        if (in_array($provider, ['openrouter', 'openai', 'anthropic', 'gemini'], true)) {
            return $provider;
        }

        return 'openrouter';
    }

    private function getDefaultApiBase(string $provider, string $geminiApiVersion): string
    {
        switch ($provider) {
            case 'openai':
                return 'https://api.openai.com/v1';
            case 'anthropic':
                return 'https://api.anthropic.com/v1';
            case 'gemini':
                $version = trim($geminiApiVersion) ?: 'v1beta';

                return 'https://generativelanguage.googleapis.com/' . ltrim($version, '/');
            case 'openrouter':
            default:
                return 'https://openrouter.ai/api/v1';
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractResponseContent(string $provider, array $body): ?string
    {
        switch ($provider) {
            case 'openrouter':
            case 'openai':
                return is_string($body['choices'][0]['message']['content'] ?? null)
                    ? $body['choices'][0]['message']['content']
                    : null;

            case 'anthropic':
                $contentBlocks = $body['content'] ?? null;
                if (!is_array($contentBlocks)) {
                    return null;
                }

                foreach ($contentBlocks as $block) {
                    if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                        return $block['text'];
                    }
                }

                return null;

            case 'gemini':
                $parts = $body['candidates'][0]['content']['parts'] ?? null;
                if (!is_array($parts)) {
                    return null;
                }

                foreach ($parts as $part) {
                    if (is_array($part) && is_string($part['text'] ?? null) && $part['text'] !== '') {
                        return $part['text'];
                    }
                }

                return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractProviderError(array $body): string
    {
        $error = $body['error'] ?? null;

        if (is_array($error) && is_string($error['message'] ?? null) && $error['message'] !== '') {
            return $error['message'];
        }

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return 'Invalid LLM response.';
    }

    /**
     * @return array{score: int|null, analysis: string}
     */
    private function parseAnalysisResponse(string $content): array
    {
        $content = trim($content);

        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            try {
                $parsed = Json::decode($matches[0]);
                if (is_array($parsed)) {
                    return [
                        'score' => isset($parsed['score']) ? (int) $parsed['score'] : null,
                        'analysis' => (string) ($parsed['analysis'] ?? $content),
                    ];
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return [
            'score' => null,
            'analysis' => $content,
        ];
    }

    private function buildPrompt(string $url, string $entryTitle, string $seoSnapshot): string
    {
        return "Audit SEO. URL: {$url}\nCraft entry title: {$entryTitle}\n\nExtracted page signals:\n{$seoSnapshot}";
    }
}
