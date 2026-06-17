<?php

namespace wideweb\aiseoaudit\services;

use wideweb\aiseoaudit\records\AuditExternalMetricRecord;
use wideweb\aiseoaudit\records\AuditResultRecord;
use wideweb\aiseoaudit\records\AuditRunRecord;
use wideweb\aiseoaudit\records\AuditUrlRecord;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;

class ExternalMetricsService extends Component
{
    private const FULL_COVERAGE_MAX_URLS = 200;
    private const PSI_MAX_PER_RUN = 150;
    private const HTML_VALIDATION_MAX_PER_RUN = 120;
    private const SITE_POLICY_MAX_PER_RUN = 250;

    /** @var array<string, array<string, mixed>> */
    private array $sitePolicyCache = [];

    /**
     * @return array<string, mixed>
     */
    public function collectForResult(AuditResultRecord $result, int $auditRunId): array
    {
        $run = AuditRunRecord::findOne($auditRunId);
        $totalUrls = max(1, (int) ($run?->totalEntries ?? 0));

        $signals = [
            'hasPsiMetrics' => false,
            'psiAttempted' => false,
            'psiStatus' => 'not_attempted',
            'psiHttpStatus' => 0,
            'psiMissingReason' => '',
            'hasHtmlValidationMetrics' => false,
            'hasSitePolicyMetrics' => false,
            'externalChecksAttempted' => false,
            'externalChecksSucceeded' => false,
            'externalChecksSampledOut' => false,
            'externalUrlLikelyPrivate' => false,
            'externalMetricsNote' => '',
            'performanceScore' => 0.0,
            'lcpMs' => 0.0,
            'inpMs' => 0.0,
            'cls' => 0.0,
            'poorCwv' => false,
            'htmlErrorCount' => 0,
            'htmlWarningCount' => 0,
            'htmlValidatorUrl' => '',
            'htmlErrorExamples' => [],
            'htmlWarningExamples' => [],
            'robotsTxtReachable' => false,
            'robotsDisallowAll' => false,
            'robotsBlockedByPath' => false,
            'sitemapDirectivePresent' => false,
            'sitemapReachable' => false,
        ];

        if ($result->url === '') {
            return $signals;
        }

        $signals['externalUrlLikelyPrivate'] = $this->isLikelyPrivateUrl($result->url);
        if ($signals['externalUrlLikelyPrivate']) {
            $signals['externalMetricsNote'] = 'url_not_public';
            return $signals;
        }

        $psiApiKey = $this->resolvePsiApiKey();
        if ($psiApiKey !== '') {
            if ($this->shouldCollectSource($auditRunId, $result->url, 'psi_public', $totalUrls, self::PSI_MAX_PER_RUN)) {
                $signals['externalChecksAttempted'] = true;
                $signals['psiAttempted'] = true;
                $psiResult = $this->fetchPageSpeedMetrics($result->url, $psiApiKey, 'mobile');
                if ((bool) ($psiResult['ok'] ?? false)) {
                    $psiMetrics = (array) ($psiResult['metrics'] ?? []);
                    $signals = array_merge($signals, $psiMetrics);
                    $signals['hasPsiMetrics'] = true;
                    $signals['psiStatus'] = 'ok';
                    $this->upsertMetric($auditRunId, (int) $result->id, 'psi_public', $psiMetrics);
                } else {
                    $signals['psiStatus'] = (string) ($psiResult['status'] ?? 'failed');
                    $signals['psiHttpStatus'] = (int) ($psiResult['httpStatus'] ?? 0);
                    $signals['psiMissingReason'] = (string) ($psiResult['reason'] ?? 'request_failed');
                }
            } else {
                $signals['externalChecksSampledOut'] = true;
                $signals['psiStatus'] = 'sampled_out';
                $signals['psiMissingReason'] = 'sampled_out';
            }
        } else {
            $signals['psiStatus'] = 'missing_api_key';
            $signals['psiMissingReason'] = 'missing_api_key';
        }

        if ($this->shouldCollectSource($auditRunId, $result->url, 'w3c_nu', $totalUrls, self::HTML_VALIDATION_MAX_PER_RUN)) {
            $signals['externalChecksAttempted'] = true;
            $htmlValidationMetrics = $this->fetchHtmlValidationMetrics($result->url);
            if ($htmlValidationMetrics !== []) {
                $signals = array_merge($signals, $htmlValidationMetrics);
                $signals['hasHtmlValidationMetrics'] = true;
                $this->upsertMetric($auditRunId, (int) $result->id, 'w3c_nu', $htmlValidationMetrics);
            }
        } else {
            $signals['externalChecksSampledOut'] = true;
        }

        if ($this->shouldCollectSource($auditRunId, $result->url, 'site_policy', $totalUrls, self::SITE_POLICY_MAX_PER_RUN)) {
            $signals['externalChecksAttempted'] = true;
            $sitePolicyMetrics = $this->fetchSitePolicyMetrics($result->url);
            if ($sitePolicyMetrics !== []) {
                $signals = array_merge($signals, $sitePolicyMetrics);
                $signals['hasSitePolicyMetrics'] = true;
                $this->upsertMetric($auditRunId, (int) $result->id, 'site_policy', $sitePolicyMetrics);
            }
        } else {
            $signals['externalChecksSampledOut'] = true;
        }

        $signals['externalChecksSucceeded'] = (bool) (
            $signals['hasPsiMetrics']
            || $signals['hasHtmlValidationMetrics']
            || $signals['hasSitePolicyMetrics']
        );

        if (!$signals['hasPsiMetrics'] && $signals['psiAttempted'] && $signals['psiMissingReason'] === '') {
            $signals['psiMissingReason'] = 'request_failed';
        }

        if (!$signals['externalChecksSucceeded'] && $signals['externalChecksAttempted']) {
            $signals['externalMetricsNote'] = 'endpoint_unreachable_or_limited';
        } elseif ($signals['externalChecksSampledOut']) {
            $signals['externalMetricsNote'] = 'sampled_out';
        } elseif ($psiApiKey === '' && $signals['hasHtmlValidationMetrics'] && $signals['hasSitePolicyMetrics']) {
            $signals['externalMetricsNote'] = 'psi_api_key_missing';
        }

        return $signals;
    }

    private function isLikelyPrivateUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        $privateHostSuffixes = ['.local', '.test', '.invalid', '.internal', '.localhost', '.ddev.site'];
        foreach ($privateHostSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }

    private function shouldCollectSource(
        int $auditRunId,
        string $url,
        string $source,
        int $totalUrls,
        int $maxPerRun
    ): bool {
        if ($totalUrls <= self::FULL_COVERAGE_MAX_URLS) {
            return true;
        }

        $alreadyCollected = $this->countSourceRowsForRun($auditRunId, $source);
        if ($alreadyCollected >= $maxPerRun) {
            return false;
        }

        $ratio = min(1.0, $maxPerRun / max(1, $totalUrls));
        $normalizedUrl = strtolower(rtrim($url, '/'));
        $bucket = (int) sprintf('%u', crc32($normalizedUrl)) % 10000;

        return $bucket < (int) round($ratio * 10000);
    }

    private function countSourceRowsForRun(int $auditRunId, string $source): int
    {
        if (!Craft::$app->getDb()->tableExists(AuditExternalMetricRecord::tableName())) {
            return 0;
        }

        return (int) (new Query())
            ->from([AuditExternalMetricRecord::tableName()])
            ->where([
                'auditRunId' => $auditRunId,
                'source' => $source,
            ])
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSignalsForResult(int $auditRunId, int $auditResultId): array
    {
        if (!Craft::$app->getDb()->tableExists(AuditExternalMetricRecord::tableName())) {
            return [];
        }

        $rows = AuditExternalMetricRecord::find()
            ->where([
                'auditRunId' => $auditRunId,
                'auditResultId' => $auditResultId,
            ])
            ->all();

        $merged = [];
        foreach ($rows as $row) {
            if (!is_string($row->metricsJson) || trim($row->metricsJson) === '') {
                continue;
            }
            try {
                $decoded = Json::decode($row->metricsJson);
                if (is_array($decoded)) {
                    $merged = array_merge($merged, $decoded);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPageSpeedMetrics(string $url, string $apiKey, string $strategy = 'mobile'): array
    {
        $strategy = strtolower(trim($strategy)) === 'desktop' ? 'desktop' : 'mobile';
        $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get($endpoint, [
                'query' => [
                    'url' => $url,
                    'strategy' => $strategy,
                    'key' => $apiKey,
                ],
                'http_errors' => false,
                'timeout' => 25,
            ]);

            if ($response->getStatusCode() >= 400) {
                $httpStatus = (int) $response->getStatusCode();
                $reason = 'http_error';
                try {
                    $decodedError = Json::decode((string) $response->getBody());
                    $apiMessage = strtolower((string) ($decodedError['error']['message'] ?? ''));
                    if ($httpStatus === 429 || str_contains($apiMessage, 'quota') || str_contains($apiMessage, 'rate')) {
                        $reason = 'quota_exceeded';
                    }
                } catch (\Throwable) {
                    // Keep default reason.
                }

                return [
                    'ok' => false,
                    'status' => 'http_error',
                    'httpStatus' => $httpStatus,
                    'reason' => $reason,
                ];
            }

            $decoded = Json::decode((string) $response->getBody());
            if (!is_array($decoded)) {
                return [
                    'ok' => false,
                    'status' => 'invalid_json',
                    'httpStatus' => 200,
                    'reason' => 'invalid_json',
                ];
            }

            $lighthouseResult = is_array($decoded['lighthouseResult'] ?? null) ? $decoded['lighthouseResult'] : [];
            if ($lighthouseResult === []) {
                return [
                    'ok' => false,
                    'status' => 'missing_lighthouse_result',
                    'httpStatus' => 200,
                    'reason' => 'missing_lighthouse_result',
                ];
            }
            $categories = is_array($lighthouseResult['categories'] ?? null) ? $lighthouseResult['categories'] : [];
            $audits = is_array($lighthouseResult['audits'] ?? null) ? $lighthouseResult['audits'] : [];
            $performanceScore = isset($categories['performance']['score']) ? (float) $categories['performance']['score'] * 100.0 : 0.0;

            $lcpSeconds = (float) (($audits['largest-contentful-paint']['numericValue'] ?? 0.0) / 1000.0);
            $inpMs = (float) ($audits['interaction-to-next-paint']['numericValue'] ?? 0.0);
            $cls = (float) ($audits['cumulative-layout-shift']['numericValue'] ?? 0.0);
            $poorCwv = ($lcpSeconds > 4.0) || ($inpMs > 500.0) || ($cls > 0.25);

            return [
                'ok' => true,
                'status' => 'ok',
                'httpStatus' => 200,
                'reason' => '',
                'metrics' => [
                    'performanceScore' => $performanceScore,
                    'lcpMs' => (float) ($audits['largest-contentful-paint']['numericValue'] ?? 0.0),
                    'inpMs' => $inpMs,
                    'cls' => $cls,
                    'poorCwv' => $poorCwv,
                ],
            ];
        } catch (\Throwable) {
            return [
                'ok' => false,
                'status' => 'exception',
                'httpStatus' => 0,
                'reason' => 'request_exception',
            ];
        }
    }

    private function resolvePsiApiKey(): string
    {
        $envValue = trim((string) Craft::parseEnv('$AI_SEO_AUDIT_PSI_API_KEY'));
        if ($envValue !== '' && str_starts_with($envValue, '$') === false) {
            return $envValue;
        }

        $settings = \wideweb\aiseoaudit\Plugin::getInstance()->getSettings();
        return trim((string) ($settings->pageSpeedApiKey ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchHtmlValidationMetrics(string $url): array
    {
        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get('https://validator.w3.org/nu/', [
                'query' => [
                    'doc' => $url,
                    'out' => 'json',
                ],
                'headers' => [
                    'User-Agent' => 'AI-SEO-Audit/1.0',
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
                'timeout' => 20,
            ]);

            if ($response->getStatusCode() >= 400) {
                return [];
            }

            $decoded = Json::decode((string) $response->getBody());
            if (!is_array($decoded)) {
                return [];
            }

            $messages = is_array($decoded['messages'] ?? null) ? $decoded['messages'] : [];
            $errors = 0;
            $warnings = 0;
            $errorExamples = [];
            $warningExamples = [];

            foreach ($messages as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $type = strtolower((string) ($message['type'] ?? ''));
                $subType = strtolower((string) ($message['subType'] ?? ''));
                $text = trim((string) ($message['message'] ?? ''));
                if ($text === '') {
                    continue;
                }
                if ($type === 'error') {
                    $errors++;
                    if (count($errorExamples) < 3) {
                        $errorExamples[] = $text;
                    }
                    continue;
                }
                if ($type === 'info' && $subType === 'warning') {
                    $warnings++;
                    if (count($warningExamples) < 3) {
                        $warningExamples[] = $text;
                    }
                }
            }

            return [
                'htmlErrorCount' => $errors,
                'htmlWarningCount' => $warnings,
                'htmlValidatorUrl' => 'https://validator.w3.org/nu/?doc=' . rawurlencode($url),
                'htmlErrorExamples' => $errorExamples,
                'htmlWarningExamples' => $warningExamples,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSitePolicyMetrics(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return [];
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        $cacheKey = $scheme . '://' . $host;

        if (!isset($this->sitePolicyCache[$cacheKey])) {
            $robotsUrl = $cacheKey . '/robots.txt';
            $sitemapUrl = $cacheKey . '/sitemap.xml';
            $robotsBody = $this->fetchText($robotsUrl);
            $sitemapBody = $this->fetchText($sitemapUrl);

            $robotsParsed = $this->parseRobots($robotsBody);
            $this->sitePolicyCache[$cacheKey] = [
                'robotsTxtReachable' => $robotsBody !== null,
                'robotsDisallowAll' => (bool) ($robotsParsed['disallowAll'] ?? false),
                'disallowPatterns' => (array) ($robotsParsed['disallowPatterns'] ?? []),
                'sitemapDirectivePresent' => (bool) ($robotsParsed['sitemapDirectivePresent'] ?? false),
                'sitemapReachable' => $sitemapBody !== null,
            ];
        }

        $hostSignals = $this->sitePolicyCache[$cacheKey];
        $disallowPatterns = (array) ($hostSignals['disallowPatterns'] ?? []);

        return [
            'robotsTxtReachable' => (bool) ($hostSignals['robotsTxtReachable'] ?? false),
            'robotsDisallowAll' => (bool) ($hostSignals['robotsDisallowAll'] ?? false),
            'robotsBlockedByPath' => $this->pathBlockedByRules($path, $disallowPatterns),
            'sitemapDirectivePresent' => (bool) ($hostSignals['sitemapDirectivePresent'] ?? false),
            'sitemapReachable' => (bool) ($hostSignals['sitemapReachable'] ?? false),
        ];
    }

    private function fetchText(string $url): ?string
    {
        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'AI-SEO-Audit/1.0',
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            return (string) $response->getBody();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRobots(?string $robotsBody): array
    {
        if ($robotsBody === null) {
            return [
                'disallowAll' => false,
                'disallowPatterns' => [],
                'sitemapDirectivePresent' => false,
            ];
        }

        $lines = preg_split('/\R/', $robotsBody) ?: [];
        $inWildcardSection = false;
        $disallowPatterns = [];
        $sitemapDirectivePresent = false;

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if ($line === '') {
                continue;
            }

            if (stripos($line, 'User-agent:') === 0) {
                $agent = trim(substr($line, strlen('User-agent:')));
                $inWildcardSection = ($agent === '*');
                continue;
            }

            if (stripos($line, 'Sitemap:') === 0) {
                $sitemapDirectivePresent = true;
                continue;
            }

            if ($inWildcardSection && stripos($line, 'Disallow:') === 0) {
                $pattern = trim(substr($line, strlen('Disallow:')));
                if ($pattern !== '') {
                    $disallowPatterns[] = $pattern;
                }
            }
        }

        return [
            'disallowAll' => in_array('/', $disallowPatterns, true),
            'disallowPatterns' => array_values(array_unique($disallowPatterns)),
            'sitemapDirectivePresent' => $sitemapDirectivePresent,
        ];
    }

    /**
     * @param string[] $disallowPatterns
     */
    private function pathBlockedByRules(string $path, array $disallowPatterns): bool
    {
        foreach ($disallowPatterns as $pattern) {
            $regex = preg_quote((string) $pattern, '/');
            $regex = str_replace('\*', '.*', $regex);
            if (str_ends_with($pattern, '$')) {
                $regex = substr($regex, 0, -2) . '$';
            } else {
                $regex .= '.*';
            }

            if (preg_match('/^' . $regex . '/', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function upsertMetric(int $auditRunId, int $auditResultId, string $source, array $metrics): void
    {
        if (!Craft::$app->getDb()->tableExists(AuditExternalMetricRecord::tableName())) {
            return;
        }

        $auditUrlId = null;
        if (Craft::$app->getDb()->tableExists(AuditUrlRecord::tableName())) {
            $auditUrl = AuditUrlRecord::find()
                ->where(['auditResultId' => $auditResultId])
                ->one();
            $auditUrlId = $auditUrl?->id;
        }

        $record = AuditExternalMetricRecord::find()
            ->where([
                'auditRunId' => $auditRunId,
                'auditResultId' => $auditResultId,
                'source' => $source,
            ])
            ->one() ?? new AuditExternalMetricRecord();

        $record->auditRunId = $auditRunId;
        $record->auditUrlId = $auditUrlId;
        $record->auditResultId = $auditResultId;
        $record->source = $source;
        $record->metricsJson = Json::encode($metrics);
        $record->dateBucket = gmdate('Y-m-d');
        $record->dateUpdated = DateTimeHelper::now();
        if (!$record->dateCreated) {
            $record->dateCreated = $record->dateUpdated;
        }
        $record->save(false);
    }

}
