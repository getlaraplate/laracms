<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Import\Enums\ImportSourceFormat;
use Modules\Core\Import\Support\ImportRunner;
use Modules\Core\Models\ImportSession;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['scout.driver' => 'null', 'app.locale' => 'en', 'cms.import.locale' => 'en']);
    Storage::fake('local');
});

/**
 * @param  array<string, string>  $mapping
 */
function cmsImportSession(string $entityKey, string $csv, array $mapping): ImportSession
{
    Storage::disk('local')->put('cms.csv', $csv);

    return ImportSession::factory()->create([
        'entity_key' => $entityKey,
        'source_format' => ImportSourceFormat::Csv,
        'file_disk' => 'local',
        'file_path' => 'cms.csv',
        'original_filename' => 'cms.csv',
        'mapping' => $mapping,
    ]);
}

test('the tag importer creates tags and dedupes by translated name', function (): void {
    app(ImportRunner::class)->process(
        cmsImportSession('cms.tag', "name,type\nSport,topic\nMusic,topic\n", ['name' => 'name', 'type' => 'type']),
    );

    expect(Tag::query()->count())->toBe(2)
        ->and(Tag::findFromString('Sport', 'topic'))->not->toBeNull();

    $second = cmsImportSession('cms.tag', "name,type\nSport,topic\n", ['name' => 'name', 'type' => 'type']);
    app(ImportRunner::class)->process($second);

    expect($second->fresh()->updated_rows)->toBe(1)
        ->and(Tag::query()->count())->toBe(2);
});

test('the contributor importer creates contributors and dedupes by name', function (): void {
    setupCMSEntities([EntityType::Contributors]);

    app(ImportRunner::class)->process(
        cmsImportSession('cms.contributor', "name\nAda Lovelace\nAlan Turing\n", ['name' => 'name']),
    );

    expect(Contributor::query()->withoutGlobalScopes()->count())->toBe(2);

    $second = cmsImportSession('cms.contributor', "name\nAda Lovelace\n", ['name' => 'name']);
    app(ImportRunner::class)->process($second);

    expect($second->fresh()->updated_rows)->toBe(1)
        ->and(Contributor::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('the category importer builds a hierarchy via the parent column', function (): void {
    setupCMSEntities([EntityType::Categories]);

    $session = cmsImportSession('cms.category',
        "name,parent\nNews,\nPolitics,news\nGhost Child,missing-parent\n",
        ['name' => 'name', 'parent' => 'parent'],
    );

    app(ImportRunner::class)->process($session);
    $session->refresh();

    expect($session->created_rows)->toBe(2)   // News + Politics
        ->and($session->failed_rows)->toBe(1) // Ghost Child → unknown parent
        ->and($session->rowErrors()->first()->errors)->toHaveKey('parent');

    $news = Category::query()->withoutGlobalScopes()->whereHas('translations', fn ($q) => $q->where('slug', 'news'))->sole();
    $politics = Category::query()->withoutGlobalScopes()->whereHas('translations', fn ($q) => $q->where('slug', 'politics'))->sole();

    expect($politics->parent_id)->toBe($news->id)
        ->and($news->parent_id)->toBeNull();
});

test('the category importer keeps the position of a category it imports again', function (): void {
    setupCMSEntities([EntityType::Categories]);

    app(ImportRunner::class)->process(cmsImportSession('cms.category', "name\nNews\n", ['name' => 'name']));
    $news = Category::query()->withoutGlobalScopes()->whereHas('translations', fn ($q) => $q->where('slug', 'news'))->sole();
    Category::query()->withoutGlobalScopes()->whereKey($news->id)->update(['order_column' => 9]);

    $again = cmsImportSession('cms.category', "name\nNews\n", ['name' => 'name']);
    app(ImportRunner::class)->process($again);

    expect($again->fresh()->updated_rows)->toBe(1)
        ->and($news->fresh()->order_column)->toBe(9);
});

test('the tag importer reuses by slug a tag of no type and gives it the row type', function (): void {
    $sport = Tag::findOrCreateFromString('Sport');

    $session = cmsImportSession('cms.tag', "name,type\nsport,topic\n", ['name' => 'name', 'type' => 'type']);
    app(ImportRunner::class)->process($session);

    expect($session->fresh()->updated_rows)->toBe(1)
        ->and(Tag::query()->count())->toBe(1)
        ->and($sport->fresh()->type)->toBe('topic');
});

test('the tag importer reuses by slug a tag of another type and keeps its type', function (): void {
    $sport = Tag::findOrCreateFromString('Sport', 'section');

    $session = cmsImportSession('cms.tag', "name,type\nSport,topic\n", ['name' => 'name', 'type' => 'type']);
    app(ImportRunner::class)->process($session);

    expect($session->fresh()->updated_rows)->toBe(1)
        ->and(Tag::query()->count())->toBe(1)
        ->and($sport->fresh()->type)->toBe('section');
});

test('the tag importer does not rename a tag another source brought', function (): void {
    $tag_id = resolve(Modules\CMS\Import\Upserters\TagUpserter::class)->upsert(new Modules\CMS\Import\Dto\ImportTagDto(
        externalId: 117,
        name: 'Libri',
        slug: 'libri',
        type: null,
        orderColumn: null,
        createdAt: null,
        updatedAt: null,
        deletedAt: null,
        sourceType: 'naxos_api@a',
    ));

    app(ImportRunner::class)->process(cmsImportSession('cms.tag', "name\nlibri\n", ['name' => 'name']));

    expect(Tag::query()->count())->toBe(1)
        ->and(Tag::query()->findOrFail($tag_id)->getTranslation('en')?->name)->toBe('Libri');
});

test('the tag importer stores the slug a row gives as a slug and finds it again', function (): void {
    app(ImportRunner::class)->process(cmsImportSession('cms.tag', "name,slug\nJazz,Jazz Music\n", ['name' => 'name', 'slug' => 'slug']));
    $again = cmsImportSession('cms.tag', "name,slug\nJazz,Jazz Music\n", ['name' => 'name', 'slug' => 'slug']);
    app(ImportRunner::class)->process($again);

    expect($again->fresh()->updated_rows)->toBe(1)
        ->and(Tag::query()->count())->toBe(1)
        ->and(Modules\CMS\Models\Translations\TagTranslation::query()->value('slug'))->toBe('jazz-music');
});
