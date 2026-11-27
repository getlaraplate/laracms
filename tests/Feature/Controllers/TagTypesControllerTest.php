<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('lists the registered tag types translated in the request locale', function (): void {
    /** @var TestCase $this */
    app()->setLocale('it');
    $this->actingAs(User::factory()->create());

    $response = $this->getJson(route('cms.tag-types.index'));

    $response->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0', [
            'type' => 'search',
            'label' => 'Ricerca',
            'heroicon' => 'heroicon-o-magnifying-glass',
            'lucide' => 'Search',
        ]);
});

it('refuses a guest', function (): void {
    /** @var TestCase $this */
    $this->getJson(route('cms.tag-types.index'))->assertUnauthorized();
});
