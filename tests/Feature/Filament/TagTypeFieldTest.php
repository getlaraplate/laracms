<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CMS\Filament\Resources\Tags\Pages\CreateTag;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Tests\Support\HttpContext;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();
    Tag::factory()->create(['type' => 'genre']);
    Tag::factory()->create(['type' => 'musical-instrument']);
    HttpContext::panelActorWithoutApproval(new Tag, ['select', 'insert']);
});

it('offers the tag types already in use', function (): void {
    Livewire::test(CreateTag::class)
        ->assertFormFieldExists('type', static fn (Select $field): bool => $field->getOptions() === [
            'genre' => 'genre',
            'musical-instrument' => 'musical-instrument',
        ]);
});

it('offers, while the type is typed, the types in use that contain it and what is typed as a slug', function (string $search, array $results): void {
    Livewire::test(CreateTag::class)
        ->assertFormFieldExists('type', static fn (Select $field): bool => $results === $field->getSearchResults($search));
})->with([
    'part of a type in use' => ['Instr', ['musical-instrument' => 'musical-instrument', 'instr' => 'instr']],
    'a type in use, spelled otherwise' => ['Musical Instrument', ['musical-instrument' => 'musical-instrument']],
    'a new type' => ['Era', ['era' => 'era']],
]);

it('saves a new type picked from the search as its slug', function (): void {
    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Baroque',
            'slug' => 'baroque',
            'type' => 'era',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tag::getWithType('era')->sole()->name)->toBe('Baroque');
});
