<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CMS\Filament\Resources\Tags\Pages\CreateTag;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Tests\Support\HttpContext;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    setupCMSEntities();
});

it('suggests the tag types already in use while the type is typed', function (): void {
    Tag::factory()->create(['type' => 'genre']);
    Tag::factory()->create(['type' => 'instrument']);
    HttpContext::panelActorWithoutApproval(new Tag, ['select', 'insert']);

    Livewire::test(CreateTag::class)
        ->assertSeeHtml('<option value="genre">')
        ->assertSeeHtml('<option value="instrument">');
});
