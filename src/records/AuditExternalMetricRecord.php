<?php

namespace wideweb\aiseoaudit\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property int $auditRunId
 * @property int|null $auditUrlId
 * @property int|null $auditResultId
 * @property string $source
 * @property string|null $metricsJson
 * @property string|null $dateBucket
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 */
class AuditExternalMetricRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%ai_seo_audit_external_metrics}}';
    }
}
