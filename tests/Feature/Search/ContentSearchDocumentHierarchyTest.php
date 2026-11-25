<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;

/*
 * Laraplate stays headless (decision 2026-11-24): the search document of a content carries no hierarchy path, on
 * the content or on its categories, tags, contributors and locations, and preparing it reads no category ancestors.
 */

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();

    $category = static function (?Category $parent): Category {
        $node = Category::factory()->create();
        $node->parent_id = $parent?->id;
        $node->save();

        return $node;
    };

    $this->root = $category(null);
    $this->middle = $category($this->root);
    $this->leaves = collect(range(1, 5))->map(fn (): Category => $category($this->middle));
});

/**
 * The indexed documents of the contents, prepared as the indexer prepares a chunk: the import query,
 * `makeSearchableUsing()`, then each document.
 *
 * @return list<array<string, mixed>>
 */
function contentSearchDocumentHierarchyDocuments(): array
{
    $indexer = new Content;

    /** @var Collection<int, Content> $chunk */
    $chunk = $indexer->makeAllSearchableUsing(Content::query())->orderBy('id')->get();

    return $indexer->makeSearchableUsing($chunk)
        ->map(static fn (Content $content): array => $content->toSearchableArray())
        ->values()
        ->all();
}

it('puts no path on the content document nor on its categories, tags, contributors and locations', function (): void {
    $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $content->categories()->attach($this->leaves->first()->id);
    $content->tags()->attach(Tag::factory()->create());
    $content->contributors()->attach(Contributor::factory()->create());
    $content->locations()->attach(Location::factory()->create());

    [$document] = contentSearchDocumentHierarchyDocuments();

    expect($document)->not->toHaveKey('path');

    foreach (['categories', 'tags', 'contributors', 'locations'] as $relation) {
        expect($document[$relation])->toHaveCount(1)
            ->and($document[$relation][0])->toHaveKeys(['id', 'name', 'slug'])
            ->and($document[$relation][0])->not->toHaveKey('path');
    }
});

it('maps no path in the content search schema', function (): void {
    // The Elasticsearch translation is pure and needs no reachable cluster; the test engine maps nothing.
    config(['scout.driver' => 'elasticsearch']);

    $properties = (new Content)->getSearchMapping()['mappings']['properties'];

    foreach (['categories', 'tags', 'contributors', 'locations'] as $relation) {
        expect($properties[$relation]['properties'])->toHaveKey('slug')
            ->and($properties[$relation]['properties'])->not->toHaveKey('path');
    }
});

it('indexes 50 contents with categories without reading the closure table or any ancestor', function (): void {
    $contents = Content::factory()->count(50)->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $contents->values()->each(fn (Content $content, int $i): mixed => $content->categories()->attach($this->leaves[$i % 5]->id));

    $hydrated = [];
    Category::retrieved(static function (Category $category) use (&$hydrated): void {
        $hydrated[] = $category->id;
    });
    DB::enableQueryLog();

    $documents = contentSearchDocumentHierarchyDocuments();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($documents)->toHaveCount(50)
        ->and($queries->filter(static fn (string $sql): bool => str_contains($sql, 'core_taxonomies_closure')))->toBeEmpty()
        ->and(array_values(array_unique($hydrated)))->toEqualCanonicalizing($this->leaves->pluck('id')->all())
        ->and($hydrated)->not->toContain($this->root->id)
        ->and($hydrated)->not->toContain($this->middle->id);
});
