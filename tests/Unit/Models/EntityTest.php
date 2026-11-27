<?php

declare(strict_types=1);

use Modules\CMS\Models\Entity;

it('entity model has correct structure', function (): void {
    $reflection = new ReflectionClass(Entity::class);
    $source = file_get_contents($reflection->getFileName());

    expect($reflection->hasProperty('fillable'))->toBeTrue()
        ->and($reflection->hasProperty('hidden'))->toBeTrue()
        ->and($source)->toContain('EntityType::class');
});

it('entity model uses correct traits', function (): void {
    $traits = array_values(class_uses_recursive(Entity::class));

    expect($traits)->toContain(Illuminate\Database\Eloquent\Factories\HasFactory::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasActivation::class);
    expect($traits)->toContain(Modules\Core\Cache\HasCache::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasSlug::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasValidations::class);
    expect($traits)->toContain(Modules\Core\Locking\Traits\HasLocks::class);
});

it('entity model has required methods', function (): void {
    $reflection = new ReflectionClass(Entity::class);

    expect($reflection->hasMethod('presets'))->toBeTrue();
    expect($reflection->hasMethod('contents'))->toBeTrue();
    expect($reflection->hasMethod('getRules'))->toBeTrue();
    expect($reflection->hasMethod('toArray'))->toBeTrue();
});

it('entity model has correct relationships', function (): void {
    $reflection = new ReflectionClass(Entity::class);

    // Test presets relationship
    $method = $reflection->getMethod('presets');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\HasMany::class);

    // Test contents relationship
    $method = $reflection->getMethod('contents');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\HasManyThrough::class);
});

it('entity model has correct method signatures', function (): void {
    $reflection = new ReflectionClass(Entity::class);

    // Test getRules method
    $method = $reflection->getMethod('getRules');
    expect($method->getReturnType()->getName())->toBe('array');

    // Test toArray method
    $method = $reflection->getMethod('toArray');
    $returnType = $method->getReturnType();

    if ($returnType !== null) {
        expect($returnType->getName())->toBe('array');
    }
});
