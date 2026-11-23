<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Overrides\LocaleScope;

uses(TestCase::class, RefreshDatabase::class);

it('content model has correct structure', function (): void {
    $reflection = new ReflectionClass(Content::class);
    $source = file_get_contents($reflection->getFileName());

    // Test hidden attributes
    expect($source)->toContain('protected $hidden');
});

it('content model uses correct traits', function (): void {
    $traits = array_values(class_uses_recursive(Content::class));

    expect($traits)->toContain(Illuminate\Database\Eloquent\Factories\HasFactory::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasTranslatedDynamicContents::class);
    expect($traits)->toContain(Modules\CMS\Helpers\HasMultimedia::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasPath::class);
    expect($traits)->toContain(Modules\CMS\Helpers\HasTags::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasApprovals::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasValidations::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasValidity::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasVersions::class);
    expect($traits)->toContain(Modules\Core\SoftDeletes\SoftDeletes::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\SortableTrait::class);
    expect($traits)->toContain(Modules\Core\Locking\Traits\HasOptimisticLocking::class);
    expect($traits)->toContain(Modules\Core\Locking\Traits\HasLocks::class);
    expect($traits)->toContain(Modules\Core\Search\Traits\Searchable::class);
});

it('content model has required methods', function (): void {
    $reflection = new ReflectionClass(Content::class);

    expect($reflection->hasMethod('contributors'))->toBeTrue();
    expect($reflection->hasMethod('categories'))->toBeTrue();
    expect($reflection->hasMethod('tags'))->toBeTrue();
    expect($reflection->hasMethod('locations'))->toBeTrue();
    expect($reflection->hasMethod('related'))->toBeTrue();
    expect($reflection->hasMethod('toSearchableArray'))->toBeTrue();
    expect($reflection->hasMethod('getSearchMapping'))->toBeTrue();
    expect($reflection->hasMethod('getRules'))->toBeTrue();
    expect($reflection->hasMethod('getPathPrefix'))->toBeTrue();
    expect($reflection->hasMethod('getPath'))->toBeTrue();
    expect($reflection->hasMethod('toArray'))->toBeTrue();
});

it('content model has correct relationships', function (): void {
    $reflection = new ReflectionClass(Content::class);

    // Test contributors relationship
    $method = $reflection->getMethod('contributors');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);

    // Test categories relationship
    $method = $reflection->getMethod('categories');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);

    // Test locations relationship
    $method = $reflection->getMethod('locations');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);

    // Test related relationship
    $method = $reflection->getMethod('related');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
});

it('content model has correct method signatures', function (): void {
    $reflection = new ReflectionClass(Content::class);

    // Test toSearchableArray method
    $method = $reflection->getMethod('toSearchableArray');
    expect($method->getReturnType()->getName())->toBe('array');

    // Test getSearchMapping method
    $method = $reflection->getMethod('getSearchMapping');
    expect($method->getReturnType()->getName())->toBe('array');

    // Test getRules method
    $method = $reflection->getMethod('getRules');
    expect($method->getReturnType()->getName())->toBe('array');

    // Test getPathPrefix method
    $method = $reflection->getMethod('getPathPrefix');
    expect($method->getReturnType()->getName())->toBe('string');

    // Test getPath method
    $method = $reflection->getMethod('getPath');
    expect($method->getReturnType()->getName())->toBe('string');

    // Test toArray method
    $method = $reflection->getMethod('toArray');
    expect($method->getReturnType()->getName())->toBe('array');
});

it('content model implements correct interfaces', function (): void {
    $reflection = new ReflectionClass(Content::class);

    expect($reflection->implementsInterface(Spatie\MediaLibrary\HasMedia::class))->toBeTrue();
    expect($reflection->implementsInterface(Spatie\EloquentSortable\Sortable::class))->toBeTrue();
});

it('content model exposes toSearchableWith method', function (): void {
    $reflection = new ReflectionClass(Content::class);

    expect($reflection->hasMethod('toSearchableWith'))->toBeTrue();
});

it('toSearchableWith returns all required relations for indexing', function (): void {
    $content = new Content();
    $relations = $content->toSearchableWith();

    expect($relations)->toContain('contributors')
        ->toContain('categories')
        ->toContain('categories.translations')
        // The hierarchical `path` of the categories is given in one batch by makeSearchableUsing(),
        // not by an eager `ancestors` load that hydrates one ancestor per category and ancestor.
        ->not->toContain('categories.ancestors.translations')
        ->toContain('tags')
        ->toContain('locations')
        ->toContain('translations')
        ->toContain('presettable.entity')
        ->toContain('presettable.preset');
});

it('serializes a content without a recursive ancestor query per category', function (): void {
    setupCMSEntities([EntityType::Contents, EntityType::Categories]);

    $parent = Category::factory()->create();
    $parent->parent_id = null;
    $parent->save();

    $child = Category::factory()->create();
    $child->parent_id = $parent->id;
    $child->save();

    $content = Content::factory()->create();
    $content->categories()->attach($child->id);

    // Fetch and eager-load exactly as the bulk indexing path does.
    $models = Content::query()->withoutGlobalScope(LocaleScope::class)->whereKey($content->id)->get();
    $models->first()->makeSearchableUsing($models);

    DB::enableQueryLog();
    $models->each(fn (Content $model) => $model->toSearchableArray());
    $recursive = collect(DB::getQueryLog())
        ->filter(static fn (array $q): bool => str_contains($q['query'], 'recursive'))
        ->count();
    DB::disableQueryLog();

    // The hierarchical `path` reads the eager-loaded ancestor chain, so no
    // recursive-ancestor CTE fires while building the document.
    expect($recursive)->toBe(0);
});

it('builds the content URL from the category chain in depth order', function (int $root_position, int $child_position, int $grandchild_position): void {
    setupCMSEntities([EntityType::Contents, EntityType::Categories]);

    $locale = config('app.locale');
    $chain = [];

    foreach (['root' => $root_position, 'child' => $child_position, 'grandchild' => $grandchild_position] as $name => $position) {
        $category = Category::factory()->create();
        $category->setTranslation($locale, ['name' => 'URL ' . $name, 'slug' => 'url-' . $name]);
        $category->parent_id = $chain === [] ? null : end($chain)->id;
        $category->order_column = $position;
        $category->save();
        $chain[] = $category;
    }

    $content = Content::factory()->create();
    $content->categories()->attach(end($chain)->id);

    $loaded = Content::query()
        ->withoutGlobalScope(LocaleScope::class)
        ->with(['categories.translations', 'categories.ancestors.translations'])
        ->findOrFail($content->id);

    expect($loaded->categories->first()->getPath())->toBe('url-root/url-child/url-grandchild')
        ->and($loaded->path)->toContain('/url-root/url-child/url-grandchild/');
})->with([
    // Sibling positions that do not follow depth. On the first, the scope order sorts the ancestors root first.
    'root 1, child 3, grandchild 2' => [1, 3, 2],
    'root 3, child 1, grandchild 2' => [3, 1, 2],
]);

it('declares filterable indexed relation fields in the content search schema', function (): void {
    $reflection = new ReflectionClass(Content::class);
    $source = file_get_contents((string) $reflection->getFileName());

    expect($source)->toContain("'relation' => 'contributors'")
        ->and($source)->toContain("'relation' => 'categories'")
        ->and($source)->toContain("'relation' => 'tags'")
        ->and($source)->toContain("'relation' => 'locations'")
        ->and($source)->toContain("'id' => ['type' => FieldType::Integer, 'filterable' => true]")
        ->and($source)->toContain("'slug' => ['type' => FieldType::Keyword, 'filterable' => true]")
        ->and($source)->toContain("'country' => ['type' => FieldType::Keyword, 'filterable' => true]");
});

/**
 * Property 6: Content toSearchableArray does not trigger lazy loading.
 *
 * For any Content record with all required relations eager-loaded, calling toSearchableArray()
 * with Model::preventLazyLoading() enabled SHALL NOT throw a LazyLoadingViolationException.
 *
 * Validates: Requirements 5.1, 5.3
 */
it('toSearchableArray does not trigger lazy loading when all relations are eager-loaded', function (): void {
    // Feature: performance-optimization, Property 6: Content toSearchableArray does not trigger lazy loading
    setupCMSEntities([
        EntityType::Contents,
        EntityType::Contributors,
        EntityType::Categories,
    ]);

    // Enable lazy loading guard for this test
    Illuminate\Database\Eloquent\Model::preventLazyLoading(true);

    try {
        // Create a content record with all required relations
        $content = Content::factory()->create();

        // Attach at least one of each relation
        $category = Category::factory()->create();
        $contributor = Modules\CMS\Models\Contributor::factory()->create();
        $tag = Modules\CMS\Models\Tag::factory()->create();
        $location = Modules\CMS\Models\Location::factory()->create();

        $content->categories()->attach($category);
        $content->contributors()->attach($contributor);
        $content->tags()->attach($tag);
        $content->locations()->attach($location);

        // Reload the model with all relations eager-loaded (as toSearchableWith() specifies)
        $loaded = Content::query()
            ->withoutGlobalScopes()
            ->with($content->toSearchableWith())
            ->findOrFail($content->id);

        // toSearchableArray() must not trigger any lazy loading
        expect(fn () => $loaded->toSearchableArray())->not->toThrow(
            Illuminate\Database\LazyLoadingViolationException::class,
        );
    } finally {
        // Always restore the original state to avoid affecting other tests
        Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
    }
})->repeat(3);
