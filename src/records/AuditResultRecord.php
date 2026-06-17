<?php

namespace wideweb\aiseoaudit\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property int $auditRunId
 * @property int $entryId
 * @property int $sectionId
 * @property string $url
 * @property string|null $title
 * @property int|null $score
 * @property string|null $analysis
 * @property string|null $htmlExcerpt
 * @property string $status
 * @property string|null $errorMessage
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 */
class AuditResultRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%ai_seo_audit_results}}';
    }
}
