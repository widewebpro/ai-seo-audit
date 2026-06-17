<?php

namespace wideweb\aiseoaudit\migrations;

use craft\db\Migration;

class m260527_140000_add_phase2_external_metrics extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%ai_seo_audit_runs}}')
            || !$this->db->tableExists('{{%ai_seo_audit_results}}')) {
            return true;
        }

        if (!$this->db->tableExists('{{%ai_seo_audit_external_metrics}}')) {
            $this->createTable('{{%ai_seo_audit_external_metrics}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'auditRunId' => $this->integer()->notNull(),
                'auditUrlId' => $this->integer(),
                'auditResultId' => $this->integer(),
                'source' => $this->string(32)->notNull(),
                'metricsJson' => $this->text(),
                'dateBucket' => $this->string(32),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%ai_seo_audit_external_metrics}}', ['auditRunId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_external_metrics}}', ['auditUrlId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_external_metrics}}', ['auditResultId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_external_metrics}}', ['source'], false);
            $this->createIndex(null, '{{%ai_seo_audit_external_metrics}}', ['auditRunId', 'auditResultId', 'source'], true);

            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_external_metrics}}',
                'auditRunId',
                '{{%ai_seo_audit_runs}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_external_metrics}}',
                'auditUrlId',
                '{{%ai_seo_audit_urls}}',
                'id',
                'SET NULL',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_external_metrics}}',
                'auditResultId',
                '{{%ai_seo_audit_results}}',
                'id',
                'SET NULL',
                'CASCADE'
            );
        }

        if ($this->db->columnExists('{{%ai_seo_audit_runs}}', 'totalsJson') === false) {
            $this->addColumn('{{%ai_seo_audit_runs}}', 'totalsJson', $this->text()->after('processedEntries'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%ai_seo_audit_runs}}', 'totalsJson')) {
            $this->dropColumn('{{%ai_seo_audit_runs}}', 'totalsJson');
        }

        $this->dropTableIfExists('{{%ai_seo_audit_external_metrics}}');

        return true;
    }
}
