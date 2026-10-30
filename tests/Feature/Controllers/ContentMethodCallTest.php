<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

// A read of contents never makes a content run a method it did not declare as computable for the CRUD.

beforeEach(function (): void {
    Cache::flush();
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents]);
});

function contentcall_reader(): App\Models\User
{
    $role = Role::factory()->create(['name' => 'contentcall_' . uniqid(), 'guard_name' => 'api']);

    foreach (['select', 'insert', 'update', 'delete', 'forceDelete', 'restore'] as $operation) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => PermissionName::forClass(Content::class, $operation), 'guard_name' => 'api']));
    }

    $user = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * @return list<array<string, mixed>>
 */
function contentcall_snapshot(): array
{
    return DB::table((new Content)->getTable())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
}

it('refuses a model method as a method column on contents and changes no row', function (string $name, string $action): void {
    $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $reader = contentcall_reader();
    $before = contentcall_snapshot();

    $query = ['columns' => [['name' => $name, 'type' => 'method']]] + ($action === 'detail' ? ['id' => $content->id] : []);
    $response = $this->actingAs($reader)->getJson(sprintf('/api/v1/%s/cms/contents?%s', $action, http_build_query($query)));

    expect(contentcall_snapshot())->toBe($before)
        ->and($response->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
})->with(['delete', 'forceDelete', 'truncate', 'update', 'save', 'touch'])->with(['select', 'detail']);

it('refuses a model method as a group_by key on contents and changes no row', function (string $name): void {
    Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $reader = contentcall_reader();
    $before = contentcall_snapshot();

    $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/contents?' . http_build_query(['group_by' => [$name]]));

    expect(contentcall_snapshot())->toBe($before)
        ->and($response->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
})->with(['forceDelete', 'restore', 'delete']);
