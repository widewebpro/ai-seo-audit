<?php

namespace wideweb\aiseoaudit\controllers;

use wideweb\aiseoaudit\Plugin;
use wideweb\aiseoaudit\records\AuditIssueRecord;
use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use yii\web\Response;

class DefaultController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('accessCp');

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('ai-seo-audit/index', [
            'settings' => $plugin->getSettings(),
            'runs' => $plugin->audit->getRecentRuns(),
        ]);
    }

    public function actionView(?int $runId = null): Response
    {
        $this->requirePermission('accessCp');

        $runId = $runId ?? (int) Craft::$app->getRequest()->getParam('runId');

        if ($runId <= 0) {
            throw new \yii\web\BadRequestHttpException('Missing audit run ID.');
        }

        $plugin = Plugin::getInstance();
        $run = $plugin->audit->getRunWithResults($runId);

        if (!$run) {
            throw new \yii\web\NotFoundHttpException('Audit run not found.');
        }

        $results = $plugin->audit->getResultsForRun($runId);
        $issueCounts = [];
        if (Craft::$app->getDb()->tableExists(AuditIssueRecord::tableName())) {
            $issueRows = AuditIssueRecord::find()
                ->select(['auditResultId', 'COUNT(*) AS total'])
                ->where(['auditRunId' => $runId])
                ->groupBy(['auditResultId'])
                ->asArray()
                ->all();

            foreach ($issueRows as $row) {
                $resultId = (int) ($row['auditResultId'] ?? 0);
                if ($resultId <= 0) {
                    continue;
                }
                $issueCounts[$resultId] = (int) ($row['total'] ?? 0);
            }
        }

        return $this->renderTemplate('ai-seo-audit/view', [
            'run' => $run,
            'results' => $results,
            'issueCounts' => $issueCounts,
            'trendComparison' => $plugin->audit->getRunTrendComparison($runId),
        ]);
    }

    public function actionIssues(?int $runId = null): Response
    {
        $this->requirePermission('accessCp');

        $runId = $runId ?? (int) Craft::$app->getRequest()->getParam('runId');
        if ($runId <= 0) {
            throw new \yii\web\BadRequestHttpException('Missing audit run ID.');
        }

        $plugin = Plugin::getInstance();
        $run = $plugin->audit->getRunWithResults($runId);
        if (!$run) {
            throw new \yii\web\NotFoundHttpException('Audit run not found.');
        }

        $filters = [
            'severity' => (string) Craft::$app->getRequest()->getParam('severity', ''),
            'status' => (string) Craft::$app->getRequest()->getParam('status', ''),
            'issueCode' => (string) Craft::$app->getRequest()->getParam('issueCode', ''),
            'ownerSuggestion' => (string) Craft::$app->getRequest()->getParam('ownerSuggestion', ''),
            'sectionId' => (string) Craft::$app->getRequest()->getParam('sectionId', ''),
        ];

        $filterOptions = $plugin->audit->getIssueFilterOptionsForRun($runId);
        $sectionOptions = [];
        foreach ((array) ($filterOptions['sectionIds'] ?? []) as $sectionId) {
            $sectionId = (int) $sectionId;
            if ($sectionId <= 0) {
                continue;
            }
            $section = Craft::$app->getEntries()->getSectionById($sectionId);
            $sectionOptions[$sectionId] = $section ? $section->name : ('Section #' . $sectionId);
        }

        return $this->renderTemplate('ai-seo-audit/issues', [
            'run' => $run,
            'filters' => $filters,
            'issueRows' => $plugin->audit->getIssueRowsForRun($runId, $filters, 5000),
            'filterOptions' => $filterOptions,
            'sectionOptions' => $sectionOptions,
            'topClusters' => array_slice($plugin->audit->getIssueClustersForRun($runId, $filters, 20000, 3), 0, 20),
            'trendComparison' => $plugin->audit->getRunTrendComparison($runId),
        ]);
    }

    public function actionRun(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $plugin = Plugin::getInstance();
        $sectionIds = Craft::$app->getRequest()->getBodyParam('sectionIds');
        $sectionIds = is_array($sectionIds)
            ? array_values(array_filter(array_map('intval', $sectionIds), static fn(int $id): bool => $id > 0))
            : null;

        try {
            $run = $plugin->audit->startRun($sectionIds);
            $scopeText = $run->getSectionIdsArray() === []
                ? 'Scope: whole site.'
                : 'Scope: selected sections.';
            Craft::$app->getSession()->setNotice('SEO audit queued. URL discovery stage started. ' . $scopeText);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());
        }

        return $this->redirect('ai-seo-audit');
    }

    public function actionClear(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $count = Plugin::getInstance()->audit->clearAllAudits();
        Craft::$app->getSession()->setNotice(
            Craft::t('ai-seo-audit', '{n} audit run(s) deleted.', ['n' => $count])
        );

        return $this->redirect('ai-seo-audit');
    }

    public function actionLogs(): Response
    {
        $this->requirePermission('accessCp');

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('ai-seo-audit/logs', [
            'logTail' => $plugin->auditLog->getTail(),
            'logPath' => $plugin->auditLog->getLogPath(),
            'stats' => $plugin->auditLog->getDebugStats(),
        ]);
    }

    public function actionStopRun(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $runId = (int) Craft::$app->getRequest()->getBodyParam('runId');
        if ($runId <= 0) {
            throw new \yii\web\BadRequestHttpException('Missing audit run ID.');
        }

        $stopped = Plugin::getInstance()->audit->stopRun($runId);

        if ($stopped) {
            Craft::$app->getSession()->setNotice(Craft::t('ai-seo-audit', 'Audit run stopped.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('ai-seo-audit', 'Audit run not found.'));
        }

        return $this->redirect('ai-seo-audit/view/' . $runId);
    }

    public function actionClearLog(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        Plugin::getInstance()->auditLog->clear();
        Craft::$app->getSession()->setNotice(Craft::t('ai-seo-audit', 'Debug log cleared.'));

        return $this->redirect('ai-seo-audit/logs');
    }

    public function actionExport(?int $runId = null): Response
    {
        $this->requirePermission('accessCp');

        $runId = $runId ?? (int) Craft::$app->getRequest()->getParam('runId');

        if ($runId <= 0) {
            throw new \yii\web\BadRequestHttpException('Missing audit run ID.');
        }

        $plugin = Plugin::getInstance();
        $run = $plugin->audit->getRunWithResults($runId);

        if (!$run) {
            throw new \yii\web\NotFoundHttpException('Audit run not found.');
        }

        $results = $plugin->audit->getResultsForRun($runId);
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new \RuntimeException('Could not prepare export file.');
        }

        fputcsv($stream, ['runId', 'entryId', 'entryCpUrl', 'sectionId', 'title', 'url', 'score', 'status', 'issueCount', 'issueCodes', 'errorMessage', 'analysis', 'htmlExcerpt']);

        $issuesByResultId = [];
        if (Craft::$app->getDb()->tableExists(AuditIssueRecord::tableName())) {
            $issueRows = AuditIssueRecord::find()
                ->where(['auditRunId' => $runId])
                ->all();

            foreach ($issueRows as $issueRow) {
                $resultId = (int) $issueRow->auditResultId;
                if ($resultId <= 0) {
                    continue;
                }
                if (!isset($issuesByResultId[$resultId])) {
                    $issuesByResultId[$resultId] = [];
                }
                $issuesByResultId[$resultId][] = (string) $issueRow->issueCode;
            }
        }

        $entryIds = [];
        foreach ($results as $result) {
            $entryId = (int) $result->entryId;
            if ($entryId > 0) {
                $entryIds[$entryId] = true;
            }
        }

        $entryCpUrls = [];
        if ($entryIds !== []) {
            $entries = Entry::find()
                ->id(array_keys($entryIds))
                ->siteId('*')
                ->status(null)
                ->limit(null)
                ->all();
            foreach ($entries as $entry) {
                $entryCpUrls[(int) $entry->id] = (string) ($entry->getCpEditUrl() ?? '');
            }
        }

        foreach ($results as $result) {
            $issueCodes = $issuesByResultId[(int) $result->id] ?? [];
            $entryId = (int) $result->entryId;
            fputcsv($stream, [
                $run->id,
                $entryId,
                $entryId > 0 ? ($entryCpUrls[$entryId] ?? '') : '',
                $result->sectionId,
                (string) ($result->title ?? ''),
                $result->url,
                $result->score ?? '',
                $result->status,
                count($issueCodes),
                implode('|', $issueCodes),
                (string) ($result->errorMessage ?? ''),
                (string) ($result->analysis ?? ''),
                (string) ($result->htmlExcerpt ?? ''),
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Could not read export file.');
        }

        $fileName = sprintf('ai-seo-audit-run-%d-%s.csv', $run->id, gmdate('Ymd-His'));
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->getHeaders()->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $response->content = "\xEF\xBB\xBF" . $csv;

        return $response;
    }

    public function actionExportIssues(?int $runId = null): Response
    {
        $this->requirePermission('accessCp');

        $runId = $runId ?? (int) Craft::$app->getRequest()->getParam('runId');
        if ($runId <= 0) {
            throw new \yii\web\BadRequestHttpException('Missing audit run ID.');
        }

        $plugin = Plugin::getInstance();
        $run = $plugin->audit->getRunWithResults($runId);
        if (!$run) {
            throw new \yii\web\NotFoundHttpException('Audit run not found.');
        }

        $filters = [
            'severity' => (string) Craft::$app->getRequest()->getParam('severity', ''),
            'status' => (string) Craft::$app->getRequest()->getParam('status', ''),
            'issueCode' => (string) Craft::$app->getRequest()->getParam('issueCode', ''),
            'ownerSuggestion' => (string) Craft::$app->getRequest()->getParam('ownerSuggestion', ''),
            'sectionId' => (string) Craft::$app->getRequest()->getParam('sectionId', ''),
        ];
        $issueRows = $plugin->audit->getIssueRowsForRun($runId, $filters, 20000);

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Could not prepare issues export file.');
        }

        fputcsv($stream, [
            'runId',
            'issueId',
            'issueCode',
            'severity',
            'status',
            'priorityScore',
            'ownerSuggestion',
            'entryId',
            'entryCpUrl',
            'sectionId',
            'title',
            'url',
            'message',
            'fixInstruction',
            'performanceScore',
            'lcpMs',
            'inpMs',
            'cls',
            'hasPsiMetrics',
            'psiAttempted',
            'psiStatus',
            'psiHttpStatus',
            'psiMissingReason',
            'htmlErrorCount',
            'htmlWarningCount',
            'htmlValidatorUrl',
            'htmlErrorExamplesText',
            'htmlWarningExamplesText',
            'hasHtmlValidationMetrics',
            'robotsBlockedByPath',
            'robotsDisallowAll',
            'sitemapReachable',
            'sitemapDirectivePresent',
            'hasSitePolicyMetrics',
            'externalChecksAttempted',
            'externalChecksSucceeded',
            'externalChecksSampledOut',
            'externalUrlLikelyPrivate',
            'externalMetricsNote',
            'dateCreated',
        ]);

        $entryCpUrls = $this->entryCpUrlsForRows($issueRows);
        foreach ($issueRows as $row) {
            $entryId = (int) ($row['entryId'] ?? 0);
            fputcsv($stream, [
                $run->id,
                (int) ($row['id'] ?? 0),
                (string) ($row['issueCode'] ?? ''),
                (string) ($row['severity'] ?? ''),
                (string) ($row['status'] ?? ''),
                (int) ($row['priorityScore'] ?? 0),
                (string) ($row['ownerSuggestion'] ?? ''),
                $entryId,
                $entryId > 0 ? ($entryCpUrls[$entryId] ?? '') : '',
                (int) ($row['sectionId'] ?? 0),
                (string) ($row['title'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['message'] ?? ''),
                (string) ($row['fixInstruction'] ?? ''),
                $row['performanceScore'] === null ? '' : (float) $row['performanceScore'],
                $row['lcpMs'] === null ? '' : (float) $row['lcpMs'],
                $row['inpMs'] === null ? '' : (float) $row['inpMs'],
                $row['cls'] === null ? '' : (float) $row['cls'],
                ((bool) ($row['hasPsiMetrics'] ?? false)) ? '1' : '0',
                ((bool) ($row['psiAttempted'] ?? false)) ? '1' : '0',
                (string) ($row['psiStatus'] ?? ''),
                (int) ($row['psiHttpStatus'] ?? 0),
                (string) ($row['psiMissingReason'] ?? ''),
                $row['htmlErrorCount'] === null ? '' : (int) $row['htmlErrorCount'],
                $row['htmlWarningCount'] === null ? '' : (int) $row['htmlWarningCount'],
                (string) ($row['htmlValidatorUrl'] ?? ''),
                (string) ($row['htmlErrorExamplesText'] ?? ''),
                (string) ($row['htmlWarningExamplesText'] ?? ''),
                ((bool) ($row['hasHtmlValidationMetrics'] ?? false)) ? '1' : '0',
                $row['robotsBlockedByPath'] === null ? '' : (((bool) $row['robotsBlockedByPath']) ? '1' : '0'),
                $row['robotsDisallowAll'] === null ? '' : (((bool) $row['robotsDisallowAll']) ? '1' : '0'),
                $row['sitemapReachable'] === null ? '' : (((bool) $row['sitemapReachable']) ? '1' : '0'),
                $row['sitemapDirectivePresent'] === null ? '' : (((bool) $row['sitemapDirectivePresent']) ? '1' : '0'),
                ((bool) ($row['hasSitePolicyMetrics'] ?? false)) ? '1' : '0',
                ((bool) ($row['externalChecksAttempted'] ?? false)) ? '1' : '0',
                ((bool) ($row['externalChecksSucceeded'] ?? false)) ? '1' : '0',
                ((bool) ($row['externalChecksSampledOut'] ?? false)) ? '1' : '0',
                ((bool) ($row['externalUrlLikelyPrivate'] ?? false)) ? '1' : '0',
                (string) ($row['externalMetricsNote'] ?? ''),
                (string) ($row['dateCreated'] ?? ''),
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Could not read issues export file.');
        }

        $fileName = sprintf('ai-seo-audit-run-%d-issues-%s.csv', $run->id, gmdate('Ymd-His'));
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->getHeaders()->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $response->content = "\xEF\xBB\xBF" . $csv;

        return $response;
    }

    public function actionExportClusters(?int $runId = null): Response
    {
        $this->requirePermission('accessCp');

        $runId = $runId ?? (int) Craft::$app->getRequest()->getParam('runId');
        if ($runId <= 0) {
            throw new \yii\web\BadRequestHttpException('Missing audit run ID.');
        }

        $plugin = Plugin::getInstance();
        $run = $plugin->audit->getRunWithResults($runId);
        if (!$run) {
            throw new \yii\web\NotFoundHttpException('Audit run not found.');
        }

        $filters = [
            'severity' => (string) Craft::$app->getRequest()->getParam('severity', ''),
            'status' => (string) Craft::$app->getRequest()->getParam('status', ''),
            'issueCode' => (string) Craft::$app->getRequest()->getParam('issueCode', ''),
            'ownerSuggestion' => (string) Craft::$app->getRequest()->getParam('ownerSuggestion', ''),
            'sectionId' => (string) Craft::$app->getRequest()->getParam('sectionId', ''),
        ];
        $clusters = $plugin->audit->getIssueClustersForRun($runId, $filters, 20000, 5);

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Could not prepare clusters export file.');
        }

        fputcsv($stream, ['runId', 'clusterKey', 'issueCode', 'severity', 'ownerSuggestion', 'sectionId', 'affectedCount', 'maxPriorityScore', 'sampleUrls', 'message', 'fixInstruction']);

        foreach ($clusters as $cluster) {
            fputcsv($stream, [
                $run->id,
                (string) ($cluster['clusterKey'] ?? ''),
                (string) ($cluster['issueCode'] ?? ''),
                (string) ($cluster['severity'] ?? ''),
                (string) ($cluster['ownerSuggestion'] ?? ''),
                (int) ($cluster['sectionId'] ?? 0),
                (int) ($cluster['affectedCount'] ?? 0),
                (int) ($cluster['maxPriorityScore'] ?? 0),
                implode('|', (array) ($cluster['sampleUrls'] ?? [])),
                (string) ($cluster['message'] ?? ''),
                (string) ($cluster['fixInstruction'] ?? ''),
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Could not read clusters export file.');
        }

        $fileName = sprintf('ai-seo-audit-run-%d-clusters-%s.csv', $run->id, gmdate('Ymd-His'));
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->getHeaders()->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $response->content = "\xEF\xBB\xBF" . $csv;

        return $response;
    }

    public function actionExportClientReport(?int $runId = null): Response
    {
        $this->requirePermission('accessCp');

        $runId = $runId ?? (int) Craft::$app->getRequest()->getParam('runId');
        if ($runId <= 0) {
            throw new \yii\web\BadRequestHttpException('Missing audit run ID.');
        }

        $plugin = Plugin::getInstance();
        $run = $plugin->audit->getRunWithResults($runId);
        if (!$run) {
            throw new \yii\web\NotFoundHttpException('Audit run not found.');
        }

        $filters = [
            'severity' => (string) Craft::$app->getRequest()->getParam('severity', ''),
            'status' => (string) Craft::$app->getRequest()->getParam('status', ''),
            'issueCode' => (string) Craft::$app->getRequest()->getParam('issueCode', ''),
            'ownerSuggestion' => (string) Craft::$app->getRequest()->getParam('ownerSuggestion', ''),
            'sectionId' => (string) Craft::$app->getRequest()->getParam('sectionId', ''),
        ];

        $results = $plugin->audit->getResultsForRun($runId);
        $issueRows = $plugin->audit->getIssueRowsForRun($runId, $filters, 20000);
        $clusters = $plugin->audit->getIssueClustersForRun($runId, $filters, 20000, 5);

        $severityCounts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        $ownerCounts = [];

        foreach ($issueRows as $issueRow) {
            $severity = strtolower((string) ($issueRow['severity'] ?? ''));
            if (!array_key_exists($severity, $severityCounts)) {
                $severity = 'low';
            }
            $severityCounts[$severity]++;

            $owner = trim((string) ($issueRow['ownerSuggestion'] ?? ''));
            if ($owner !== '') {
                $ownerCounts[$owner] = (int) ($ownerCounts[$owner] ?? 0) + 1;
            }
        }
        arsort($ownerCounts);

        $totalPages = count($results);
        $processedPages = 0;
        $erroredPages = 0;
        foreach ($results as $result) {
            if (!in_array((string) $result->status, ['pending', 'fetched'], true)) {
                $processedPages++;
            }
            if ((string) $result->status === 'error') {
                $erroredPages++;
            }
        }

        $riskLevel = $this->riskLevelFromSeverityCounts($severityCounts);
        $content = $this->buildClientReportHtml(
            (int) $run->id,
            (string) $run->status,
            $processedPages,
            $totalPages,
            $erroredPages,
            count($issueRows),
            $severityCounts,
            $ownerCounts,
            $riskLevel,
            array_slice($clusters, 0, 12)
        );

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;

        $shouldExportPdf = $plugin->llm->supportsPdfReportOutput();
        if ($shouldExportPdf && class_exists(\Dompdf\Dompdf::class)) {
            try {
                $options = new \Dompdf\Options();
                $options->set('isRemoteEnabled', false);
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml($content, 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();

                $fileName = sprintf('ai-seo-audit-run-%d-client-report-%s.pdf', $run->id, gmdate('Ymd-His'));
                $response->getHeaders()->set('Content-Type', 'application/pdf');
                $response->getHeaders()->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
                $response->content = $dompdf->output();

                return $response;
            } catch (\Throwable) {
                // Fall back to HTML export if PDF rendering fails.
            }
        }

        $fileName = sprintf('ai-seo-audit-run-%d-client-report-%s.html', $run->id, gmdate('Ymd-His'));
        $response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
        $response->getHeaders()->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $response->content = $content;

        return $response;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function entryCpUrlsForRows(array $rows): array
    {
        $entryIds = [];
        foreach ($rows as $row) {
            $entryId = (int) ($row['entryId'] ?? 0);
            if ($entryId > 0) {
                $entryIds[$entryId] = true;
            }
        }

        if ($entryIds === []) {
            return [];
        }

        $entryCpUrls = [];
        $entries = Entry::find()
            ->id(array_keys($entryIds))
            ->siteId('*')
            ->status(null)
            ->limit(null)
            ->all();
        foreach ($entries as $entry) {
            $entryCpUrls[(int) $entry->id] = (string) ($entry->getCpEditUrl() ?? '');
        }

        return $entryCpUrls;
    }

    /**
     * @param array<string, int> $severityCounts
     */
    private function riskLevelFromSeverityCounts(array $severityCounts): string
    {
        if ((int) ($severityCounts['critical'] ?? 0) > 0) {
            return 'Critical';
        }

        if ((int) ($severityCounts['high'] ?? 0) >= 5) {
            return 'High';
        }

        if ((int) ($severityCounts['high'] ?? 0) > 0 || (int) ($severityCounts['medium'] ?? 0) >= 8) {
            return 'Elevated';
        }

        if ((int) ($severityCounts['medium'] ?? 0) > 0 || (int) ($severityCounts['low'] ?? 0) > 0) {
            return 'Moderate';
        }

        return 'Low';
    }

    private function impactCategoryForIssueCode(string $issueCode): string
    {
        return match ($issueCode) {
            'ROBOTS_NOINDEX', 'CANONICAL_MISSING' => 'Indexing',
            'TITLE_MISSING', 'TITLE_TOO_LONG', 'META_DESCRIPTION_MISSING', 'META_DESCRIPTION_TOO_LONG' => 'SERP relevance and CTR',
            'H1_MISSING', 'H1_MULTIPLE' => 'Topical relevance',
            'IMAGES_WITHOUT_ALT' => 'Accessibility and long-tail discoverability',
            default => 'Technical SEO quality',
        };
    }

    private function impactStatementForIssueCode(string $issueCode, string $severity): string
    {
        return match ($issueCode) {
            'ROBOTS_NOINDEX' => 'This can directly block organic visibility because search engines may be instructed not to index affected pages.',
            'CANONICAL_MISSING' => 'This can split ranking signals across duplicate URLs and reduce stable indexation.',
            'TITLE_MISSING' => 'Missing titles usually hurt relevance signals and reduce click-through rate from search results.',
            'TITLE_TOO_LONG' => 'Overlong titles may be truncated in search snippets, weakening message clarity and CTR.',
            'META_DESCRIPTION_MISSING', 'META_DESCRIPTION_TOO_LONG' => 'Snippet quality can drop, lowering click-through rates even when rankings are stable.',
            'H1_MISSING', 'H1_MULTIPLE' => 'Weak heading structure can reduce topical clarity for both users and crawlers.',
            'IMAGES_WITHOUT_ALT' => 'Missing alt text reduces accessibility compliance and can limit image-search opportunities.',
            default => 'This issue can negatively affect organic performance, crawl efficiency, and user trust if unresolved.',
        };
    }

    private function validationStepForIssueCode(string $issueCode): string
    {
        return match ($issueCode) {
            'ROBOTS_NOINDEX' => 'Re-crawl page and verify robots meta no longer contains "noindex".',
            'CANONICAL_MISSING' => 'Re-crawl page and confirm a valid self-referencing or designated canonical URL is present.',
            'TITLE_MISSING', 'TITLE_TOO_LONG' => 'Re-crawl page and confirm title exists and is within recommended length.',
            'META_DESCRIPTION_MISSING', 'META_DESCRIPTION_TOO_LONG' => 'Re-crawl page and confirm meta description exists and stays within recommended length.',
            'H1_MISSING', 'H1_MULTIPLE' => 'Re-crawl page and verify exactly one descriptive H1 is present.',
            'IMAGES_WITHOUT_ALT' => 'Re-crawl page and verify informative images contain descriptive alt attributes.',
            default => 'Re-run audit for this URL set and verify issue count for this code decreases.',
        };
    }

    /**
     * @param array<string, int> $severityCounts
     * @param array<string, int> $ownerCounts
     * @param array<int, array<string, mixed>> $clusters
     */
    private function buildClientReportHtml(
        int $runId,
        string $runStatus,
        int $processedPages,
        int $totalPages,
        int $erroredPages,
        int $totalIssues,
        array $severityCounts,
        array $ownerCounts,
        string $riskLevel,
        array $clusters
    ): string {
        $ownerItems = '';
        foreach (array_slice($ownerCounts, 0, 3, true) as $owner => $count) {
            $ownerItems .= '<li><strong>' . $this->e(strtoupper((string) $owner)) . ':</strong> ' . (int) $count . ' issues</li>';
        }

        $clusterBlocks = '';
        if ($clusters === []) {
            $clusterBlocks = '<p>No issue clusters found for the selected scope.</p>';
        } else {
            $clusterIndex = 1;
            foreach ($clusters as $cluster) {
                $issueCode = (string) ($cluster['issueCode'] ?? '');
                $severity = ucfirst((string) ($cluster['severity'] ?? 'low'));
                $affectedCount = (int) ($cluster['affectedCount'] ?? 0);
                $maxPriority = (int) ($cluster['maxPriorityScore'] ?? 0);
                $owner = (string) ($cluster['ownerSuggestion'] ?? 'team');
                $message = trim((string) ($cluster['message'] ?? ''));
                $fixInstruction = trim((string) ($cluster['fixInstruction'] ?? ''));
                $impactCategory = $this->impactCategoryForIssueCode($issueCode);
                $impactStatement = $this->impactStatementForIssueCode($issueCode, $severity);
                $validationStep = $this->validationStepForIssueCode($issueCode);

                $sampleUrlsHtml = '';
                $sampleUrls = array_values(array_filter(array_map('strval', (array) ($cluster['sampleUrls'] ?? []))));
                if ($sampleUrls !== []) {
                    $sampleUrlsHtml .= '<li><strong>Example URLs:</strong><ul>';
                    foreach (array_slice($sampleUrls, 0, 5) as $sampleUrl) {
                        $sampleUrlsHtml .= '<li>' . $this->e($sampleUrl) . '</li>';
                    }
                    $sampleUrlsHtml .= '</ul></li>';
                }

                $clusterBlocks .= '<div class="cluster">';
                $clusterBlocks .= '<h3>' . $clusterIndex . '. ' . $this->e($issueCode) . ' (' . $this->e($severity) . ')</h3>';
                $clusterBlocks .= '<ul>';
                if ($message !== '') {
                    $clusterBlocks .= '<li><strong>Problem:</strong> ' . $this->e($message) . '</li>';
                }
                $clusterBlocks .= '<li><strong>Why this is critical:</strong> ' . $this->e($impactStatement) . '</li>';
                $clusterBlocks .= '<li><strong>SEO impact area:</strong> ' . $this->e($impactCategory) . '</li>';
                $clusterBlocks .= '<li><strong>Priority score (max in cluster):</strong> ' . $maxPriority . '</li>';
                $clusterBlocks .= '<li><strong>Affected pages:</strong> ' . $affectedCount . '</li>';
                $clusterBlocks .= '<li><strong>Suggested owner:</strong> ' . $this->e(strtoupper($owner)) . '</li>';
                $clusterBlocks .= '<li><strong>Recommended fix:</strong> ' . $this->e($fixInstruction !== '' ? $fixInstruction : 'Apply technical/content correction based on issue evidence.') . '</li>';
                $clusterBlocks .= $sampleUrlsHtml;
                $clusterBlocks .= '<li><strong>Validation step:</strong> ' . $this->e($validationStep) . '</li>';
                $clusterBlocks .= '</ul>';
                $clusterBlocks .= '</div>';

                $clusterIndex++;
            }
        }

        return '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SEO Audit Client Report</title>
  <style>
    body { font-family: Arial, sans-serif; color: #1f2937; line-height: 1.45; margin: 28px; }
    h1, h2, h3 { color: #111827; }
    h1 { margin-bottom: 8px; }
    .meta { color: #4b5563; margin-bottom: 16px; }
    .panel { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; background: #fafafa; }
    .risk { font-weight: 700; }
    .cluster { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; page-break-inside: avoid; }
    ul { margin: 8px 0 8px 18px; }
    li { margin-bottom: 4px; }
    .footer { margin-top: 20px; color: #6b7280; font-size: 13px; }
  </style>
</head>
<body>
  <h1>SEO Audit Client Report</h1>
  <div class="meta">
    <div>Generated (UTC): ' . $this->e(gmdate('Y-m-d H:i')) . '</div>
    <div>Audit Run ID: ' . $runId . '</div>
    <div>Run Status: ' . $this->e(ucfirst($runStatus)) . '</div>
    <div>Pages Processed: ' . $processedPages . ' / ' . $totalPages . '</div>
    <div>Pages With Crawl/Processing Errors: ' . $erroredPages . '</div>
  </div>

  <h2>Executive Summary</h2>
  <div class="panel">
    <div class="risk">Overall Risk Level: ' . $this->e($riskLevel) . '</div>
    <ul>
      <li>Total issues found: ' . $totalIssues . '</li>
      <li>Critical: ' . (int) ($severityCounts['critical'] ?? 0) . '</li>
      <li>High: ' . (int) ($severityCounts['high'] ?? 0) . '</li>
      <li>Medium: ' . (int) ($severityCounts['medium'] ?? 0) . '</li>
      <li>Low: ' . (int) ($severityCounts['low'] ?? 0) . '</li>
    </ul>' .
    ($ownerItems !== '' ? '<div><strong>Primary owner groups impacted:</strong><ul>' . $ownerItems . '</ul></div>' : '') . '
  </div>

  <h2>Top Priority Problem Clusters</h2>
  ' . $clusterBlocks . '

  <h2>Recommended Next Actions (Client-Facing)</h2>
  <div class="panel">
    <ol>
      <li>Resolve all Critical and High issues first, starting with clusters affecting the most pages.</li>
      <li>Re-crawl affected URLs after fixes to validate indexability and snippet quality improvements.</li>
      <li>Schedule a follow-up audit after deployment to confirm trend improvements in issue count and severity.</li>
    </ol>
  </div>

  <div class="footer">
    This report is generated automatically by AI SEO Audit.
  </div>
</body>
</html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
