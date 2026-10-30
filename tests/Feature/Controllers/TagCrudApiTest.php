<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['scout.driver' => 'null']);
    $this->locale = Modules\Core\Helpers\LocaleContext::getDefault();

    $user = User::factory()->create();

    foreach (['select', 'insert'] as $action) {
        $permission = PermissionName::forModel(new Tag, $action);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $this->actingAs($user);
});

it('creates a tag through the CRUD API, whose model rules carry a rule object', function (): void {
    $this->postJson(route('core.crud.insert', ['module' => 'cms', 'entity' => 'tags']), [
        'translations' => [['locale' => $this->locale, 'name' => 'Jazz', 'slug' => 'jazz']],
    ])->assertSuccessful();

    expect(Tag::query()->withoutGlobalScopes()->count())->toBe(1);
});
