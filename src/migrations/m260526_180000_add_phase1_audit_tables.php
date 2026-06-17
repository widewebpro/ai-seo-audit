<?php

namespace wideweb\aiseoaudit\migrations;

use craft\db\Migration;

class m260526_180000_add_phase1_audit_tables extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%ai_seo_audit_runs}}') || !$this->db->tableExists('{{%ai_seo_audit_results}}')) {
            return true;
        }

        if (!$this->db->tableExists('{{%ai_seo_audit_urls}}')) {
            $this->createTable('{{%ai_seo_audit_urls}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'auditRunId' => $this->integer()->notNull(),
                'auditResultId' => $this->integer(),
                'entryId' => $this->integer(),
                'url' => $this->string(2048)->notNull(),
                'normalizedUrl' => $this->string(2048)->notNull(),
                'status' => $this->string(32)->notNull()->defaultValue('queued'),
                'sourceType' => $this->string(32)->notNull()->defaultValue('entry'),
                'depth' => $this->smallInteger()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%ai_seo_audit_urls}}', ['auditRunId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_urls}}', ['auditResultId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_urls}}', ['status'], false);
            $this->createIndex(null, '{{%ai_seo_audit_urls}}', ['auditRunId', 'normalizedUrl'], true);

            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_urls}}',
                'auditRunId',
                '{{%ai_seo_audit_runs}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_urls}}',
                'auditResultId',
                '{{%ai_seo_audit_results}}',
                'id',
                'SET NULL',
                'CASCADE'
            );
        }

        if (!$this->db->tableExists('{{%ai_seo_audit_signals}}')) {
            $this->createTable('{{%ai_seo_audit_signals}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'auditRunId' => $this->integer()->notNull(),
                'auditUrlId' => $this->integer(),
                'auditResultId' => $this->integer(),
                'httpStatus' => $this->integer(),
                'responseTimeMs' => $this->integer(),
                'title' => $this->string(512),
                'metaDescription' => $this->text(),
                'canonicalUrl' => $this->string(2048),
                'robotsMeta' => $this->string(512),
                'h1Count' => $this->smallInteger(),
                'imagesWithoutAlt' => $this->integer(),
                'contentHash' => $this->string(64),
                'signalsJson' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%ai_seo_audit_signals}}', ['auditRunId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_signals}}', ['auditUrlId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_signals}}', ['auditResultId'], false);

            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_signals}}',
                'auditRunId',
                '{{%ai_seo_audit_runs}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_signals}}',
                'auditUrlId',
                '{{%ai_seo_audit_urls}}',
                'id',
                'SET NULL',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_signals}}',
                'auditResultId',
                '{{%ai_seo_audit_results}}',
                'id',
                'SET NULL',
                'CASCADE'
            );
        }

        if (!$this->db->tableExists('{{%ai_seo_audit_issues}}')) {
            $this->createTable('{{%ai_seo_audit_issues}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'auditRunId' => $this->integer()->notNull(),
                'auditUrlId' => $this->integer(),
                'auditResultId' => $this->integer(),
                'issueCode' => $this->string(64)->notNull(),
                'severity' => $this->string(16)->notNull()->defaultValue('low'),
                'status' => $this->string(16)->notNull()->defaultValue('open'),
                'priorityScore' => $this->integer()->notNull()->defaultValue(0),
                'message' => $this->text(),
                'fixInstruction' => $this->text(),
                'ownerSuggestion' => $this->string(32),
                'evidenceJson' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%ai_seo_audit_issues}}', ['auditRunId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_issues}}', ['auditUrlId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_issues}}', ['auditResultId'], false);
            $this->createIndex(null, '{{%ai_seo_audit_issues}}', ['severity'], false);
            $this->createIndex(null, '{{%ai_seo_audit_issues}}', ['priorityScore'], false);
            $this->createIndex(null, '{{%ai_seo_audit_issues}}', ['auditResultId', 'issueCode'], false);

            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_issues}}',
                'auditRunId',
                '{{%ai_seo_audit_runs}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_issues}}',
                'auditUrlId',
                '{{%ai_seo_audit_urls}}',
                'id',
                'SET NULL',
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%ai_seo_audit_issues}}',
                'auditResultId',
                '{{%ai_seo_audit_results}}',
                'id',
                'SET NULL',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%ai_seo_audit_issues}}');
        $this->dropTableIfExists('{{%ai_seo_audit_signals}}');
        $this->dropTableIfExists('{{%ai_seo_audit_urls}}');

        return true;
    }
}
