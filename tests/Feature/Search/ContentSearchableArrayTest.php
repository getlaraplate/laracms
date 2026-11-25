<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Preset;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Translators\ElasticsearchTranslator;
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
        ->and($properties['title']['properties']['it'])->toBe(['type' => 'text', 'analyzer' => 'italian', 'fields' => ['exact' => ['type' => 'text', 'analyzer' => 'standard']]])
        ->and($properties['title']['properties']['en'])->toBe(['type' => 'text', 'analyzer' => 'english', 'fields' => ['exact' => ['type' => 'text', 'analyzer' => 'standard']]])
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

it('declares valid_from as the date a search period is applied to', function (): void {
    expect(new Content()->searchDateField())->toBe('valid_from');
});

/**
 * The indexed relations of Content exactly as `getSearchMapping()` declared them before the Core `IndexedRelation`
 * helper (spec 2026-11-17-extended-entity-search-design.md, XS14), without the hierarchy `path` Laraplate no longer
 * computes: the pin the helper must reproduce.
 *
 * @return list<FieldDefinition>
 */
function content_indexed_relation_fields_before_helper(): array
{
    $base = [
        'name' => FieldType::Text,
        'id' => ['type' => FieldType::Integer, 'filterable' => true],
        'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
    ];
    $faceted = [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable];

    return [
        new FieldDefinition('contributors', FieldType::Array, $faceted, ['relation' => 'contributors', 'properties' => $base]),
        new FieldDefinition('categories', FieldType::Array, $faceted, ['relation' => 'categories', 'properties' => $base]),
        new FieldDefinition('tags', FieldType::Array, $faceted, ['relation' => 'tags', 'properties' => ['name' => FieldType::Keyword] + $base]),
        new FieldDefinition('locations', FieldType::Array, [IndexType::Searchable, IndexType::Filterable], [
            'relation' => 'locations',
            'properties' => [
                'name' => FieldType::Text,
                'id' => ['type' => FieldType::Integer, 'filterable' => true],
                'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
                'address' => FieldType::Text,
                'city' => ['type' => FieldType::Keyword, 'filterable' => true],
                'province' => ['type' => FieldType::Keyword, 'filterable' => true],
                'country' => ['type' => FieldType::Keyword, 'filterable' => true],
                'postcode' => ['type' => FieldType::Keyword, 'filterable' => true],
                'zone' => ['type' => FieldType::Keyword, 'filterable' => true],
            ],
        ]),
    ];
}

it('maps the indexed relations of Content as they were declared before the indexed-relation helper', function (): void {
    $content = createBilingualSearchableContent('Titolo di prova', 'Test title');
    config(['scout.driver' => 'elasticsearch']);

    $schema = new SchemaDefinition('contents');

    foreach (content_indexed_relation_fields_before_helper() as $field) {
        $schema->addField($field);
    }

    $expected = (new ElasticsearchTranslator)->translate($schema)['mappings']['properties'];
    $properties = $content->getSearchMapping()['mappings']['properties'];

    foreach (['contributors', 'categories', 'tags', 'locations'] as $relation) {
        expect($properties[$relation])->toBe($expected[$relation]);
    }
});

it('writes the indexed relations of Content with the keys and values they had before the indexed-relation helper', function (): void {
    $content = createBilingualSearchableContent('Titolo di prova', 'Test title');
    setupCMSEntities([EntityType::Contributors, EntityType::Categories]);
    $content->setRelation('contributors', Contributor::factory()->count(2)->create());
    $content->setRelation('categories', Category::factory()->count(2)->create());
    $content->setRelation('tags', Tag::factory()->count(2)->create());
    $content->setRelation('locations', Location::factory()->count(2)->create());

    $document = $content->toSearchableArray();
    $before = static fn (iterable $records, array $keys): array => collect($records)->map(static fn ($record): array => $record->only($keys))->values()->all();
    $base = ['id', 'name', 'slug'];

    // Compared by key and value: the helper lists `name` first, the code before it listed `id` first.
    expect($document['contributors'])->toEqual($before($content->contributors, $base))
        ->and($document['categories'])->toEqual($before($content->categories, $base))
        ->and($document['tags'])->toEqual($before($content->tags, $base))
        ->and($document['locations'])->toEqual($before($content->locations, [...$base, 'address', 'city', 'province', 'country', 'postcode', 'zone']))
        ->and($document['contributors'])->toHaveCount(2)
        ->and($document['locations'])->toHaveCount(2);
});
