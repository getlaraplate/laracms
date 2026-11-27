<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Preset;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities([EntityType::Contents]);
});

it('exposes presets relation from core entity', function (): void {
    $entity = Entity::query()->create([
        'type' => EntityType::Contents,
        'name' => 'entity-behavior-' . uniqid(),
        'slug' => 'entity-behavior-' . uniqid(),
    ]);

    expect($entity->presets())->toBeInstanceOf(HasMany::class);
    expect($entity->presets()->getRelated())->toBeInstanceOf(Preset::class);
});

it('creates entities through the module factory binding', function (): void {
    $factory_method = new ReflectionMethod(Entity::class, 'newFactory');
    $factory = $factory_method->invoke(null);

    expect($factory)->toBeInstanceOf(Modules\Core\Database\Factories\EntityFactory::class);
    expect($factory->model)->toBe(Entity::class);
});
