<?php

namespace wideweb\aiseoaudit\services;

use wideweb\aiseoaudit\records\AuditResultRecord;
use wideweb\aiseoaudit\records\AuditRunRecord;
use wideweb\aiseoaudit\records\AuditIssueRecord;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\FileHelper;

class AuditLogService extends Component
{
    private const LOG_FILE = 'ai-seo-audit.log';

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            '[%s] [%s] %s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message
        );

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        FileHelper::writeToFile($this->getLogPath(), $line . PHP_EOL, ['append' => true]);

        match ($level) {
            'error' => Craft::error($line, 'ai-seo-audit'),
            'warning' => Craft::warning($line, 'ai-seo-audit'),
            default => Craft::info($line, 'ai-seo-audit'),
        };
    }

    public function getLogPath(): string
    {
        return Craft::getAlias('@storage/logs/' . self::LOG_FILE);
    }

    public function getTail(int $maxLines = 300): string
    {
        $path = $this->getLogPath();

        if (!is_file($path)) {
            return '';
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false || $lines === []) {
            return '';
        }

        return implode("\n", array_slice($lines, -$maxLines));
    }

    public function clear(): void
    {
        $path = $this->getLogPath();

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function getDebugStats(): array
    {
        $stats = [
            'pendingResults' => 0,
            'runningRuns' => 0,
            'openIssues' => 0,
            'pluginQueueJobs' => 0,
            'totalQueueJobs' => 0,
        ];

        if (Craft::$app->getDb()->tableExists(AuditResultRecord::tableName())) {
            $stats['pendingResults'] = (int) AuditResultRecord::find()
                ->where(['status' => ['pending', 'fetched']])
                ->count();
        }

        if (Craft::$app->getDb()->tableExists(AuditRunRecord::tableName())) {
            $stats['runningRuns'] = (int) AuditRunRecord::find()
                ->where(['status' => ['pending', 'running']])
                ->count();
        }

        if (Craft::$app->getDb()->tableExists(AuditIssueRecord::tableName())) {
            $stats['openIssues'] = (int) AuditIssueRecord::find()
                ->where(['status' => 'open'])
                ->count();
        }

        if (Craft::$app->getDb()->tableExists('{{%queue}}')) {
            $stats['totalQueueJobs'] = (int) (new Query())
                ->from(['{{%queue}}'])
                ->count();

            $stats['pluginQueueJobs'] = (int) (new Query())
                ->from(['{{%queue}}'])
                ->where(['like', 'description', 'AI SEO audit', false])
                ->count();
        }

        return $stats;
    }
}
