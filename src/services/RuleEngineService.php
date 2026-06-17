<?php

namespace wideweb\aiseoaudit\services;

use craft\base\Component;

class RuleEngineService extends Component
{
    /**
     * @param array<string, mixed> $signals
     * @return array<int, array<string, mixed>>
     */
    public function evaluate(array $signals): array
    {
        $issues = [];

        $title = trim((string) ($signals['title'] ?? ''));
        $metaDescription = trim((string) ($signals['metaDescription'] ?? ''));
        $canonicalUrl = trim((string) ($signals['canonicalUrl'] ?? ''));
        $robotsMeta = strtolower(trim((string) ($signals['robotsMeta'] ?? '')));
        $h1Count = (int) ($signals['h1Count'] ?? 0);
        $imagesWithoutAlt = (int) ($signals['imagesWithoutAlt'] ?? 0);
        $hasHtmlValidationMetrics = (bool) ($signals['hasHtmlValidationMetrics'] ?? false);
        $hasSitePolicyMetrics = (bool) ($signals['hasSitePolicyMetrics'] ?? false);
        $htmlErrorCount = (int) ($signals['htmlErrorCount'] ?? 0);
        $htmlWarningCount = (int) ($signals['htmlWarningCount'] ?? 0);
        $robotsBlockedByPath = (bool) ($signals['robotsBlockedByPath'] ?? false);
        $robotsDisallowAll = (bool) ($signals['robotsDisallowAll'] ?? false);
        $sitemapReachable = (bool) ($signals['sitemapReachable'] ?? false);
        $sitemapDirectivePresent = (bool) ($signals['sitemapDirectivePresent'] ?? false);

        if ($title === '') {
            $issues[] = $this->issue(
                'TITLE_MISSING',
                'high',
                'Page title is missing.',
                'Set a unique <title> that clearly describes the page topic.',
                'content'
            );
        } elseif (mb_strlen($title) > 65) {
            $issues[] = $this->issue(
                'TITLE_TOO_LONG',
                'medium',
                'Page title is likely too long for search snippets.',
                'Shorten the <title> to roughly 50-65 characters and keep the primary keyword near the start.',
                'content'
            );
        }

        if ($metaDescription === '') {
            $issues[] = $this->issue(
                'META_DESCRIPTION_MISSING',
                'medium',
                'Meta description is missing.',
                'Add a concise meta description (about 140-160 characters) that reflects search intent.',
                'content'
            );
        } elseif (mb_strlen($metaDescription) > 170) {
            $issues[] = $this->issue(
                'META_DESCRIPTION_TOO_LONG',
                'low',
                'Meta description is likely too long.',
                'Trim meta description to about 140-160 characters.',
                'content'
            );
        }

        if ($canonicalUrl === '') {
            $issues[] = $this->issue(
                'CANONICAL_MISSING',
                'medium',
                'Canonical URL is missing.',
                'Add <link rel="canonical"> to avoid duplicate indexing signals.',
                'dev'
            );
        }

        if ($h1Count <= 0) {
            $issues[] = $this->issue(
                'H1_MISSING',
                'high',
                'No H1 heading was found.',
                'Add one descriptive H1 heading that matches the page intent.',
                'content'
            );
        } elseif ($h1Count > 1) {
            $issues[] = $this->issue(
                'H1_MULTIPLE',
                'medium',
                'Multiple H1 headings were found.',
                'Use a single primary H1 and move additional top-level headings to H2.',
                'content'
            );
        }

        if ($imagesWithoutAlt > 0) {
            $issues[] = $this->issue(
                'IMAGES_WITHOUT_ALT',
                'low',
                'Images without alt text were detected.',
                'Add descriptive alt text for meaningful images; keep decorative images empty alt.',
                'content'
            );
        }

        if (str_contains($robotsMeta, 'noindex')) {
            $issues[] = $this->issue(
                'ROBOTS_NOINDEX',
                'high',
                'Page has noindex in robots meta.',
                'Remove noindex if this page should appear in search results.',
                'seo'
            );
        }

        if ($hasSitePolicyMetrics) {
            if ($robotsDisallowAll) {
                $issues[] = $this->issue(
                    'ROBOTS_DISALLOW_ALL',
                    'critical',
                    'robots.txt appears to disallow crawling for all user agents.',
                    'Update robots.txt so crawl-important sections are allowed for User-agent: *.',
                    'seo'
                );
            } elseif ($robotsBlockedByPath) {
                $issues[] = $this->issue(
                    'ROBOTS_PATH_BLOCKED',
                    'high',
                    'This URL path matches a Disallow rule in robots.txt.',
                    'Adjust robots.txt Disallow rules or move the page to an allowed path.',
                    'seo'
                );
            }

            if (!$sitemapDirectivePresent && !$sitemapReachable) {
                $issues[] = $this->issue(
                    'SITEMAP_NOT_DISCOVERABLE',
                    'low',
                    'No sitemap.xml endpoint or Sitemap directive was detected.',
                    'Expose sitemap.xml and reference it in robots.txt to improve URL discovery.',
                    'seo'
                );
            }
        }

        if ($hasHtmlValidationMetrics) {
            if ($htmlErrorCount >= 10) {
                $issues[] = $this->issue(
                    'HTML_VALIDATION_ERRORS',
                    'high',
                    'Multiple HTML validation errors were detected by W3C Nu Validator.',
                    'Fix critical HTML markup errors to improve rendering consistency and crawler parsing.',
                    'dev'
                );
            } elseif ($htmlErrorCount > 0) {
                $issues[] = $this->issue(
                    'HTML_VALIDATION_ERRORS',
                    'medium',
                    'HTML validation errors were detected by W3C Nu Validator.',
                    'Fix reported HTML markup errors, prioritizing template-level issues first.',
                    'dev'
                );
            } elseif ($htmlWarningCount >= 15) {
                $issues[] = $this->issue(
                    'HTML_VALIDATION_WARNINGS',
                    'low',
                    'A high number of HTML validation warnings were detected.',
                    'Review repeated warnings in templates and reduce invalid or deprecated markup patterns.',
                    'dev'
                );
            }
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $issueCode, string $severity, string $message, string $fixInstruction, string $ownerSuggestion): array
    {
        return [
            'issueCode' => $issueCode,
            'severity' => $severity,
            'message' => $message,
            'fixInstruction' => $fixInstruction,
            'ownerSuggestion' => $ownerSuggestion,
            'priorityScore' => $this->severityToPriority($severity),
        ];
    }

    private function severityToPriority(string $severity): int
    {
        return match ($severity) {
            'critical' => 95,
            'high' => 80,
            'medium' => 55,
            default => 30,
        };
    }
}
