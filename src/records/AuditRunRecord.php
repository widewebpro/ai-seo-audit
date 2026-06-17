<?php

namespace wideweb\aiseoaudit\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property string $status
 * @property string|null $sectionIds
 * @property int $totalEntries
 * @property int $processedEntries
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 */
class AuditRunRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%ai_seo_audit_runs}}';
    }

    /**
     * @return int[]
     */
    public function getSectionIdsArray(): array
    {
        if (empty($this->sectionIds)) {
            return [];
        }

        $decoded = json_decode($this->sectionIds, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    public function setSectionIdsArray(array $sectionIds): void
    {
        $this->sectionIds = json_encode(array_values(array_map('intval', $sectionIds)));
    }
}
