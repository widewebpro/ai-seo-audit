<?php

namespace wideweb\aiseoaudit\jobs;

use wideweb\aiseoaudit\Plugin;
use craft\queue\BaseJob;
use Throwable;

class ProcessAuditPageJob extends BaseJob
{
    public int $resultId;

    public int $auditRunId;

    public function execute($queue): void
    {
        Plugin::getInstance()->auditLog->info('Queue job execute', [
            'job' => static::class,
            'resultId' => $this->resultId,
            'auditRunId' => $this->auditRunId,
        ]);

        try {
            Plugin::getInstance()->audit->processPage($this->resultId, $this->auditRunId);
        } catch (Throwable $e) {
            Plugin::getInstance()->auditLog->error('Queue job uncaught exception', [
                'resultId' => $this->resultId,
                'auditRunId' => $this->auditRunId,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'AI SEO audit: fetch one page (result #' . $this->resultId . ')';
    }
}
