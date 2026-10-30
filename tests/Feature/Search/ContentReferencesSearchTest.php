<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentReference;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A content's references (its bibliography) travel in the content's search document as one text field: the
 * labels and the URL hosts, so a search for a cited source finds the contents that cite it.
 */

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents]);

    Artisan::call('permission:refresh', ['--quiet' => true]);
    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return list<int>
 */
function refsearch_found_ids(string $query): array
{
    $response = test()->getJson('/api/v1/search/cms/contents?' . http_build_query(['qs' => $query]));

    $response->assertOk();

    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('finds an anonymous caller the published content that cites a source, by its label and by its domain', function (string $query): void {
    $published = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    ContentReference::factory()->create(['content_id' => $published->id, 'label' => 'Nature', 'url' => 'https://www.nature.com/x']);

    $draft = Content::factory()->create(['valid_from' => now()->addWeek(), 'valid_to' => null]);
    ContentReference::factory()->create(['content_id' => $draft->id, 'label' => 'Nature', 'url' => 'https://www.nature.com/y']);

    expect(refsearch_found_ids($query))->toBe([$published->id]);
})->with(['Nature', 'nature.com']);

it('finds nothing when no content cites the searched source', function (): void {
    $published = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    ContentReference::factory()->create(['content_id' => $published->id, 'label' => 'Nature', 'url' => 'https://www.nature.com/x']);

    expect(refsearch_found_ids('sciencemag.org'))->toBe([]);
});

it('indexes the labels and the hosts of the references, the leading www stripped, and no path', function (): void {
    $content = Content::factory()->create();
    ContentReference::factory()->create(['content_id' => $content->id, 'label' => 'Nature', 'url' => 'https://www.nature.com/articles/x?y=1']);
    ContentReference::factory()->create(['content_id' => $content->id, 'label' => 'Docs', 'url' => 'https://docs.example.org/a']);
    ContentReference::factory()->create(['content_id' => $content->id, 'label' => 'Offline note', 'url' => null]);

    $text = $content->fresh()->toSearchableArray()['references'];

    expect($text)->toContain('Nature')
        ->toContain('nature.com')
        ->toContain('docs.example.org')
        ->toContain('Offline note')
        ->not->toContain('www.')
        ->not->toContain('articles')
        ->not->toContain('https');
});

it('indexes an empty references field for a content with no reference', function (): void {
    expect(Content::factory()->create()->fresh()->toSearchableArray()['references'])->toBe('');
});

it('declares the references field as searchable text in the content mapping of the elasticsearch and typesense engines', function (string $driver): void {
    config()->set('scout.driver', $driver);

    $fields = collect(Content::factory()->create()->getSearchMapping()['fields'] ?? [])->keyBy('name');

    expect($fields->get('references'))->toMatchArray(['type' => 'string']);
})->with(['typesense']);

it('declares the references field as a text property in the elasticsearch content mapping', function (): void {
    config()->set('scout.driver', 'elasticsearch');

    $mapping = Content::factory()->create()->getSearchMapping();

    expect($mapping['mappings']['properties']['references']['type'])->toBe('text');
});

it('reindexes the content when one of its references is added, changed or removed', function (): void {
    $content = Content::factory()->create();

    Event::fake([ModelRequiresIndexing::class]);

    $reference = ContentReference::factory()->create(['content_id' => $content->id, 'label' => 'Nature', 'url' => null]);
    Event::assertDispatched(ModelRequiresIndexing::class, fn (ModelRequiresIndexing $event): bool => $event->model->is($content));

    Event::fake([ModelRequiresIndexing::class]);
    $reference->update(['label' => 'Science']);
    Event::assertDispatched(ModelRequiresIndexing::class, fn (ModelRequiresIndexing $event): bool => $event->model->is($content));

    Event::fake([ModelRequiresIndexing::class]);
    $reference->delete();
    Event::assertDispatched(ModelRequiresIndexing::class, fn (ModelRequiresIndexing $event): bool => $event->model->is($content));
});
