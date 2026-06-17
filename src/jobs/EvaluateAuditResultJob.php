<?php

namespace wideweb\aiseoaudit\jobs;

use wideweb\aiseoaudit\Plugin;
use craft\queue\BaseJob;
use Throwable;

class EvaluateAuditResultJob extends BaseJob
{
    public int $resultId;

    public int $auditRunId;

    public function execute($queue): void
    {
        Plugin::getInstance()->auditLog->info('Evaluate job execute', [
            'job' => static::class,
            'resultId' => $this->resultId,
            'auditRunId' => $this->auditRunId,
        ]);

        try {
            Plugin::getInstance()->audit->evaluateFetchedPage($this->resultId, $this->auditRunId);
        } catch (Throwable $e) {
            Plugin::getInstance()->auditLog->error('Evaluate job uncaught exception', [
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
        return 'AI SEO audit: evaluate one fetched page (result #' . $this->resultId . ')';
    }
}
