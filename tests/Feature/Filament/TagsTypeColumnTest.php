<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CMS\Filament\Resources\Tags\Pages\ListTags;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Tests\Support\HttpContext;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();
    HttpContext::panelActorWithoutApproval(new Tag, ['select']);
});

it('renders the type column for a registered type, an unregistered type and no type', function (): void {
    $people = Tag::factory()->create(['type' => 'people']);
    $genre = Tag::factory()->create(['type' => 'genre']);
    $untyped = Tag::factory()->create(['type' => null]);

    Livewire::test(ListTags::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$people, $genre, $untyped])
        ->assertTableColumnExists('type')
        ->assertTableColumnStateSet('type', 'people', $people)
        ->assertTableColumnStateSet('type', 'genre', $genre)
        ->assertTableColumnStateSet('type', null, $untyped);
});

it('shows the registered icon, the generic icon for no type and a letter for an unregistered type', function (): void {
    $people = Tag::factory()->create(['type' => 'people']);
    $genre = Tag::factory()->create(['type' => 'genre']);
    $untyped = Tag::factory()->create(['type' => null]);

    Livewire::test(ListTags::class)
        ->loadTable()
        ->assertTableColumnExists('type', static fn ($column): bool => $column->getIcon('people') === 'heroicon-o-users', $people)
        ->assertTableColumnExists('type', static fn ($column): bool => $column->getIcon('genre') === null && $column->formatState('genre') === 'G genre', $genre)
        ->assertTableColumnExists('type', static fn ($column): bool => $column->getIcon(null) === 'heroicon-o-tag', $untyped);
});
