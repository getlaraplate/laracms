<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Filament\Resources\Tags\Schemas\TagTypeOptions;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();
    Tag::factory()->create(['type' => 'genre']);
});

it('offers every registered type in registry order, then the other types in use', function (): void {
    expect(array_keys(TagTypeOptions::all()))->toBe(['search', 'people', 'locations', 'organizations', 'topics', 'events', 'genre']);
});

it('offers a registered type in use once', function (): void {
    Tag::factory()->create(['type' => 'people']);

    expect(array_keys(TagTypeOptions::all()))->toBe(['search', 'people', 'locations', 'organizations', 'topics', 'events', 'genre']);
});

it('offers, while typed, the types that contain it and then what is typed', function (string $search, array $keys): void {
    expect(array_keys(TagTypeOptions::search($search)))->toBe($keys);
})->with([
    'part of a type' => ['pe', ['people', 'pe']],
    'a registered type, spelled otherwise' => ['People', ['people']],
    'a new type' => ['Era', ['era']],
]);

it('offers a typed type as its slug', function (): void {
    expect(array_key_last(TagTypeOptions::search('Musical Instrument')))->toBe('musical-instrument');
});

it('offers every type for an empty search', function (): void {
    expect(TagTypeOptions::search(' '))->toBe(TagTypeOptions::all());
});
