<?php

namespace wideweb\aiseoaudit\services;

use craft\base\Component;

class IssuePrioritizationService extends Component
{
    /**
     * @param array<string, mixed> $issue
     * @param array<string, mixed> $businessSignals
     */
    public function computePriorityScore(array $issue, array $businessSignals): int
    {
        $baseScore = (int) ($issue['priorityScore'] ?? 0);
        if ($baseScore <= 0) {
            $baseScore = $this->severityToPriority((string) ($issue['severity'] ?? 'low'));
        }

        $clicks = max(0.0, (float) ($businessSignals['clicks'] ?? 0.0));
        $impressions = max(0.0, (float) ($businessSignals['impressions'] ?? 0.0));
        $position = max(0.0, (float) ($businessSignals['position'] ?? 0.0));
        $performanceScore = max(0.0, min(100.0, (float) ($businessSignals['performanceScore'] ?? 0.0)));
        $poorCwv = (bool) ($businessSignals['poorCwv'] ?? false);
        $htmlErrorCount = max(0, (int) ($businessSignals['htmlErrorCount'] ?? 0));
        $robotsBlockedByPath = (bool) ($businessSignals['robotsBlockedByPath'] ?? false);

        $trafficBoost = min(20.0, sqrt($clicks) * 2.0) + min(12.0, sqrt($impressions / 10.0));
        $visibilityBoost = 0.0;
        if ($position > 0.0 && $position <= 20.0) {
            $visibilityBoost = 7.0;
        } elseif ($position > 20.0 && $position <= 40.0) {
            $visibilityBoost = 3.0;
        }

        $performancePenalty = 0.0;
        if ($performanceScore > 0.0 && $performanceScore < 60.0) {
            $performancePenalty = 8.0;
        } elseif ($performanceScore >= 60.0 && $performanceScore < 75.0) {
            $performancePenalty = 4.0;
        }

        $issueCode = (string) ($issue['issueCode'] ?? '');
        $issueSpecificBoost = 0.0;
        if (in_array($issueCode, ['ROBOTS_NOINDEX', 'CANONICAL_MISSING'], true)) {
            $issueSpecificBoost += min(8.0, $clicks > 0 ? log(1 + $clicks, 2) : 0.0);
        }
        if ($issueCode === 'CWV_POOR' || $poorCwv) {
            $issueSpecificBoost += 10.0;
        }
        if ($issueCode === 'HTML_VALIDATION_ERRORS') {
            $issueSpecificBoost += min(10.0, (float) $htmlErrorCount);
        }
        if ($issueCode === 'ROBOTS_PATH_BLOCKED' || $issueCode === 'ROBOTS_DISALLOW_ALL' || $robotsBlockedByPath) {
            $issueSpecificBoost += 10.0;
        }

        $finalScore = (int) round($baseScore + $trafficBoost + $visibilityBoost + $performancePenalty + $issueSpecificBoost);

        return max(1, min(100, $finalScore));
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
