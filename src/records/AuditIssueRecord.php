<?php

namespace wideweb\aiseoaudit\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property int $auditRunId
 * @property int|null $auditUrlId
 * @property int|null $auditResultId
 * @property string $issueCode
 * @property string $severity
 * @property string $status
 * @property int $priorityScore
 * @property string|null $message
 * @property string|null $fixInstruction
 * @property string|null $ownerSuggestion
 * @property string|null $evidenceJson
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 */
class AuditIssueRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%ai_seo_audit_issues}}';
    }
}
