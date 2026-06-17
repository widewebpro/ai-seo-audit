<?php

namespace wideweb\aiseoaudit\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property int $auditRunId
 * @property int|null $auditResultId
 * @property int|null $entryId
 * @property string $url
 * @property string $normalizedUrl
 * @property string $status
 * @property string $sourceType
 * @property int $depth
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 */
class AuditUrlRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%ai_seo_audit_urls}}';
    }
}
