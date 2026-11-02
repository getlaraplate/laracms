<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Preset;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A content valid from 2026-06-01 with an Italian and an English translation, reloaded with its translations
 * the way the embedding paths load it.
 */
function createHeaderedContent(string $itTitle, string $enTitle): Content
{
    setupCMSEntities([EntityType::Contents]);

    $entity = Entity::query()->where('name', 'contents')->firstOrFail();
    $preset = Preset::query()->where('entity_id', $entity->id)->where('name', 'default')->firstOrFail();
    $presettable = Presettable::query()
        ->where('entity_id', $entity->id)
        ->where('preset_id', $preset->id)
        ->whereNull('deleted_at')
        ->latest('version')
        ->firstOrFail();

    $content = Content::query()->create([
        'entity_id' => $entity->id,
        'presettable_id' => $presettable->id,
        'valid_from' => '2026-06-01 10:00:00',
    ]);

    foreach (['it' => $itTitle, 'en' => $enTitle] as $locale => $title) {
        ContentTranslation::query()->create([
            'content_id' => $content->id,
            'locale' => $locale,
            'title' => $title,
            'slug' => Str::slug(Str::limit($title, 60, '')),
            'components' => [],
        ]);
    }

    return $content->fresh(['translations']);
}

it('declares a header with its type, the title of the locale and its date', function (): void {
    $content = createHeaderedContent('Festa d\'estate', 'Summer festival');

    expect($content->embedHeader('it'))->toBe('contents | Festa d\'estate | 2026-06-01')
        ->and($content->embedHeader('en'))->toBe('contents | Summer festival | 2026-06-01');
});

it('builds the header from what is already loaded, without queries', function (): void {
    $content = createHeaderedContent('Festa d\'estate', 'Summer festival');

    DB::enableQueryLog();
    $content->embedHeader('it');
    $content->embedHeader('en');

    expect(DB::getQueryLog())->toBe([]);
});

it('keeps the header on one line of at most 300 characters', function (): void {
    $content = createHeaderedContent("Festa\nd'estate " . str_repeat('lunga ', 80), 'Summer festival');

    $header = $content->embedHeader('it');

    expect($header)->not->toContain("\n")
        ->and(mb_strlen((string) $header))->toBeLessThanOrEqual(300)
        ->and($header)->toStartWith('contents | Festa d\'estate lunga');
});

it('leaves the header out of the text it embeds', function (): void {
    $content = createHeaderedContent('Festa d\'estate', 'Summer festival');

    expect($content->prepareDataToEmbedByLocale())->toEqual(['it' => 'Festa d\'estate', 'en' => 'Summer festival']);
});
