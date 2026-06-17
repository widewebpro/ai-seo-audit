<?php

namespace wideweb\aiseoaudit\helpers;

/**
 * Ensures text can be stored in MySQL utf8mb4 (or strips 4-byte chars for legacy utf8 columns).
 */
class DbTextHelper
{
    public static function sanitize(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Remove UTF-8 sequences that require 4 bytes (emojis, etc.)
        $sanitized = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

        if ($sanitized === null) {
            $sanitized = preg_replace('/(?:\xF0[\x90-\xBF][\x80-\xBF]{2}|\xF1[\x80-\xBF]{3}|\xF2[\x80-\xBF]{3}|\xF3[\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})/', '', $text) ?? $text;
        }

        return $sanitized;
    }
}
