<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\TestCase;

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
        // The document carries no hierarchy path, so indexing loads no ancestors.
        ->not->toContain('categories.ancestors.translations')
        ->toContain('tags')
        ->toContain('locations')
        ->toContain('translations')
        ->toContain('presettable.entity')
        ->toContain('presettable.preset');
});

it('declares filterable indexed relation fields in the content search schema', function (): void {
    setupCMSEntities([EntityType::Contents]);
    config(['scout.driver' => 'elasticsearch']);

    $properties = new Content()->getSearchMapping()['mappings']['properties'];

    foreach (['contributors', 'categories', 'tags', 'locations'] as $relation) {
        expect($properties[$relation]['meta']['relation'] ?? null)->toBe($relation)
            ->and($properties[$relation]['properties']['id']['meta']['filterable'] ?? null)->toBeTrue()
            ->and($properties[$relation]['properties']['slug']['meta']['filterable'] ?? null)->toBeTrue();
    }

    expect($properties['locations']['properties']['country']['meta']['filterable'] ?? null)->toBeTrue();
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
