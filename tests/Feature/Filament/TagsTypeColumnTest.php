<?php

declare(strict_types=1);

use Filament\Tables\Columns\TextColumn;
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

/**
 * The path data of a heroicon: what identifies the icon in a rendered cell, whatever attributes the cell adds.
 */
function tag_type_icon_path(string $icon): string
{
    preg_match('/ d="([^"]+)"/', svg($icon)->contents(), $matches);

    return $matches[1];
}

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
        ->assertTableColumnStateSet('type', __('cms.fields.no_type'), $untyped);
});

it('renders the registered icon, the generic icon for no type and a letter for an unregistered type', function (): void {
    $people = Tag::factory()->create(['type' => 'people']);
    $genre = Tag::factory()->create(['type' => 'genre']);
    $untyped = Tag::factory()->create(['type' => null]);

    $people_icon = tag_type_icon_path('heroicon-o-users');
    $tag_icon = tag_type_icon_path('heroicon-o-tag');

    Livewire::test(ListTags::class)
        ->loadTable()
        ->assertTableColumnExists('type', static fn (TextColumn $column): bool => str_contains($column->toEmbeddedHtml(), $people_icon), $people)
        ->assertTableColumnExists('type', static fn (TextColumn $column): bool => ! str_contains($column->toEmbeddedHtml(), '<svg') && str_contains($column->toEmbeddedHtml(), 'G genre'), $genre)
        ->assertTableColumnExists('type', static fn (TextColumn $column): bool => str_contains($column->toEmbeddedHtml(), $tag_icon) && str_contains($column->toEmbeddedHtml(), e(__('cms.fields.no_type'))), $untyped);
});
