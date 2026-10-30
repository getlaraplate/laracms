<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CMS\Filament\Resources\Tags\Pages\CreateTag;
use Modules\CMS\Filament\Resources\Tags\Pages\EditTag;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Tests\Support\HttpContext;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();
});

it('refuses in the form a slug another tag has in the edited locale', function (): void {
    Tag::findOrCreateFromString('Jazz');
    HttpContext::panelActorWithoutApproval(new Tag, ['select', 'insert']);

    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'Jazz', 'slug' => 'jazz'])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Tag::query()->count())->toBe(1);
});

it('saves a tag whose slug is unchanged', function (): void {
    $tag = Tag::findOrCreateFromString('Jazz');
    HttpContext::panelActorWithoutApproval(new Tag, ['select', 'update']);

    Livewire::test(EditTag::class, ['record' => $tag->getKey()])
        ->fillForm(['name' => 'Jazz!', 'slug' => 'jazz'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name)->toBe('Jazz!');
});
