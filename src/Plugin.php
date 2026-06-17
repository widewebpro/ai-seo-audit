<?php

namespace wideweb\aiseoaudit;

use wideweb\aiseoaudit\models\Settings;
use wideweb\aiseoaudit\services\AuditLogService;
use wideweb\aiseoaudit\services\AuditService;
use wideweb\aiseoaudit\services\ExternalMetricsService;
use wideweb\aiseoaudit\services\IssuePrioritizationService;
use wideweb\aiseoaudit\services\LlmService;
use wideweb\aiseoaudit\services\PageFetcherService;
use wideweb\aiseoaudit\services\RuleEngineService;
use wideweb\aiseoaudit\services\UrlDiscoveryService;
use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * @property AuditService $audit
 * @property AuditLogService $auditLog
 * @property ExternalMetricsService $externalMetrics
 * @property IssuePrioritizationService $issuePrioritization
 * @property LlmService $llm
 * @property PageFetcherService $pageFetcher
 * @property RuleEngineService $ruleEngine
 * @property UrlDiscoveryService $urlDiscovery
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.3.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }
        $item['label'] = Craft::t('ai-seo-audit', 'AI SEO Audit');
        $item['icon'] = '@wideweb/aiseoaudit/icon.svg';
        $item['badgeCount'] = $this->audit->getPendingResultsCount();
        return $item;
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@wideweb/aiseoaudit', __DIR__);

        $this->setComponents([
            'audit' => AuditService::class,
            'auditLog' => AuditLogService::class,
            'externalMetrics' => ExternalMetricsService::class,
            'issuePrioritization' => IssuePrioritizationService::class,
            'llm' => LlmService::class,
            'pageFetcher' => PageFetcherService::class,
            'ruleEngine' => RuleEngineService::class,
            'urlDiscovery' => UrlDiscoveryService::class,
        ]);

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['ai-seo-audit/view/<runId:\d+>'] = 'ai-seo-audit/default/view';
                $event->rules['ai-seo-audit/issues/<runId:\d+>'] = 'ai-seo-audit/default/issues';
                $event->rules['ai-seo-audit/export/<runId:\d+>'] = 'ai-seo-audit/default/export';
                $event->rules['ai-seo-audit/export-issues/<runId:\d+>'] = 'ai-seo-audit/default/export-issues';
                $event->rules['ai-seo-audit/export-clusters/<runId:\d+>'] = 'ai-seo-audit/default/export-clusters';
                $event->rules['ai-seo-audit/export-client-report/<runId:\d+>'] = 'ai-seo-audit/default/export-client-report';
                $event->rules['ai-seo-audit/logs'] = 'ai-seo-audit/default/logs';
            }
        );
    }

    public function setSettings(array $settings): void
    {
        if (!isset($settings['llmProvider'])) {
            $settings['llmProvider'] = 'openrouter';
        }

        if (!isset($settings['selectedSectionIds'])) {
            $settings['selectedSectionIds'] = [];
        }

        if (!isset($settings['enableLlm'])) {
            $settings['enableLlm'] = false;
        }

        if (!isset($settings['anthropicVersion'])) {
            $settings['anthropicVersion'] = '2023-06-01';
        }

        if (!isset($settings['geminiApiVersion'])) {
            $settings['geminiApiVersion'] = 'v1beta';
        }

        if (!isset($settings['enableSitemapDiscovery'])) {
            $settings['enableSitemapDiscovery'] = false;
        }

        if (!isset($settings['maxDiscoveredUrls'])) {
            $settings['maxDiscoveredUrls'] = 5000;
        }

        if (!isset($settings['pageSpeedApiKey'])) {
            $settings['pageSpeedApiKey'] = '';
        }

        parent::setSettings($settings);
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('ai-seo-audit/settings', [
            'settings' => $this->getSettings(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
        ]);
    }
}
