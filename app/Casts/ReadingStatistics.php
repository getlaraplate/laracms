<?php

declare(strict_types=1);

namespace Modules\CMS\Casts;

use JsonSerializable;
use Modules\Core\Support\EditorPlainText;

/**
 * Reading metrics for Editor.js block JSON, aligned with the newsroom Editor + StringUtils.js logic.
 *
 * Which parts of a block are text, and the plain text itself, come from Core {@see EditorPlainText}: the Editor field
 * type is Core's, and the search index reads the same text (spec 2026-11-17-extended-entity-search-design.md, XS15).
 */
final readonly class ReadingStatistics implements JsonSerializable
{
    /**
     * @var array<string, float>
     */
    private const array BLOCK_COMPLEXITY = [
        'paragraph' => 1.0,
        'header' => 0.8,
        'list' => 0.9,
        'quote' => 1.1,
        'code' => 1.3,
        'image' => 1.2,
        'video' => 1.5,
        'audio' => 1.4,
        'table' => 1.2,
        'embed' => 1.3,
        'gallery' => 1.4,
        'warning' => 1.1,
        'alert' => 1.1,
        'checklist' => 0.9,
        'delimiter' => 0.1,
        'raw' => 1.2,
        'box' => 1.0,
        'link' => 1.0,
        'linkBlock' => 1.0,
    ];

    public function __construct(
        public int $characters,
        public int $words,
        public int $sentences,
        public int $paragraphs,
        /**
         * Estimated reading duration in seconds (aligned with StringUtils.calculateReadingTime in the Editor).
         */
        public float $reading_time,
    ) {}

    /**
     * Build statistics from Editor.js `blocks` array (as stored in JSON).
     *
     * @param  iterable<int, mixed>  $blocks
     */
    public static function fromBlocks(iterable $blocks): self
    {
        $blocks_list = EditorPlainText::blocks($blocks);

        if ($blocks_list === []) {
            return new self(0, 0, 0, 0, 0.0);
        }

        $total_characters = 0;
        $total_words = 0;
        $text_blocks = 0;
        $cleaned_chunks = [];

        foreach ($blocks_list as $block) {
            $segments = EditorPlainText::segments($block);

            if ($segments === []) {
                continue;
            }

            $text_blocks++;

            foreach ($segments as $segment) {
                $stripped = EditorPlainText::stripFindMarks($segment);
                $total_characters += mb_strlen($stripped);
                $total_words += self::countWords($stripped);
                $clean = self::cleanText($stripped);

                if ($clean !== '') {
                    $cleaned_chunks[] = $clean;
                }
            }
        }

        $merged_plain = implode("\n", $cleaned_chunks);
        $sentences = self::countSentencesInPlainText($merged_plain);

        $reading_time_seconds = self::calculateReadingTimeSeconds($blocks_list);

        return new self(
            $total_characters,
            $total_words,
            $sentences,
            $text_blocks,
            $reading_time_seconds,
        );
    }

    /**
     * The readable text of Editor.js blocks as one plain string: the same
     * text-bearing parts the metrics count (text, caption, list items, table
     * cells, raw html), tags removed, entities decoded, whitespace collapsed.
     * Punctuation is kept, unlike {@see cleanText()}.
     *
     * @param  iterable<int, mixed>  $blocks
     */
    public static function plainText(iterable $blocks): string
    {
        return EditorPlainText::fromBlocks($blocks);
    }

    public static function stripFindMarksFromHtmlString(string $html): string
    {
        return EditorPlainText::stripFindMarks($html);
    }

    public static function cleanText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $plain = strip_tags($text);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/&[^;]+;/u', ' ', $plain) ?? '';
        $plain = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $plain) ?? '';
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? '';

        return mb_trim($plain);
    }

    public static function countWords(string $text): int
    {
        $clean = self::cleanText($text);

        if ($clean === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    public static function countSentencesInPlainText(string $plain): int
    {
        $plain = mb_trim($plain);

        if ($plain === '') {
            return 0;
        }

        $parts = preg_split('/[.!?…]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts)) {
            return 1;
        }

        $count = 0;

        foreach ($parts as $part) {
            if (mb_trim($part) !== '') {
                $count++;
            }
        }

        return max(1, $count);
    }

    /**
     * @return array{characters: int, words: int, sentences: int, paragraphs: int, reading_time: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'characters' => $this->characters,
            'words' => $this->words,
            'sentences' => $this->sentences,
            'paragraphs' => $this->paragraphs,
            'reading_time' => $this->reading_time,
        ];
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     */
    private static function calculateReadingTimeSeconds(array $blocks): float
    {
        $total_words = 0;
        $total_complexity = 0.0;
        $block_count = 0;
        $has_images = false;
        $has_videos = false;
        $has_code = false;
        $has_tables = false;

        foreach ($blocks as $block) {
            $raw_type = $block['type'];
            $type = $raw_type === 'link' ? 'linkBlock' : $raw_type;
            $data = $block['data'];
            $complexity = self::BLOCK_COMPLEXITY[$type] ?? 1.0;
            $block_count++;
            $total_complexity += $complexity;

            if ($type === 'image' || $type === 'gallery') {
                $has_images = true;
            }

            if (in_array($raw_type, ['video', 'embed', 'youtube', 'vimeo', 'tiktok', 'instagramreel', 'instagram', 'meride', 'twitchVideo', 'twitchChannel', 'facebook', 'multimedia'], true)) {
                $has_videos = true;
            }

            if ($type === 'code' || $type === 'raw') {
                $has_code = true;
            }

            if ($type === 'table') {
                $has_tables = true;
            }

            $block_words = self::blockWordsForReadingTime($type, $data);
            $caption = $data['caption'] ?? null;

            if (is_string($caption) && $caption !== '') {
                $block_words += self::countWords($caption);
            }

            $total_words += $block_words;
        }

        if ($block_count === 0) {
            return 0.0;
        }

        $reading_speed = 225.0;

        if ($has_code) {
            $reading_speed = 150.0;
        } elseif ($has_tables) {
            $reading_speed = 225.0 * 0.9;
        } elseif ($total_words < 100) {
            $reading_speed = 250.0;
        } elseif ($total_words > 1000) {
            $reading_speed = 180.0;
        }

        $base_time = ($total_words / $reading_speed) * 60.0;
        $avg_complexity = $total_complexity / $block_count;
        $base_time *= $avg_complexity;

        if ($has_images) {
            $base_time *= 1.15;
        }

        if ($has_videos) {
            $base_time *= 1.25;
        }

        if ($has_tables) {
            $base_time *= 1.1;
        }

        return round($base_time, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function blockWordsForReadingTime(string $type, array $data): int
    {
        return match ($type) {
            'image', 'gallery', 'video', 'audio', 'embed', 'delimiter' => 0,
            'raw' => self::countWords(is_string($data['html'] ?? null) ? $data['html'] : ''),
            'table' => self::countWordsInTableContent($data['content'] ?? null),
            default => self::defaultBlockWords($data),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function defaultBlockWords(array $data): int
    {
        if (isset($data['items']) && is_array($data['items'])) {
            $sum = 0;

            foreach ($data['items'] as $item) {
                if (is_string($item)) {
                    $sum += self::countWords($item);
                }

                if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                    $sum += self::countWords($item['text']);
                }
            }

            return $sum;
        }

        if (isset($data['text']) && is_string($data['text'])) {
            return self::countWords($data['text']);
        }

        return 0;
    }

    private static function countWordsInTableContent(mixed $content): int
    {
        if (! is_array($content)) {
            return 0;
        }

        $total = 0;

        foreach ($content as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $cell) {
                $total += is_string($cell) ? self::countWords($cell) : 0;
            }
        }

        return $total;
    }
}
