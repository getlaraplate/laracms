<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\TestCase;

/*
 * The category `path` of an indexed content, built while a chunk of contents is prepared for the search engine
 * (`makeSearchableUsing()`, then `toSearchableArray()`). Two leaves share the same chain of ancestors, a third
 * category is a root: the paths must read as the `path` accessor reads them on a category loaded alone, and the
 * shared ancestors must be read once for the chunk, not once per category that has them.
 */

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities([EntityType::Contents, EntityType::Categories]);

    $category = static function (?Category $parent): Category {
        $node = Category::factory()->create();
        $node->parent_id = $parent?->id;
        $node->save();

        return $node->fresh();
    };

    $this->root = $category(null);
    $this->middle = $category($this->root);
    $this->first_leaf = $category($this->middle);
    $this->second_leaf = $category($this->middle);
    $this->lone_root = $category(null);

    $this->contents = Content::factory()->count(2)->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $this->contents[0]->categories()->attach([$this->first_leaf->id, $this->lone_root->id]);
    $this->contents[1]->categories()->attach([$this->second_leaf->id]);
});

/**
 * The indexed documents of the contents, prepared as the indexer prepares a chunk.
 *
 * @return list<array<string, mixed>>
 */
function contentCategoryPathDocuments(Collection $contents): array
{
    return $contents->first()->makeSearchableUsing($contents)
        ->map(static fn (Content $content): array => $content->toSearchableArray())
        ->values()
        ->all();
}

it('indexes each category with the path its accessor gives when the category is loaded alone', function (): void {
    expect($this->middle->parent_id)->toBe($this->root->id)
        ->and($this->first_leaf->parent_id)->toBe($this->middle->id)
        ->and($this->second_leaf->parent_id)->toBe($this->middle->id);

    $expected = static fn (Category $category): string => Category::query()->findOrFail($category->id)->path;

    $documents = contentCategoryPathDocuments(Content::query()->whereKey($this->contents->modelKeys())->orderBy('id')->get());

    expect(collect($documents[0]['categories'])->pluck('path', 'id')->all())->toBe([
        $this->first_leaf->id => $expected($this->first_leaf),
        $this->lone_root->id => $expected($this->lone_root),
    ])
        ->and(collect($documents[1]['categories'])->pluck('path', 'id')->all())->toBe([
            $this->second_leaf->id => $expected($this->second_leaf),
        ])
        ->and($expected($this->first_leaf))->toContain($this->root->slug . '/' . $this->middle->slug . '/' . $this->first_leaf->slug);
});

it('reads the ancestors of the chunk once, not once per category that has them', function (): void {
    $hydrated = [];
    Category::retrieved(static function (Category $category) use (&$hydrated): void {
        $hydrated[] = $category->id;
    });

    contentCategoryPathDocuments(Content::query()->whereKey($this->contents->modelKeys())->orderBy('id')->get());

    // The three categories of the contents, and the two ancestors the two leaves share: each one model.
    expect($hydrated)->toHaveCount(5)
        ->and(array_count_values($hydrated))->each->toBe(1);
});
