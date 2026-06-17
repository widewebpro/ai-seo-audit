<?php

namespace wideweb\aiseoaudit\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property int $auditRunId
 * @property int|null $auditUrlId
 * @property int|null $auditResultId
 * @property int|null $httpStatus
 * @property int|null $responseTimeMs
 * @property string|null $title
 * @property string|null $metaDescription
 * @property string|null $canonicalUrl
 * @property string|null $robotsMeta
 * @property int|null $h1Count
 * @property int|null $imagesWithoutAlt
 * @property string|null $contentHash
 * @property string|null $signalsJson
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 */
class AuditSignalRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%ai_seo_audit_signals}}';
    }
}
