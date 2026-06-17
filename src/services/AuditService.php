<?php

namespace wideweb\aiseoaudit\services;

use wideweb\aiseoaudit\helpers\DbTextHelper;
use wideweb\aiseoaudit\jobs\DiscoverAuditUrlsJob;
use wideweb\aiseoaudit\jobs\EvaluateAuditResultJob;
use wideweb\aiseoaudit\jobs\ProcessAuditPageJob;
use wideweb\aiseoaudit\Plugin;
use wideweb\aiseoaudit\records\AuditExternalMetricRecord;
use wideweb\aiseoaudit\records\AuditIssueRecord;
use wideweb\aiseoaudit\records\AuditResultRecord;
use wideweb\aiseoaudit\records\AuditRunRecord;
use wideweb\aiseoaudit\records\AuditSignalRecord;
use wideweb\aiseoaudit\records\AuditUrlRecord;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use yii\base\Exception;

class AuditService extends Component
{
    /**
     * Deletes all audit runs and results. Returns number of runs removed.
     */
    public function clearAllAudits(): int
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(AuditRunRecord::tableName())) {
            return 0;
        }

        $count = (int) AuditRunRecord::find()->count();
        $transaction = $db->beginTransaction();
        try {
            // Delete child tables explicitly to avoid FK-order issues across environments.
            if ($db->tableExists(AuditExternalMetricRecord::tableName())) {
                AuditExternalMetricRecord::deleteAll();
            }
            if ($db->tableExists(AuditIssueRecord::tableName())) {
                AuditIssueRecord::deleteAll();
            }
            if ($db->tableExists(AuditSignalRecord::tableName())) {
                AuditSignalRecord::deleteAll();
            }
            if ($db->tableExists(AuditUrlRecord::tableName())) {
                AuditUrlRecord::deleteAll();
            }
            if ($db->tableExists(AuditResultRecord::tableName())) {
                AuditResultRecord::deleteAll();
            }
            AuditRunRecord::deleteAll();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->log()->error('Failed to clear audits', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }

        $this->log()->info('All audits cleared', ['runsDeleted' => $count]);

        return $count;
    }

    public function stopRun(int $auditRunId): bool
    {
        $run = AuditRunRecord::findOne($auditRunId);
        if (!$run) {
            return false;
        }

        $nowDb = Db::prepareDateForDb(DateTimeHelper::now());

        $updated = AuditResultRecord::updateAll(
            ['status' => 'cancelled', 'dateUpdated' => $nowDb],
            ['auditRunId' => $auditRunId, 'status' => ['pending', 'fetched']]
        );

        $run->status = 'cancelled';
        $run->dateUpdated = DateTimeHelper::now();
        $run->save(false);

        if (Craft::$app->getDb()->tableExists(AuditUrlRecord::tableName())) {
            AuditUrlRecord::updateAll(
                ['status' => 'cancelled', 'dateUpdated' => $nowDb],
                ['auditRunId' => $auditRunId, 'status' => ['queued', 'fetched']]
            );
        }

        $this->log()->warning('Audit run stopped by user', [
            'auditRunId' => $auditRunId,
            'pendingResultsCancelled' => $updated,
        ]);

        return true;
    }

    public function getPendingResultsCount(): int
    {
        if (!Craft::$app->getDb()->tableExists(AuditResultRecord::tableName())) {
            return 0;
        }

        return (int) AuditResultRecord::find()
            ->where(['status' => ['pending', 'fetched']])
            ->count();
    }

    /**
     * @param int[]|null $sectionIds
     * @throws Exception
     */
    public function startRun(?array $sectionIds = null): AuditRunRecord
    {
        $settings = Plugin::getInstance()->getSettings();
        $sectionIds = $this->normalizeSectionIds($sectionIds ?? $settings->selectedSectionIds);
        $isFullSiteRun = $sectionIds === [];

        $this->log()->info('Audit run starting', [
            'sectionIds' => $sectionIds,
            'scope' => $isFullSiteRun ? 'all-sections' : 'selected-sections',
            'enableLlm' => $settings->enableLlm,
            'sitemapDiscovery' => (bool) $settings->enableSitemapDiscovery,
        ]);

        $run = new AuditRunRecord();
        $run->status = 'pending';
        $run->setSectionIdsArray($sectionIds);
        $run->totalEntries = 0;
        $run->processedEntries = 0;
        $run->dateCreated = DateTimeHelper::now();
        $run->dateUpdated = $run->dateCreated;

        if (!$run->save()) {
            throw new Exception('Could not create audit run.');
        }

        $this->log()->info('Audit run created', [
            'runId' => $run->id,
            'status' => $run->status,
            'nextStage' => 'discovery',
        ]);

        $this->queueDiscovery($run->id);

        return $run;
    }

    public function discoverAndQueueRun(int $auditRunId): void
    {
        $run = AuditRunRecord::findOne($auditRunId);
        if (!$run || $run->status === 'cancelled') {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $sectionIds = $run->getSectionIdsArray();
        $entries = $this->findEntriesForSections($sectionIds);
        $candidates = [];
        $skippedNoUrl = 0;

        foreach ($entries as $entry) {
            $url = $entry->getUrl();
            if (!$url) {
                $skippedNoUrl++;
                continue;
            }

            $normalized = $this->normalizeUrl($url);
            $candidates[$normalized] = [
                'url' => $url,
                'entryId' => (int) $entry->id,
                'sectionId' => (int) $entry->sectionId,
                'title' => (string) $entry->title,
                'sourceType' => 'entry',
            ];
        }

        $sitemapCount = 0;
        if ($settings->enableSitemapDiscovery && trim($settings->sitemapUrl) !== '') {
            $discoveredUrls = Plugin::getInstance()->urlDiscovery->discoverFromSitemap(
                trim($settings->sitemapUrl),
                max(0, (int) $settings->maxDiscoveredUrls)
            );

            foreach ($discoveredUrls as $url) {
                $normalized = $this->normalizeUrl($url);
                if (isset($candidates[$normalized])) {
                    continue;
                }
                $candidates[$normalized] = [
                    'url' => $url,
                    'entryId' => 0,
                    'sectionId' => 0,
                    'title' => $this->titleFromUrl($url),
                    'sourceType' => 'sitemap',
                ];
                $sitemapCount++;
            }
        }

        $existing = AuditResultRecord::find()
            ->select(['id', 'url'])
            ->where(['auditRunId' => $auditRunId])
            ->asArray()
            ->all();
        $existingByUrl = [];
        foreach ($existing as $row) {
            $existingByUrl[$this->normalizeUrl((string) ($row['url'] ?? ''))] = true;
        }

        $created = 0;
        foreach ($candidates as $normalizedUrl => $candidate) {
            if (isset($existingByUrl[$normalizedUrl])) {
                continue;
            }

            $result = new AuditResultRecord();
            $result->auditRunId = $auditRunId;
            $result->entryId = (int) $candidate['entryId'];
            $result->sectionId = (int) $candidate['sectionId'];
            $result->url = (string) $candidate['url'];
            $result->title = (string) $candidate['title'];
            $result->status = 'pending';
            $result->dateCreated = DateTimeHelper::now();
            $result->dateUpdated = $result->dateCreated;
            $result->save(false);
            $created++;
        }

        $run->totalEntries = (int) AuditResultRecord::find()
            ->where(['auditRunId' => $auditRunId])
            ->count();
        $run->dateUpdated = DateTimeHelper::now();
        $run->save(false);

        $this->seedAuditUrlsForRun($auditRunId);

        $this->log()->info('Discovery finished', [
            'auditRunId' => $auditRunId,
            'entryCandidates' => count($entries),
            'sitemapCandidates' => $sitemapCount,
            'createdResults' => $created,
            'skippedNoUrl' => $skippedNoUrl,
            'totalEntries' => $run->totalEntries,
        ]);

        $run = AuditRunRecord::findOne($auditRunId);
        if ($run && $run->status !== 'cancelled') {
            $this->queuePages($auditRunId);
        }
    }

    /**
     * One queue job per page, staggered to respect rate limits.
     */
    public function queuePages(int $auditRunId): void
    {
        $results = AuditResultRecord::find()
            ->where(['auditRunId' => $auditRunId, 'status' => 'pending'])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $run = AuditRunRecord::findOne($auditRunId);
        if ($run) {
            if ($run->status === 'cancelled') {
                $this->log()->warning('Queueing aborted: run is cancelled', ['auditRunId' => $auditRunId]);

                return;
            }
            $run->status = 'running';
            $run->dateUpdated = DateTimeHelper::now();
            $run->save(false);
        }

        $queue = Craft::$app->getQueue();
        $stats = $this->log()->getDebugStats();

        $this->log()->info('Queueing page jobs', [
            'auditRunId' => $auditRunId,
            'jobCount' => count($results),
            'delaySeconds' => 0,
            'queueBefore' => $stats,
        ]);

        foreach ($results as $result) {
            $job = new ProcessAuditPageJob([
                'resultId' => $result->id,
                'auditRunId' => $auditRunId,
            ]);

            $queue->push($job);

            $this->log()->info('Job pushed', [
                'auditRunId' => $auditRunId,
                'resultId' => $result->id,
                'url' => $result->url,
                'delay' => 0,
            ]);
        }

        $this->log()->info('Queueing finished', [
            'auditRunId' => $auditRunId,
            'queueAfter' => $this->log()->getDebugStats(),
        ]);
    }

    public function processPage(int $resultId, int $auditRunId): void
    {
        $started = microtime(true);
        $this->log()->info('processPage started', [
            'resultId' => $resultId,
            'auditRunId' => $auditRunId,
        ]);

        $result = AuditResultRecord::findOne($resultId);

        if (!$result || $result->auditRunId !== $auditRunId) {
            $this->log()->warning('processPage skipped: result missing or wrong run', [
                'resultId' => $resultId,
                'auditRunId' => $auditRunId,
            ]);

            return;
        }

        $run = AuditRunRecord::findOne($auditRunId);
        if ($run && $run->status === 'cancelled') {
            $result->status = 'cancelled';
            $result->dateUpdated = DateTimeHelper::now();
            $result->save(false);
            $this->refreshRunProgress($auditRunId);

            return;
        }

        if ($result->status !== 'pending') {
            $this->log()->warning('processPage skipped: already processed', [
                'resultId' => $resultId,
                'status' => $result->status,
            ]);
            $this->refreshRunProgress($auditRunId);

            return;
        }

        $plugin = Plugin::getInstance();

        try {
            $this->log()->info('Fetching URL', ['url' => $result->url]);
            $fetchStarted = microtime(true);
            $html = $plugin->pageFetcher->fetch($this->resolveFetchUrl($result->url));
            $fetchMs = (int) round((microtime(true) - $fetchStarted) * 1000);

            $snapshot = $plugin->pageFetcher->buildSeoSnapshot($html);
            $result->htmlExcerpt = DbTextHelper::sanitize($snapshot);
            $this->upsertSignalsFromHtml(
                $result,
                $auditRunId,
                $html,
                $fetchMs,
                $snapshot
            );

            $this->log()->info('Snapshot built', [
                'resultId' => $resultId,
                'htmlBytes' => strlen($html),
                'snapshotChars' => strlen($snapshot),
                'fetchMs' => $fetchMs,
            ]);

            $result->status = 'fetched';
            $result->errorMessage = null;
        } catch (\Throwable $e) {
            $result->status = 'error';
            $result->errorMessage = DbTextHelper::sanitize($e->getMessage());
            $this->log()->error('processPage failed', [
                'resultId' => $resultId,
                'url' => $result->url,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        $result->dateUpdated = DateTimeHelper::now();

        try {
            if (!$result->save(false)) {
                throw new Exception('Could not save audit result: ' . json_encode($result->getErrors()));
            }
        } catch (\Throwable $e) {
            $result->status = 'error';
            $result->errorMessage = DbTextHelper::sanitize($e->getMessage());
            $result->save(false);
            $this->log()->error('processPage save failed', [
                'resultId' => $resultId,
                'message' => $e->getMessage(),
            ]);
        }

        if ($result->status === 'fetched') {
            $this->queueEvaluateResult($result->id, $auditRunId);
        }

        $this->refreshRunProgress($auditRunId);

        $totalMs = (int) round((microtime(true) - $started) * 1000);
        $this->log()->info('processPage finished', [
            'resultId' => $resultId,
            'status' => $result->status,
            'totalMs' => $totalMs,
        ]);
    }

    public function evaluateFetchedPage(int $resultId, int $auditRunId): void
    {
        $started = microtime(true);
        $this->log()->info('evaluateFetchedPage started', [
            'resultId' => $resultId,
            'auditRunId' => $auditRunId,
        ]);

        $result = AuditResultRecord::findOne($resultId);

        if (!$result || $result->auditRunId !== $auditRunId) {
            $this->log()->warning('evaluateFetchedPage skipped: result missing or wrong run', [
                'resultId' => $resultId,
                'auditRunId' => $auditRunId,
            ]);

            return;
        }

        $run = AuditRunRecord::findOne($auditRunId);
        if ($run && $run->status === 'cancelled') {
            if (in_array($result->status, ['pending', 'fetched'], true)) {
                $result->status = 'cancelled';
                $result->dateUpdated = DateTimeHelper::now();
                $result->save(false);
            }
            $this->refreshRunProgress($auditRunId);

            return;
        }

        if ($result->status !== 'fetched') {
            $this->log()->warning('evaluateFetchedPage skipped: status is not fetched', [
                'resultId' => $resultId,
                'status' => $result->status,
            ]);
            $this->refreshRunProgress($auditRunId);

            return;
        }

        try {
            $signalPayload = $this->getSignalPayloadForResult($result);
            $businessSignals = Plugin::getInstance()->externalMetrics->collectForResult($result, $auditRunId);
            $combinedSignals = array_merge($signalPayload, $businessSignals);
            AuditIssueRecord::deleteAll(['auditResultId' => $result->id]);
            $issues = Plugin::getInstance()->ruleEngine->evaluate($combinedSignals);
            $this->saveIssuesForResult($result, $auditRunId, $issues, $combinedSignals, $businessSignals);

            $settings = Plugin::getInstance()->getSettings();
            if (!$settings->enableLlm) {
                $result->status = 'preview';
                $result->score = null;
                $result->analysis = null;
                $result->errorMessage = null;
                $this->log()->info('LLM skipped (preview mode)', ['resultId' => $resultId]);
            } else {
                $this->log()->info('Calling LLM', [
                    'resultId' => $resultId,
                    'model' => $settings->modelVersion,
                ]);
                $llmStarted = microtime(true);
                $analysis = Plugin::getInstance()->llm->analyzePage($result->url, (string) $result->title, (string) ($result->htmlExcerpt ?? ''));
                $llmMs = (int) round((microtime(true) - $llmStarted) * 1000);

                $result->score = $analysis['score'];
                $result->analysis = DbTextHelper::sanitize($analysis['analysis']);
                $result->status = 'success';
                $result->errorMessage = null;

                $this->log()->info('LLM finished', [
                    'resultId' => $resultId,
                    'score' => $result->score,
                    'llmMs' => $llmMs,
                ]);
            }
        } catch (\Throwable $e) {
            $result->status = 'error';
            $result->errorMessage = DbTextHelper::sanitize($e->getMessage());
            $this->log()->error('evaluateFetchedPage failed', [
                'resultId' => $resultId,
                'url' => $result->url,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        $result->dateUpdated = DateTimeHelper::now();

        try {
            if (!$result->save(false)) {
                throw new Exception('Could not save audit result: ' . json_encode($result->getErrors()));
            }
        } catch (\Throwable $e) {
            $result->status = 'error';
            $result->errorMessage = DbTextHelper::sanitize($e->getMessage());
            $result->save(false);
            $this->log()->error('evaluateFetchedPage save failed', [
                'resultId' => $resultId,
                'message' => $e->getMessage(),
            ]);
        }

        $this->refreshRunProgress($auditRunId);

        $totalMs = (int) round((microtime(true) - $started) * 1000);
        $this->log()->info('evaluateFetchedPage finished', [
            'resultId' => $resultId,
            'status' => $result->status,
            'totalMs' => $totalMs,
        ]);
    }

    private function refreshRunProgress(int $auditRunId): void
    {
        $run = AuditRunRecord::findOne($auditRunId);

        if (!$run) {
            return;
        }

        $run->processedEntries = (int) AuditResultRecord::find()
            ->where(['auditRunId' => $auditRunId])
            ->andWhere(['not in', 'status', ['pending', 'fetched']])
            ->count();

        $remaining = (int) AuditResultRecord::find()
            ->where(['auditRunId' => $auditRunId])
            ->andWhere(['status' => ['pending', 'fetched']])
            ->count();

        $previousStatus = $run->status;
        if ($previousStatus === 'cancelled') {
            $run->status = 'cancelled';
        } else {
            $run->status = $remaining > 0 ? 'running' : 'completed';
        }
        $run->dateUpdated = DateTimeHelper::now();
        $run->save(false);

        if ($run->status === 'completed' && Craft::$app->getDb()->columnExists(AuditRunRecord::tableName(), 'totalsJson')) {
            $totals = $this->buildRunTotals($auditRunId);
            AuditRunRecord::updateAll(
                ['totalsJson' => Json::encode($totals), 'dateUpdated' => Db::prepareDateForDb(DateTimeHelper::now())],
                ['id' => $auditRunId]
            );
        }

        if ($previousStatus !== $run->status) {
            $this->log()->info('Run status changed', [
                'auditRunId' => $auditRunId,
                'status' => $run->status,
                'processed' => $run->processedEntries,
                'total' => $run->totalEntries,
                'remaining' => $remaining,
            ]);
        }
    }

    /**
     * @param int[] $sectionIds
     * @return Entry[]
     */
    public function findEntriesForSections(array $sectionIds = []): array
    {
        $query = Entry::find()
            ->status('live')
            ->siteId('*')
            ->orderBy(['title' => SORT_ASC]);

        if ($sectionIds !== []) {
            $query->sectionId($sectionIds);
        }

        return $query->all();
    }

    /**
     * @return AuditRunRecord[]
     */
    public function getRecentRuns(int $limit = 20): array
    {
        if (!Craft::$app->getDb()->tableExists(AuditRunRecord::tableName())) {
            return [];
        }

        return AuditRunRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getRunWithResults(int $runId): ?AuditRunRecord
    {
        return AuditRunRecord::findOne($runId);
    }

    /**
     * @return AuditResultRecord[]
     */
    public function getResultsForRun(int $runId): array
    {
        return AuditResultRecord::find()
            ->where(['auditRunId' => $runId])
            ->orderBy(['score' => SORT_ASC, 'title' => SORT_ASC])
            ->all();
    }

    /**
     * @return AuditIssueRecord[]
     */
    public function getIssuesForRun(int $runId): array
    {
        if (!Craft::$app->getDb()->tableExists(AuditIssueRecord::tableName())) {
            return [];
        }

        return AuditIssueRecord::find()
            ->where(['auditRunId' => $runId])
            ->orderBy(['priorityScore' => SORT_DESC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getIssueRowsForRun(int $runId, array $filters = [], int $limit = 1000): array
    {
        if (!Craft::$app->getDb()->tableExists(AuditIssueRecord::tableName())) {
            return [];
        }

        $query = (new Query())
            ->from(['i' => AuditIssueRecord::tableName()])
            ->leftJoin(['r' => AuditResultRecord::tableName()], '[[r.id]] = [[i.auditResultId]]')
            ->select([
                'i.id',
                'i.auditResultId',
                'i.issueCode',
                'i.severity',
                'i.status',
                'i.priorityScore',
                'i.ownerSuggestion',
                'i.message',
                'i.fixInstruction',
                'i.evidenceJson',
                'i.dateCreated',
                'r.entryId',
                'r.sectionId',
                'r.title',
                'r.url',
            ])
            ->where(['i.auditRunId' => $runId]);

        $severity = trim((string) ($filters['severity'] ?? ''));
        if ($severity !== '') {
            $query->andWhere(['i.severity' => $severity]);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->andWhere(['i.status' => $status]);
        }

        $issueCode = trim((string) ($filters['issueCode'] ?? ''));
        if ($issueCode !== '') {
            $query->andWhere(['i.issueCode' => $issueCode]);
        }

        $owner = trim((string) ($filters['ownerSuggestion'] ?? ''));
        if ($owner !== '') {
            $query->andWhere(['i.ownerSuggestion' => $owner]);
        }

        $sectionId = (int) ($filters['sectionId'] ?? 0);
        if ($sectionId > 0) {
            $query->andWhere(['r.sectionId' => $sectionId]);
        }

        $rows = $query
            ->orderBy([
                'i.priorityScore' => SORT_DESC,
                'i.severity' => SORT_ASC,
                'i.id' => SORT_ASC,
            ])
            ->limit(max(1, $limit))
            ->all();

        return $this->appendExternalSignalsToIssueRows($rows);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getIssueFilterOptionsForRun(int $runId): array
    {
        if (!Craft::$app->getDb()->tableExists(AuditIssueRecord::tableName())) {
            return [
                'severities' => [],
                'statuses' => [],
                'issueCodes' => [],
                'ownerSuggestions' => [],
            ];
        }

        return [
            'severities' => $this->distinctIssueValues($runId, 'severity'),
            'statuses' => $this->distinctIssueValues($runId, 'status'),
            'issueCodes' => $this->distinctIssueValues($runId, 'issueCode'),
            'ownerSuggestions' => $this->distinctIssueValues($runId, 'ownerSuggestion'),
            'sectionIds' => $this->distinctIssueSectionIds($runId),
        ];
    }

    /**
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getIssueClustersForRun(
        int $runId,
        array $filters = [],
        int $maxIssues = 20000,
        int $sampleSize = 5
    ): array {
        $rows = $this->getIssueRowsForRun($runId, $filters, $maxIssues);
        if ($rows === []) {
            return [];
        }

        $clusters = [];
        $sampleSize = max(1, $sampleSize);

        foreach ($rows as $row) {
            $issueCode = (string) ($row['issueCode'] ?? '');
            $severity = (string) ($row['severity'] ?? '');
            $owner = (string) ($row['ownerSuggestion'] ?? '');
            $sectionId = (int) ($row['sectionId'] ?? 0);
            $clusterKey = implode('|', [$issueCode, $severity]);

            if (!isset($clusters[$clusterKey])) {
                $clusters[$clusterKey] = [
                    'clusterKey' => $clusterKey,
                    'issueCode' => $issueCode,
                    'severity' => $severity,
                    'ownerSuggestion' => '',
                    'sectionId' => 0,
                    'affectedCount' => 0,
                    'maxPriorityScore' => 0,
                    'sampleUrls' => [],
                    'sampleTitles' => [],
                    'ownerCounts' => [],
                    'sectionCounts' => [],
                    'message' => (string) ($row['message'] ?? ''),
                    'fixInstruction' => (string) ($row['fixInstruction'] ?? ''),
                ];
            }

            $clusters[$clusterKey]['affectedCount']++;
            $clusters[$clusterKey]['maxPriorityScore'] = max(
                (int) $clusters[$clusterKey]['maxPriorityScore'],
                (int) ($row['priorityScore'] ?? 0)
            );

            if ($owner !== '') {
                $clusters[$clusterKey]['ownerCounts'][$owner] = (int) ($clusters[$clusterKey]['ownerCounts'][$owner] ?? 0) + 1;
            }
            if ($sectionId > 0) {
                $clusters[$clusterKey]['sectionCounts'][(string) $sectionId] = (int) ($clusters[$clusterKey]['sectionCounts'][(string) $sectionId] ?? 0) + 1;
            }

            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '' && count($clusters[$clusterKey]['sampleUrls']) < $sampleSize && !in_array($url, $clusters[$clusterKey]['sampleUrls'], true)) {
                $clusters[$clusterKey]['sampleUrls'][] = $url;
                $clusters[$clusterKey]['sampleTitles'][] = (string) ($row['title'] ?? $url);
            }
        }

        $clusterRows = [];
        foreach ($clusters as $cluster) {
            if ($cluster['ownerCounts'] !== []) {
                arsort($cluster['ownerCounts']);
                $cluster['ownerSuggestion'] = (string) array_key_first($cluster['ownerCounts']);
            }
            if ($cluster['sectionCounts'] !== []) {
                arsort($cluster['sectionCounts']);
                $cluster['sectionId'] = (int) array_key_first($cluster['sectionCounts']);
            }
            unset($cluster['ownerCounts'], $cluster['sectionCounts']);
            $clusterRows[] = $cluster;
        }

        usort($clusterRows, static function (array $a, array $b): int {
            $priorityCmp = ((int) $b['maxPriorityScore']) <=> ((int) $a['maxPriorityScore']);
            if ($priorityCmp !== 0) {
                return $priorityCmp;
            }

            return ((int) $b['affectedCount']) <=> ((int) $a['affectedCount']);
        });

        return $clusterRows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRunTrendComparison(int $runId): array
    {
        $currentRun = AuditRunRecord::findOne($runId);
        if (!$currentRun) {
            return [];
        }

        $previousRun = AuditRunRecord::find()
            ->where(['<', 'id', $runId])
            ->andWhere(['status' => 'completed'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if (!$previousRun) {
            return [
                'previousRunId' => null,
                'issueDelta' => 0,
                'severityDelta' => [],
                'issueCodeDelta' => [],
            ];
        }

        $currentIssueCount = (int) AuditIssueRecord::find()->where(['auditRunId' => $runId])->count();
        $previousIssueCount = (int) AuditIssueRecord::find()->where(['auditRunId' => $previousRun->id])->count();

        $currentSeverity = $this->issueSeverityCountsForRun($runId);
        $previousSeverity = $this->issueSeverityCountsForRun((int) $previousRun->id);
        $severityDelta = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $severityDelta[$severity] = (int) ($currentSeverity[$severity] ?? 0) - (int) ($previousSeverity[$severity] ?? 0);
        }

        $currentByCode = $this->issueCodeCountsForRun($runId);
        $previousByCode = $this->issueCodeCountsForRun((int) $previousRun->id);
        $allCodes = array_values(array_unique(array_merge(array_keys($currentByCode), array_keys($previousByCode))));
        $issueCodeDelta = [];
        foreach ($allCodes as $code) {
            $delta = (int) ($currentByCode[$code] ?? 0) - (int) ($previousByCode[$code] ?? 0);
            if ($delta !== 0) {
                $issueCodeDelta[$code] = $delta;
            }
        }
        arsort($issueCodeDelta);

        return [
            'previousRunId' => (int) $previousRun->id,
            'issueDelta' => $currentIssueCount - $previousIssueCount,
            'severityDelta' => $severityDelta,
            'issueCodeDelta' => array_slice($issueCodeDelta, 0, 10, true),
        ];
    }

    private function log(): AuditLogService
    {
        return Plugin::getInstance()->auditLog;
    }

    private function seedAuditUrlsForRun(int $auditRunId): void
    {
        if (!Craft::$app->getDb()->tableExists(AuditUrlRecord::tableName())) {
            return;
        }

        $results = AuditResultRecord::find()
            ->where(['auditRunId' => $auditRunId])
            ->all();

        foreach ($results as $result) {
            $existing = AuditUrlRecord::find()
                ->where(['auditResultId' => $result->id])
                ->one();

            if ($existing) {
                continue;
            }

            $urlRecord = new AuditUrlRecord();
            $urlRecord->auditRunId = $auditRunId;
            $urlRecord->auditResultId = $result->id;
            $urlRecord->entryId = $result->entryId;
            $urlRecord->url = $result->url;
            $urlRecord->normalizedUrl = $this->normalizeUrl($result->url);
            $urlRecord->normalizedUrlHash = md5($urlRecord->normalizedUrl);
            $urlRecord->status = 'queued';
            $urlRecord->sourceType = (int) $result->entryId > 0 ? 'entry' : 'sitemap';
            $urlRecord->depth = 0;
            $urlRecord->dateCreated = DateTimeHelper::now();
            $urlRecord->dateUpdated = $urlRecord->dateCreated;
            $urlRecord->save(false);
        }
    }

    private function upsertSignalsFromHtml(
        AuditResultRecord $result,
        int $auditRunId,
        string $html,
        int $fetchMs,
        string $snapshot
    ): void {
        if (!Craft::$app->getDb()->tableExists(AuditSignalRecord::tableName())
            || !Craft::$app->getDb()->tableExists(AuditIssueRecord::tableName())
            || !Craft::$app->getDb()->tableExists(AuditUrlRecord::tableName())) {
            return;
        }

        $auditUrl = AuditUrlRecord::find()
            ->where(['auditResultId' => $result->id])
            ->one();

        if (!$auditUrl) {
            $auditUrl = new AuditUrlRecord();
            $auditUrl->auditRunId = $auditRunId;
            $auditUrl->auditResultId = $result->id;
            $auditUrl->entryId = $result->entryId;
            $auditUrl->url = $result->url;
            $auditUrl->normalizedUrl = $this->normalizeUrl($result->url);
            $auditUrl->normalizedUrlHash = md5($auditUrl->normalizedUrl);
            $auditUrl->sourceType = (int) $result->entryId > 0 ? 'entry' : 'sitemap';
            $auditUrl->depth = 0;
            $auditUrl->dateCreated = DateTimeHelper::now();
        }

        $auditUrl->status = 'fetched';
        $auditUrl->dateUpdated = DateTimeHelper::now();
        $auditUrl->save(false);

        $title = $this->snapshotValue($snapshot, '<title>');
        $metaDescription = $this->snapshotValue($snapshot, 'meta[name=description]');
        $canonicalUrl = $this->snapshotValue($snapshot, 'link[rel=canonical]');
        $robotsMeta = $this->snapshotValue($snapshot, 'meta[name=robots]');
        $imagesWithoutAlt = (int) ($this->snapshotValue($snapshot, 'images_without_alt') ?: 0);
        preg_match_all('/<h1\b/i', $html, $h1Matches);
        $h1Count = count($h1Matches[0] ?? []);

        $signalPayload = [
            'title' => $title,
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $canonicalUrl,
            'robotsMeta' => $robotsMeta,
            'h1Count' => $h1Count,
            'imagesWithoutAlt' => $imagesWithoutAlt,
            'snapshotChars' => mb_strlen($snapshot),
            'analysisStatus' => $result->status,
        ];

        $signal = AuditSignalRecord::find()
            ->where(['auditResultId' => $result->id])
            ->one() ?? new AuditSignalRecord();
        $signal->auditRunId = $auditRunId;
        $signal->auditUrlId = $auditUrl->id;
        $signal->auditResultId = $result->id;
        $signal->httpStatus = null;
        $signal->responseTimeMs = $fetchMs;
        $signal->title = $title ?: null;
        $signal->metaDescription = $metaDescription ?: null;
        $signal->canonicalUrl = $canonicalUrl ?: null;
        $signal->robotsMeta = $robotsMeta ?: null;
        $signal->h1Count = $h1Count;
        $signal->imagesWithoutAlt = $imagesWithoutAlt;
        $signal->contentHash = hash('sha256', strip_tags($html));
        $signal->signalsJson = Json::encode($signalPayload);
        $signal->dateUpdated = DateTimeHelper::now();
        if (!$signal->dateCreated) {
            $signal->dateCreated = $signal->dateUpdated;
        }
        $signal->save(false);

    }

    private function snapshotValue(string $snapshot, string $key): string
    {
        if (!preg_match('/^' . preg_quote($key, '/') . ':\s*(.+)$/m', $snapshot, $matches)) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!$parts || !isset($parts['host'])) {
            return rtrim($url, '/');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $path = $path === '' ? '/' : $path;
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function queueDiscovery(int $auditRunId): void
    {
        $queue = Craft::$app->getQueue();
        $queue->push(new DiscoverAuditUrlsJob([
            'auditRunId' => $auditRunId,
        ]));
    }

    private function queueEvaluateResult(int $resultId, int $auditRunId): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $delay = $settings->enableLlm ? max(0, (int) $settings->requestDelaySeconds) : 0;
        if ($delay > 0 && Craft::$app->getDb()->tableExists('{{%queue}}')) {
            $queuedEvaluateJobs = (new Query())
                ->from(['{{%queue}}'])
                ->where(['like', 'description', 'AI SEO audit: evaluate one fetched page', false])
                ->count();
            $delay *= (int) $queuedEvaluateJobs;
        }
        $job = new EvaluateAuditResultJob([
            'resultId' => $resultId,
            'auditRunId' => $auditRunId,
        ]);

        $queue = Craft::$app->getQueue();
        if ($delay > 0) {
            $queue->delay($delay)->push($job);
        } else {
            $queue->push($job);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSignalPayloadForResult(AuditResultRecord $result): array
    {
        $signal = AuditSignalRecord::find()
            ->where(['auditResultId' => $result->id])
            ->one();

        if (!$signal || !is_string($signal->signalsJson) || trim($signal->signalsJson) === '') {
            throw new Exception('Missing signal payload for result #' . $result->id);
        }

        $decoded = Json::decode($signal->signalsJson);
        if (!is_array($decoded)) {
            throw new Exception('Invalid signal payload for result #' . $result->id);
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @param array<string, mixed> $signalPayload
     * @param array<string, mixed> $businessSignals
     */
    private function saveIssuesForResult(
        AuditResultRecord $result,
        int $auditRunId,
        array $issues,
        array $signalPayload,
        array $businessSignals = []
    ): void {
        $auditUrl = AuditUrlRecord::find()
            ->where(['auditResultId' => $result->id])
            ->one();

        foreach ($issues as $issueData) {
            $issue = new AuditIssueRecord();
            $issue->auditRunId = $auditRunId;
            $issue->auditUrlId = $auditUrl?->id;
            $issue->auditResultId = $result->id;
            $issue->issueCode = (string) $issueData['issueCode'];
            $issue->severity = (string) $issueData['severity'];
            $issue->status = 'open';
            $issue->priorityScore = Plugin::getInstance()->issuePrioritization->computePriorityScore($issueData, $businessSignals);
            $issue->message = DbTextHelper::sanitize((string) $issueData['message']);
            $issue->fixInstruction = DbTextHelper::sanitize((string) $issueData['fixInstruction']);
            $issue->ownerSuggestion = (string) $issueData['ownerSuggestion'];
            $issue->evidenceJson = Json::encode([
                'signals' => $signalPayload,
                'businessSignals' => $businessSignals,
            ]);
            $issue->dateCreated = DateTimeHelper::now();
            $issue->dateUpdated = $issue->dateCreated;
            $issue->save(false);
        }
    }

    /**
     * @return array<string, int>
     */
    private function buildRunTotals(int $runId): array
    {
        return [
            'issuesTotal' => (int) AuditIssueRecord::find()->where(['auditRunId' => $runId])->count(),
            'severity' => $this->issueSeverityCountsForRun($runId),
            'issuesByCode' => $this->issueCodeCountsForRun($runId),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function issueSeverityCountsForRun(int $runId): array
    {
        $rows = (new Query())
            ->from([AuditIssueRecord::tableName()])
            ->select(['severity', 'COUNT(*) AS total'])
            ->where(['auditRunId' => $runId])
            ->groupBy(['severity'])
            ->all();

        $counts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        foreach ($rows as $row) {
            $severity = strtolower((string) ($row['severity'] ?? ''));
            if (!array_key_exists($severity, $counts)) {
                continue;
            }
            $counts[$severity] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function issueCodeCountsForRun(int $runId): array
    {
        $rows = (new Query())
            ->from([AuditIssueRecord::tableName()])
            ->select(['issueCode', 'COUNT(*) AS total'])
            ->where(['auditRunId' => $runId])
            ->groupBy(['issueCode'])
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $issueCode = trim((string) ($row['issueCode'] ?? ''));
            if ($issueCode === '') {
                continue;
            }
            $counts[$issueCode] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function appendExternalSignalsToIssueRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $businessSignals = [];
            $evidenceJson = $row['evidenceJson'] ?? null;
            if (is_string($evidenceJson) && trim($evidenceJson) !== '') {
                try {
                    $decoded = Json::decode($evidenceJson);
                    if (is_array($decoded) && isset($decoded['businessSignals']) && is_array($decoded['businessSignals'])) {
                        $businessSignals = $decoded['businessSignals'];
                    }
                } catch (\Throwable) {
                    $businessSignals = [];
                }
            }

            $hasPsi = (bool) ($businessSignals['hasPsiMetrics'] ?? false);
            $hasHtmlValidation = (bool) ($businessSignals['hasHtmlValidationMetrics'] ?? false);
            $hasSitePolicy = (bool) ($businessSignals['hasSitePolicyMetrics'] ?? false);

            $row['hasPsiMetrics'] = $hasPsi;
            $row['psiAttempted'] = (bool) ($businessSignals['psiAttempted'] ?? false);
            $row['psiStatus'] = (string) ($businessSignals['psiStatus'] ?? 'not_attempted');
            $row['psiHttpStatus'] = (int) ($businessSignals['psiHttpStatus'] ?? 0);
            $row['psiMissingReason'] = (string) ($businessSignals['psiMissingReason'] ?? '');
            $row['hasHtmlValidationMetrics'] = $hasHtmlValidation;
            $row['hasSitePolicyMetrics'] = $hasSitePolicy;
            $row['externalChecksAttempted'] = (bool) ($businessSignals['externalChecksAttempted'] ?? false);
            $row['externalChecksSucceeded'] = (bool) ($businessSignals['externalChecksSucceeded'] ?? false);
            $row['externalChecksSampledOut'] = (bool) ($businessSignals['externalChecksSampledOut'] ?? false);
            $row['externalUrlLikelyPrivate'] = (bool) ($businessSignals['externalUrlLikelyPrivate'] ?? false);
            $row['externalMetricsNote'] = (string) ($businessSignals['externalMetricsNote'] ?? '');

            $row['performanceScore'] = $hasPsi ? (float) ($businessSignals['performanceScore'] ?? 0.0) : null;
            $row['lcpMs'] = $hasPsi ? (float) ($businessSignals['lcpMs'] ?? 0.0) : null;
            $row['inpMs'] = $hasPsi ? (float) ($businessSignals['inpMs'] ?? 0.0) : null;
            $row['cls'] = $hasPsi ? (float) ($businessSignals['cls'] ?? 0.0) : null;

            $row['htmlErrorCount'] = $hasHtmlValidation ? (int) ($businessSignals['htmlErrorCount'] ?? 0) : null;
            $row['htmlWarningCount'] = $hasHtmlValidation ? (int) ($businessSignals['htmlWarningCount'] ?? 0) : null;
            $row['htmlValidatorUrl'] = $hasHtmlValidation ? (string) ($businessSignals['htmlValidatorUrl'] ?? '') : '';
            $row['htmlErrorExamples'] = $hasHtmlValidation && isset($businessSignals['htmlErrorExamples']) && is_array($businessSignals['htmlErrorExamples'])
                ? array_values(array_map('strval', $businessSignals['htmlErrorExamples']))
                : [];
            $row['htmlWarningExamples'] = $hasHtmlValidation && isset($businessSignals['htmlWarningExamples']) && is_array($businessSignals['htmlWarningExamples'])
                ? array_values(array_map('strval', $businessSignals['htmlWarningExamples']))
                : [];
            $row['htmlErrorExamplesText'] = $row['htmlErrorExamples'] !== [] ? implode(' || ', $row['htmlErrorExamples']) : '';
            $row['htmlWarningExamplesText'] = $row['htmlWarningExamples'] !== [] ? implode(' || ', $row['htmlWarningExamples']) : '';

            $row['robotsBlockedByPath'] = $hasSitePolicy ? (bool) ($businessSignals['robotsBlockedByPath'] ?? false) : null;
            $row['robotsDisallowAll'] = $hasSitePolicy ? (bool) ($businessSignals['robotsDisallowAll'] ?? false) : null;
            $row['sitemapReachable'] = $hasSitePolicy ? (bool) ($businessSignals['sitemapReachable'] ?? false) : null;
            $row['sitemapDirectivePresent'] = $hasSitePolicy ? (bool) ($businessSignals['sitemapDirectivePresent'] ?? false) : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * @return string[]
     */
    private function distinctIssueValues(int $runId, string $column): array
    {
        $rows = (new Query())
            ->from([AuditIssueRecord::tableName()])
            ->select([$column])
            ->where(['auditRunId' => $runId])
            ->andWhere(['not', [$column => null]])
            ->distinct()
            ->orderBy([$column => SORT_ASC])
            ->column();

        return array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $rows), static fn(string $v): bool => $v !== ''));
    }

    /**
     * @return int[]
     */
    private function distinctIssueSectionIds(int $runId): array
    {
        $rows = (new Query())
            ->from(['i' => AuditIssueRecord::tableName()])
            ->leftJoin(['r' => AuditResultRecord::tableName()], '[[r.id]] = [[i.auditResultId]]')
            ->select(['r.sectionId'])
            ->where(['i.auditRunId' => $runId])
            ->andWhere(['>', 'r.sectionId', 0])
            ->distinct()
            ->orderBy(['r.sectionId' => SORT_ASC])
            ->column();

        return array_values(array_map('intval', $rows));
    }

    private function titleFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return (string) ($parts['host'] ?? $url);
        }

        $lastSegment = (string) preg_replace('/[-_]+/', ' ', basename($path));
        $title = ucwords($lastSegment);

        return $title !== '' ? $title : $url;
    }

    private function resolveFetchUrl(string $url): string
    {
        $base = trim(Plugin::getInstance()->getSettings()->frontendBaseUrl);
        if ($base === '') {
            return $url;
        }
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $path .= '#' . $parsed['fragment'];
        }
        return rtrim($base, '/') . $path;
    }

    /**
     * @param mixed[] $sectionIds
     * @return int[]
     */
    private function normalizeSectionIds(array $sectionIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map('intval', $sectionIds), static fn(int $id): bool => $id > 0)));

        sort($normalized);

        return $normalized;
    }
}
