<?php

namespace wideweb\aiseoaudit\migrations;

use craft\db\Migration;

class m260526_120000_fix_results_utf8mb4 extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%ai_seo_audit_results}}')) {
            return true;
        }

        $this->execute('ALTER TABLE {{%ai_seo_audit_results}} MODIFY `htmlExcerpt` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        $this->execute('ALTER TABLE {{%ai_seo_audit_results}} MODIFY `analysis` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        $this->execute('ALTER TABLE {{%ai_seo_audit_results}} MODIFY `errorMessage` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        $this->execute('ALTER TABLE {{%ai_seo_audit_results}} MODIFY `title` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');
        $this->execute('ALTER TABLE {{%ai_seo_audit_results}} MODIFY `url` VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
