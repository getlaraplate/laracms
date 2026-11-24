<?php

declare(strict_types=1);

namespace Modules\CMS\ApplicationContent;

use Carbon\CarbonInterface;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\Core\ApplicationContent\Data\ApplicationContentHit;
use Modules\Core\Helpers\LocaleContext;

final class CmsContentEvidenceProjector
{
    /**
     * Only these content-bearing component fields may enter assistant evidence.
     * Metadata and unknown dynamic fields remain private by default.
     */
    private const array TEXT_FIELDS = [
        'body',
        'content',
        'description',
        'excerpt',
        'short_content',
        'subtitle',
        'summary',
        'text',
    ];

    private const array NESTED_TEXT_KEYS = [
        'body',
        'caption',
        'content',
        'description',
        'excerpt',
        'html',
        'label',
        'summary',
        'text',
        'title',
        'value',
    ];

    private const array NESTED_CONTAINER_KEYS = ['blocks', 'children', 'data', 'items'];

    public function project(
        Content $content,
        string $requestedLocale,
        string $strategy,
        ?float $score,
    ): ?ApplicationContentHit {
        $translation = $this->translation($content, $requestedLocale);

        if (! $translation instanceof ContentTranslation) {
            return null;
        }

        $label = $this->plainText($translation->title);

        if ($label === '') {
            return null;
        }

        [$excerpt, $excerpt_truncated] = $this->excerpt($translation, $label);
        [$label, $label_truncated] = $this->truncate(
            $label,
            $this->maximum('max_label_chars', 200, 500),
        );
        $record_key = $content->getKey();

        if (! is_int($record_key) && ! is_string($record_key)) {
            return null;
        }

        return new ApplicationContentHit(
            id: 'cms.contents:' . $record_key,
            source: 'cms.contents',
            module: 'cms',
            entity: 'contents',
            recordKey: $record_key,
            excerpt: $excerpt,
            label: $label,
            canonicalReference: '/app/cms/contents/' . rawurlencode((string) $record_key),
            locale: $translation->locale,
            strategy: $strategy,
            score: $score === null ? null : max(0.0, min(1.0, $score)),
            revision: $this->revision($content->updated_at),
            truncated: $excerpt_truncated || $label_truncated,
        );
    }

    private function translation(Content $content, string $locale): ?ContentTranslation
    {
        $translations = $content->translations;
        $translation = $translations->firstWhere('locale', $locale);

        if ($translation instanceof ContentTranslation) {
            return $translation;
        }

        if (! $content->translationFallbackEnabledBySettings()) {
            return null;
        }

        $default_locale = LocaleContext::getDefault();
        $fallback = $translations->firstWhere('locale', $default_locale);

        return $fallback instanceof ContentTranslation ? $fallback : null;
    }

    /**
     * @return array{string, bool}
     */
    private function excerpt(ContentTranslation $translation, string $fallback): array
    {
        $parts = [];
        $components = $translation->components;

        foreach (self::TEXT_FIELDS as $field) {
            if (! array_key_exists($field, $components)) {
                continue;
            }

            $this->collectText($components[$field], $parts);
        }

        $plain_text = $this->plainText(implode(' ', $parts));

        if ($plain_text === '') {
            $plain_text = $fallback;
        }

        return $this->truncate(
            $plain_text,
            $this->maximum('max_excerpt_chars', 2000, 8000),
        );
    }

    /**
     * @param  list<string>  $parts
     */
    private function collectText(mixed $value, array &$parts): void
    {
        if (is_string($value)) {
            if ($value !== '') {
                $parts[] = $value;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nested) {
            if (is_int($key) && is_array($nested)) {
                $this->collectText($nested, $parts);

                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            if (in_array($key, self::NESTED_TEXT_KEYS, true)) {
                $this->collectText($nested, $parts);

                continue;
            }

            if (in_array($key, self::NESTED_CONTAINER_KEYS, true) && is_array($nested)) {
                $this->collectText($nested, $parts);
            }
        }
    }

    private function plainText(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_trim($value);
    }

    /**
     * @return array{string, bool}
     */
    private function truncate(string $value, int $maximum): array
    {
        if ($maximum >= mb_strlen($value)) {
            return [$value, false];
        }

        if ($maximum === 1) {
            return ['…', true];
        }

        return [mb_substr($value, 0, $maximum - 1) . '…', true];
    }

    private function maximum(string $key, int $default, int $hardMaximum): int
    {
        return min($hardMaximum, max(1, (int) config('application-content.' . $key, $default)));
    }

    private function revision(mixed $updatedAt): ?string
    {
        return $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null;
    }
}
