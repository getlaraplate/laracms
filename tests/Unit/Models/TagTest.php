<?php

declare(strict_types=1);

use Modules\CMS\Models\Tag;

it('tag model has correct structure', function (): void {
    $reflection = new ReflectionClass(Tag::class);
    $source = file_get_contents($reflection->getFileName());

    // Test fillable attributes
    expect($source)->toContain('protected $fillable');

    // Test hidden attributes
    expect($source)->toContain('protected $hidden');
});

it('tag model uses correct traits', function (): void {
    $traits = array_values(class_uses_recursive(Tag::class));

    expect($traits)->toContain(Illuminate\Database\Eloquent\Factories\HasFactory::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasTranslations::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\HasValidations::class);
    expect($traits)->toContain(Modules\Core\SoftDeletes\SoftDeletes::class);
    expect($traits)->toContain(Modules\Core\Models\Concerns\SortableTrait::class);
});

it('tag model has required methods', function (): void {
    $reflection = new ReflectionClass(Tag::class);

    expect($reflection->hasMethod('getRules'))->toBeTrue();
    expect($reflection->hasMethod('toArray'))->toBeTrue();
});

it('tag model has correct method signatures', function (): void {
    $reflection = new ReflectionClass(Tag::class);

    // Test getRules method
    $method = $reflection->getMethod('getRules');
    expect($method->getReturnType()->getName())->toBe('array');

    // Test toArray method
    $method = $reflection->getMethod('toArray');
    expect($method->getReturnType()->getName())->toBe('array');
});
