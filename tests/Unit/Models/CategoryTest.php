<?php

declare(strict_types=1);

use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Factories\CategoryFactory;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Models\Taxonomy;

uses(TestCase::class);

it('category model has correct structure', function (): void {
    $categoryReflection = new ReflectionClass(Category::class);
    expect($categoryReflection->getParentClass()?->getName())->toBe(Taxonomy::class);

    $taxonomySource = file_get_contents((new ReflectionClass(Taxonomy::class))->getFileName());
    expect($taxonomySource)->toContain('protected $fillable')
        ->and($taxonomySource)->toContain('protected $hidden');

    $categorySource = file_get_contents($categoryReflection->getFileName());
    expect($categorySource)->toContain('$this->fillable');
});

it('category model uses correct traits', function (): void {
    $traits = array_values(class_uses_recursive(Category::class));

    expect($traits)->toContain(Illuminate\Database\Eloquent\Factories\HasFactory::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasActivation::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasApprovals::class);
    expect($traits)->toContain(Modules\CMS\Helpers\HasMultimedia::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasClosureTable::class);
    expect($traits)->toContain(Modules\CMS\Helpers\HasTags::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasTranslatedDynamicContents::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasValidations::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasValidity::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasVersions::class);
    expect($traits)->toContain(Modules\Core\SoftDeletes\SoftDeletes::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\SortableTrait::class);
    expect($traits)->toContain(Modules\Core\Locking\Traits\HasLocks::class);
});

it('category model has required methods', function (): void {
    $reflection = new ReflectionClass(Category::class);

    expect($reflection->hasMethod('contents'))->toBeTrue();
    expect($reflection->hasMethod('getRules'))->toBeTrue();
    expect($reflection->hasMethod('toArray'))->toBeTrue();
});

it('returns the CMS entity model class', function (): void {
    expect(Category::getEntityModelClass())->toBe(Entity::class);
});

it('adds logo fields to fillable attributes on construction', function (): void {
    $category = new Category;

    expect($category->getFillable())->toContain('logo', 'logo_full');
});

it('returns the CMS presettable pivot class', function (): void {
    expect(Category::getPresettableClass())->toBe(Presettable::class);
});

it('category model has correct relationships', function (): void {
    $reflection = new ReflectionClass(Category::class);

    // Test contents relationship
    $method = $reflection->getMethod('contents');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);

    expect((new Category)->contents())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
});

it('returns the categories entity type', function (): void {
    $method = new ReflectionMethod(Category::class, 'getEntityType');
    $method->setAccessible(true);

    expect($method->invoke(null))->toBe(EntityType::Categories);
});

it('creates the category factory instance', function (): void {
    $method = new ReflectionMethod(Category::class, 'newFactory');
    $method->setAccessible(true);

    expect($method->invoke(null))->toBeInstanceOf(CategoryFactory::class);
});

it('category model has correct method signatures', function (): void {
    $reflection = new ReflectionClass(Category::class);

    // Test getRules method
    $method = $reflection->getMethod('getRules');
    expect($method->getReturnType()->getName())->toBe('array');

    // Test toArray method
    $method = $reflection->getMethod('toArray');
    expect($method->getReturnType()->getName())->toBe('array');
});
