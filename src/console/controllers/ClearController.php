<?php

namespace wideweb\aiseoaudit\console\controllers;

use wideweb\aiseoaudit\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

class ClearController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        if (!$this->confirm('Delete all AI SEO audit runs and results?')) {
            $this->stdout("Cancelled.\n");

            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->audit->clearAllAudits();
        $this->stdout(Console::renderColoredString("%g{$count} audit run(s) deleted.%n\n"));

        return ExitCode::OK;
    }
}
