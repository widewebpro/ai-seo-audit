<?php

namespace wideweb\aiseoaudit\helpers;

/**
 * Reduces page HTML to a compact SEO snapshot for the LLM (no scripts, styles, or body copy).
 */
class SeoHtmlExtractor
{
    public static function extract(string $html, int $maxLength = 3500): string
    {
        $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/iu', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/iu', '', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/iu', '', $html) ?? $html;
        $html = preg_replace('/<!--[\s\S]*?-->/', '', $html) ?? $html;

        $lines = [];

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);

        $htmlEl = $doc->getElementsByTagName('html')->item(0);
        if ($htmlEl instanceof \DOMElement && $htmlEl->hasAttribute('lang')) {
            $lines[] = 'html[lang]: ' . self::oneLine($htmlEl->getAttribute('lang'));
        }

        $title = $doc->getElementsByTagName('title')->item(0);
        if ($title) {
            $lines[] = '<title>: ' . self::oneLine($title->textContent);
        }

        foreach (['description', 'robots', 'viewport'] as $name) {
            $node = $xpath->query("//meta[@name='{$name}']")->item(0);
            if ($node instanceof \DOMElement) {
                $lines[] = "meta[name={$name}]: " . self::oneLine((string) $node->getAttribute('content'));
            }
        }

        $canonical = $xpath->query("//link[@rel='canonical']")->item(0);
        if ($canonical instanceof \DOMElement) {
            $lines[] = 'link[rel=canonical]: ' . self::oneLine((string) $canonical->getAttribute('href'));
        }

        foreach (['og:title', 'og:description', 'og:image'] as $prop) {
            $node = $xpath->query("//meta[@property='{$prop}']")->item(0);
            if ($node instanceof \DOMElement) {
                $lines[] = "meta[property={$prop}]: " . self::oneLine((string) $node->getAttribute('content'));
            }
        }

        $hCount = 0;
        foreach (['h1', 'h2', 'h3'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $heading) {
                if ($hCount >= 8) {
                    break 2;
                }
                $text = self::oneLine($heading->textContent);
                if ($text !== '') {
                    $lines[] = "<{$tag}>: {$text}";
                    $hCount++;
                }
            }
        }

        $missingAlt = 0;
        $altExamples = [];
        foreach ($doc->getElementsByTagName('img') as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }
            $alt = trim($img->getAttribute('alt'));
            if ($alt === '') {
                $missingAlt++;
                if (count($altExamples) < 3) {
                    $src = self::oneLine($img->getAttribute('src'));
                    $altExamples[] = $src !== '' ? $src : '(no src)';
                }
            }
        }
        $lines[] = 'images_without_alt: ' . $missingAlt;
        if ($altExamples !== []) {
            $lines[] = 'images_without_alt_examples: ' . implode(', ', $altExamples);
        }

        $jsonLd = $xpath->query("//script[@type='application/ld+json']");
        if ($jsonLd->length > 0) {
            $snippet = self::oneLine($jsonLd->item(0)?->textContent ?? '');
            $lines[] = 'json_ld_present: yes';
            if ($snippet !== '') {
                $lines[] = 'json_ld_snippet: ' . mb_substr($snippet, 0, 400);
            }
        } else {
            $lines[] = 'json_ld_present: no';
        }

        $text = implode("\n", $lines);

        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength) . '…';
        }

        return $text;
    }

    private static function oneLine(?string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
        $value = DbTextHelper::sanitize($value) ?? '';

        return mb_substr($value, 0, 300);
    }
}
