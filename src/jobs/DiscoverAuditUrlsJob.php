<?php

namespace wideweb\aiseoaudit\jobs;

use wideweb\aiseoaudit\Plugin;
use craft\queue\BaseJob;
use Throwable;

class DiscoverAuditUrlsJob extends BaseJob
{
    public int $auditRunId;

    public function execute($queue): void
    {
        Plugin::getInstance()->auditLog->info('Discover job execute', [
            'job' => static::class,
            'auditRunId' => $this->auditRunId,
        ]);

        try {
            Plugin::getInstance()->audit->discoverAndQueueRun($this->auditRunId);
        } catch (Throwable $e) {
            Plugin::getInstance()->auditLog->error('Discover job failed', [
                'auditRunId' => $this->auditRunId,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'AI SEO audit: discover URLs';
    }
}
