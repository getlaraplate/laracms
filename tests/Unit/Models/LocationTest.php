<?php

declare(strict_types=1);

use Modules\CMS\Models\Location;

it('location model has correct structure', function (): void {
    $reflection = new ReflectionClass(Location::class);
    $source = file_get_contents($reflection->getFileName());

    // Test fillable attributes
    expect($source)->toContain('protected $fillable');
});

it('location model uses correct traits', function (): void {
    $traits = array_values(class_uses_recursive(Location::class));

    expect($traits)->toContain(Illuminate\Database\Eloquent\Factories\HasFactory::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasSlug::class);
    expect($traits)->toContain(MatanYadaev\EloquentSpatial\Traits\HasSpatial::class);
    expect($traits)->toContain(Modules\CMS\Helpers\HasTags::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasValidations::class);
    expect($traits)->toContain(Modules\Core\Search\Traits\Searchable::class);
    expect($traits)->toContain(Modules\Core\SoftDeletes\SoftDeletes::class);
});

it('location model has required methods', function (): void {
    $reflection = new ReflectionClass(Location::class);

    expect($reflection->hasMethod('contents'))->toBeTrue();
    expect($reflection->hasMethod('getRules'))->toBeTrue();
    expect($reflection->hasMethod('toArray'))->toBeTrue();
});

it('location model has correct relationships', function (): void {
    $reflection = new ReflectionClass(Location::class);

    // Test contents relationship
    $method = $reflection->getMethod('contents');
    expect($method->getReturnType()->getName())->toBe(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
});

it('location model has correct method signatures', function (): void {
    $reflection = new ReflectionClass(Location::class);

    // Test getRules method
    $method = $reflection->getMethod('getRules');
    expect($method->getReturnType()->getName())->toBe('array');

    // Test toArray method
    $method = $reflection->getMethod('toArray');
    expect($method->getReturnType()->getName())->toBe('array');
});
