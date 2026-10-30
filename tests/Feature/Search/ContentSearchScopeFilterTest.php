<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A search engine takes no scope node (S8): the node is left out of the engine filters, as a relation filter
 * is, and the records behind the hits are checked against it when they are reloaded from the database. The
 * hits of the test engine (the collection driver) are every content, so only that reload can narrow them.
 */

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A live content, a scheduled one (valid from next week) and an expired one.
 *
 * @return array{live: Content, scheduled: Content, expired: Content}
 */
function scopesearch_contents(): array
{
    return [
        'live' => Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]),
        'scheduled' => Content::factory()->create(['valid_from' => now()->addWeek(), 'valid_to' => null]),
        'expired' => Content::factory()->create(['valid_from' => now()->subMonth(), 'valid_to' => now()->subDay()]),
    ];
}

/**
 * A user whose `api` role may select contents, narrowed by the given ACL (none: unrestricted).
 */
function scopesearch_reader(?FiltersGroup $acl_filters = null): App\Models\User
{
    $role = Role::factory()->create(['name' => 'scopesearch_reader_' . uniqid(), 'guard_name' => 'api']);
    $name = PermissionName::forClass(Content::class, 'select');
    Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']);
    $role->givePermissionTo($permission);

    if ($acl_filters instanceof FiltersGroup) {
        $acl = new ACL;
        $acl->forceFill([
            'permission_id' => $permission->id,
            'role_id' => $role->id,
            'filters' => $acl_filters,
            'unrestricted' => false,
            'priority' => 10,
            'is_active' => true,
        ]);
        $acl->save();
    }

    $user = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * @param  array<mixed>|null  $filters
 */
function scopesearch_search(App\Models\User $reader, ?array $filters = null): TestResponse
{
    $query = ['qs' => 'contents'];

    if ($filters !== null) {
        $query['filters'] = json_encode($filters, JSON_THROW_ON_ERROR);
    }

    return test()->actingAs($reader)->getJson('/api/v1/search/cms/contents?' . http_build_query($query));
}

/**
 * @return list<int>
 */
function scopesearch_ids(TestResponse $response): array
{
    $ids = array_map(static fn (mixed $id): int => (int) $id, collect($response->json('data'))->pluck('id')->all());
    sort($ids);

    return $ids;
}

it('finds every content when nothing narrows the search, so the engine alone narrows nothing', function (): void {
    $all = scopesearch_contents();

    $response = scopesearch_search(scopesearch_reader());

    $response->assertOk();
    expect(scopesearch_ids($response))->toBe([$all['live']->id, $all['scheduled']->id, $all['expired']->id]);
});

it('enforces a client scope of the search request when the hits are reloaded', function (): void {
    $all = scopesearch_contents();

    $response = scopesearch_search(scopesearch_reader(), ['scope' => 'valid']);

    $response->assertOk();
    expect(scopesearch_ids($response))->toBe([$all['live']->id]);
});

it('enforces a request scope dropped from an AND group, beside a column filter the engine takes', function (): void {
    $all = scopesearch_contents();

    $response = scopesearch_search(scopesearch_reader(), [
        'operator' => 'and',
        'filters' => [
            ['scope' => 'valid'],
            ['property' => 'valid_to', 'operator' => 'eq', 'value' => null],
        ],
    ]);

    // The engine takes the column filter and leaves out the expired content; the scope leaves out the scheduled one.
    $response->assertOk();
    expect(scopesearch_ids($response))->toBe([$all['live']->id]);
});

it('enforces a request OR group holding a scope, left out of the engine whole', function (array $filters, array $expected): void {
    $all = scopesearch_contents();

    $response = scopesearch_search(scopesearch_reader(), $filters);

    $response->assertOk();
    expect(scopesearch_ids($response))->toBe(array_map(static fn (string $key): int => $all[$key]->id, $expected));
})->with([
    'two scopes' => [
        ['operator' => 'or', 'filters' => [['scope' => 'scheduled'], ['scope' => 'expired']]],
        ['scheduled', 'expired'],
    ],
    'a scope and a column filter' => [
        ['operator' => 'or', 'filters' => [['scope' => 'scheduled'], ['property' => 'valid_to', 'operator' => 'lt', 'value' => '2026-08-19 00:00:00']]],
        ['scheduled', 'expired'],
    ],
    'a scope in an AND group inside the OR group' => [
        ['operator' => 'or', 'filters' => [
            ['operator' => 'and', 'filters' => [['scope' => 'valid'], ['property' => 'valid_from', 'operator' => 'le', 'value' => '2026-08-19 00:00:00']]],
            ['scope' => 'expired'],
        ]],
        ['live', 'expired'],
    ],
]);

it('refuses with 422 an undeclared scope in a search request', function (): void {
    scopesearch_contents();

    $response = scopesearch_search(scopesearch_reader(), ['scope' => 'undeclared']);

    $response->assertUnprocessable()->assertJsonValidationErrors('filters');
});

it('enforces an ACL scope in an AND group when the hits are reloaded', function (): void {
    $all = scopesearch_contents();
    $reader = scopesearch_reader(new FiltersGroup([
        new ScopeFilter('valid'),
        new Filter('valid_from', '@now', FilterOperator::LessEquals),
    ]));

    $response = scopesearch_search($reader);

    $response->assertOk();
    expect(scopesearch_ids($response))->toBe([$all['live']->id]);
});

it('enforces an ACL OR group holding a scope, left out of the engine whole', function (): void {
    $all = scopesearch_contents();
    $reader = scopesearch_reader(new FiltersGroup([
        new ScopeFilter('valid'),
        new Filter('valid_to', '@now', FilterOperator::Less),
    ], WhereClause::Or));

    $response = scopesearch_search($reader);

    $response->assertOk();
    expect(scopesearch_ids($response))->toBe([$all['live']->id, $all['expired']->id]);
});

it('combines an ACL scope with a request scope by AND', function (): void {
    $all = scopesearch_contents();
    $reader = scopesearch_reader(new FiltersGroup([new ScopeFilter('expired')]));

    expect(scopesearch_ids(scopesearch_search($reader, ['scope' => 'expired'])->assertOk()))->toBe([$all['expired']->id])
        ->and(scopesearch_ids(scopesearch_search($reader, ['scope' => 'valid'])->assertOk()))->toBe([]);
});

it('keeps a request OR group applied at the reload inside its own condition, so it never widens the ACL', function (): void {
    scopesearch_contents();
    $reader = scopesearch_reader(new FiltersGroup([new ScopeFilter('valid')]));

    $response = scopesearch_search($reader, ['operator' => 'or', 'filters' => [
        ['scope' => 'scheduled'],
        ['property' => 'valid_to', 'operator' => 'lt', 'value' => '2026-08-19 00:00:00'],
    ]]);

    $response->assertOk();
    expect(scopesearch_ids($response))->toBe([]);
});
