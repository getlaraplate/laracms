<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Category;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The anonymous reader of `/api` reads the categories by default, limited by the ACL the CMS seeder creates on the
 * `guest` role of the `api` guard: active categories inside their validity window.
 */

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Categories]);

    Artisan::call('permission:refresh', ['--quiet' => true]);
    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function guestcat_category(array $attributes): Category
{
    return Category::factory()->create(['parent_id' => null, ...$attributes]);
}

it('shows an anonymous caller of the api only the active categories inside their validity window', function (): void {
    $live = guestcat_category(['is_active' => true, 'valid_from' => now()->subDay(), 'valid_to' => null]);
    $inactive = guestcat_category(['is_active' => false, 'valid_from' => now()->subDay(), 'valid_to' => null]);
    $future = guestcat_category(['is_active' => true, 'valid_from' => now()->addWeek(), 'valid_to' => null]);
    $expired = guestcat_category(['is_active' => true, 'valid_from' => now()->subMonth(), 'valid_to' => now()->subDay()]);

    $list = $this->getJson('/api/v1/select/cms/categories');

    $list->assertOk();
    expect(collect($list->json('data'))->pluck('id')->sort()->values()->all())->toBe([$live->id]);

    $this->getJson('/api/v1/detail/cms/categories?' . http_build_query(['id' => $live->id]))->assertOk();

    foreach ([$inactive, $future, $expired] as $hidden) {
        $this->getJson('/api/v1/detail/cms/categories?' . http_build_query(['id' => $hidden->id]))->assertNotFound();
    }
});
