<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Preset;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Build a Content with exactly two translations (it + en), bypassing the factory's
 * randomized extra-locale duplication so the resulting `locales` set is deterministic.
 */
function createBilingualSearchableContent(string $itTitle, string $enTitle): Content
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
        'valid_from' => now(),
    ]);

    ContentTranslation::query()->create([
        'content_id' => $content->id,
        'locale' => 'it',
        'title' => $itTitle,
        'slug' => Str::slug($itTitle),
        'components' => [],
    ]);

    ContentTranslation::query()->create([
        'content_id' => $content->id,
        'locale' => 'en',
        'title' => $enTitle,
        'slug' => Str::slug($enTitle),
        'components' => [],
    ]);

    return $content->fresh(['translations']);
}

it('indexes Content title/slug as locale-keyed objects with a locales list, not flat suffix keys', function (): void {
    $itTitle = 'Titolo di prova';
    $enTitle = 'Test title';

    $content = createBilingualSearchableContent($itTitle, $enTitle);

    $document = $content->toSearchableArray();

    expect($document['title'])->toMatchArray(['it' => $itTitle, 'en' => $enTitle])
        ->and($document['slug'])->toMatchArray(['it' => Str::slug($itTitle), 'en' => Str::slug($enTitle)])
        ->and($document)->not->toHaveKey('title_it')
        ->and($document)->not->toHaveKey('title_en')
        ->and($document)->not->toHaveKey('slug_it')
        ->and($document)->not->toHaveKey('slug_en')
        ->and($document['locales'])->toEqualCanonicalizing(['it', 'en']);
});

it('indexes an Editor.js component as its plain text, whatever blocks it holds', function (): void {
    $content = createBilingualSearchableContent('Titolo di prova', 'Test title');
    $content->translations()->where('locale', 'it')->firstOrFail()->forceFill(['components' => [
        'content' => ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Primo <i>paragrafo</i>']],
            ['type' => 'quote', 'data' => ['text' => 'Una citazione', 'caption' => 'Autore', 'source' => ['url' => null, 'name' => null]]],
        ]],
        'subtitle' => 'Sottotitolo',
    ]])->save();

    $document = $content->fresh(['translations'])->toSearchableArray();

    expect($document['content']['it'])->toBe('Primo paragrafo Una citazione Autore')
        ->and($document['subtitle']['it'])->toBe('Sottotitolo');
});

it('declares the Content search mapping with locale-keyed title/slug objects, a locales field, and the nested embeddings vector', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 1024, 'dot_product'));
    $content = createBilingualSearchableContent('Titolo di prova', 'Test title');

    // Force the Elasticsearch translator so the mapping shape can be asserted without
    // depending on which Scout driver the environment happens to have configured;
    // building the mapping array is pure translation and needs no reachable cluster.
    config(['scout.driver' => 'elasticsearch']);

    $mapping = $content->getSearchMapping();
    $properties = $mapping['mappings']['properties'];

    expect($properties['title']['type'])->toBe('object')
        ->and($properties['title']['properties']['it'])->toBe(['type' => 'text', 'analyzer' => 'italian'])
        ->and($properties['title']['properties']['en'])->toBe(['type' => 'text', 'analyzer' => 'english'])
        ->and($properties['title']['properties']['de'])->toBe(['type' => 'text', 'analyzer' => 'standard'])
        ->and($properties['slug']['type'])->toBe('object')
        ->and($properties['locales']['type'])->toBe('keyword')
        ->and($properties['embeddings']['type'])->toBe('nested')
        ->and($properties['embeddings']['properties']['vector'])->toMatchArray(['type' => 'dense_vector', 'dims' => 1024, 'similarity' => 'dot_product'])
        ->and($properties)->not->toHaveKey('title_it')
        ->and($properties)->not->toHaveKey('title_en')
        ->and($properties)->not->toHaveKey('slug_it')
        ->and($properties)->not->toHaveKey('slug_en')
        ->and($properties)->not->toHaveKey('embedding');
});

it('declares the Content embeddings field without a nested vector when there is no vector profile', function (): void {
    FakeVectorProfile::none();
    $content = createBilingualSearchableContent('Titolo di prova', 'Test title');
    config(['scout.driver' => 'elasticsearch']);

    $properties = $content->getSearchMapping()['mappings']['properties'];

    expect($properties['embeddings'])->not->toHaveKey('properties');
});
