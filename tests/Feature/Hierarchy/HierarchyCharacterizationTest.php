<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\CMS\Models\Comment;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;

/*
 * Characterization of the comment hierarchy on the adjacency-list engine, written before it is replaced.
 * The thread is: comment 1 (root), comments 2 and 3 (its replies), comment 4 (reply to 2). The expected
 * payloads are what the tree endpoint returns today, `depth` (signed: negative towards the ancestors) included,
 * and they must keep holding unchanged unless the migration plan says otherwise. The dotted `path` the adjacency-list
 * engine also gave each node is gone: Laraplate stays headless and computes no hierarchy path (decision 2026-11-24).
 */

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    CrudApiExposure::enable();
    $this->content = createMinimalTestContentForComments();
    $this->author = User::factory()->create();
    $this->root = hierarchyCharacterizationComment($this->content->id, $this->author->id, 'root');
    $this->first_reply = hierarchyCharacterizationComment($this->content->id, $this->author->id, 'first reply', $this->root->id);
    $this->second_reply = hierarchyCharacterizationComment($this->content->id, $this->author->id, 'second reply', $this->root->id);
    $this->grandchild = hierarchyCharacterizationComment($this->content->id, $this->author->id, 'grandchild', $this->first_reply->id);
});

function hierarchyCharacterizationComment(int $content_id, int $user_id, string $body, ?int $parent_id = null): Comment
{
    return Comment::factory()->approved($body)->create([
        'content_id' => $content_id,
        'user_id' => $user_id,
        'parent_id' => $parent_id,
    ]);
}

/**
 * The tree of every comment, reduced to `[node id, [[related id, depth], ...]]` in response order.
 *
 * @param  array<string, bool>  $query
 * @return list<array{0: int, 1: list<array{0: int, 1: int}>}>
 */
function hierarchyCharacterizationTree(TestCase $test, User $caller, array $query): array
{
    // The CRUD responses are cached per URL, not per caller: each reading starts from an empty cache.
    Cache::flush();
    $response = $test->actingAs($caller)->json('GET', '/api/v1/tree/cms/comments', $query);
    $response->assertOk();

    return collect($response->json('data'))
        ->map(static function (array $node): array {
            $relation = collect($node)->only(['descendants_and_self', 'ancestors_and_self', 'bloodline'])->first();

            return [
                $node['id'],
                collect($relation)->map(static fn (array $related): array => [$related['id'], $related['depth']])->all(),
            ];
        })
        ->all();
}

function hierarchyCharacterizationSuperadmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('superadmin', 'web'));

    return $admin;
}

it('pins the identifiers of the thread the expected payloads are written for', function (): void {
    expect([$this->root->id, $this->first_reply->id, $this->second_reply->id, $this->grandchild->id])->toBe([1, 2, 3, 4]);
});

it('returns the descendants of every comment with today\'s depth when children are requested', function (): void {
    $tree = hierarchyCharacterizationTree($this, hierarchyCharacterizationSuperadmin(), ['children' => true]);

    expect($tree)->toBe([
        [1, [[1, 0], [2, 1], [3, 1], [4, 2]]],
        [2, [[2, 0], [4, 1]]],
        [3, [[3, 0]]],
        [4, [[4, 0]]],
    ]);
});

it('returns the ancestors of every comment with today\'s signed depth when parents are requested', function (): void {
    $tree = hierarchyCharacterizationTree($this, hierarchyCharacterizationSuperadmin(), ['parents' => true]);

    expect($tree)->toBe([
        [1, [[1, 0]]],
        [2, [[2, 0], [1, -1]]],
        [3, [[3, 0], [1, -1]]],
        [4, [[4, 0], [2, -1], [1, -2]]],
    ]);
});

it('returns the whole bloodline of every comment when parents and children are requested', function (): void {
    $tree = hierarchyCharacterizationTree($this, hierarchyCharacterizationSuperadmin(), ['children' => true, 'parents' => true]);

    expect($tree)->toBe([
        [1, [[1, 0], [2, 1], [3, 1], [4, 2]]],
        [2, [[2, 0], [4, 1], [1, -1]]],
        [3, [[3, 0], [1, -1]]],
        [4, [[4, 0], [2, -1], [1, -2]]],
    ]);
});

it('puts no path on any node of the tree', function (array $query): void {
    Cache::flush();
    $response = $this->actingAs(hierarchyCharacterizationSuperadmin())->json('GET', '/api/v1/tree/cms/comments', $query);
    $response->assertOk();
    $nodes = collect($response->json('data'))
        ->flatMap(static fn (array $node): array => [$node, ...collect($node)->only(['descendants_and_self', 'ancestors_and_self', 'bloodline'])->first()]);

    expect($nodes)->toHaveCount(match (true) {
        isset($query['children'], $query['parents']) => 4 + 4 + 3 + 2 + 3,
        isset($query['children']) => 4 + 4 + 2 + 1 + 1,
        default => 4 + 1 + 2 + 2 + 3,
    })
        ->and($nodes->filter(static fn (array $node): bool => array_key_exists('path', $node)))->toBeEmpty();
})->with([
    'children' => [['children' => true]],
    'parents' => [['parents' => true]],
    'parents and children' => [['children' => true, 'parents' => true]],
]);

it('leaves out of every tree the comment the ACL hides and keeps the others unchanged', function (): void {
    $role = Role::factory()->create(['name' => 'hierarchy_reader', 'guard_name' => 'api']);
    $permission_name = PermissionName::forClass(Comment::class, 'select');
    Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'web']);
    $permission = Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'api']);
    $role->givePermissionTo($permission);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $role->id,
        'filters' => new FiltersGroup([new Filter('id', [1, 2, 4], FilterOperator::In)], WhereClause::And),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $reader = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
    $reader->assignRole($role);
    $reader = $reader->fresh();

    expect(hierarchyCharacterizationTree($this, $reader, ['children' => true]))->toBe([
        [1, [[1, 0], [2, 1], [4, 2]]],
        [2, [[2, 0], [4, 1]]],
        [4, [[4, 0]]],
    ])->and(hierarchyCharacterizationTree($this, $reader, ['parents' => true]))->toBe([
        [1, [[1, 0]]],
        [2, [[2, 0], [1, -1]]],
        [4, [[4, 0], [2, -1], [1, -2]]],
    ])->and(hierarchyCharacterizationTree($this, $reader, ['children' => true, 'parents' => true]))->toBe([
        [1, [[1, 0], [2, 1], [4, 2]]],
        [2, [[2, 0], [4, 1], [1, -1]]],
        [4, [[4, 0], [2, -1], [1, -2]]],
    ]);
});

it('loads on two threads\' roots only the replies of each', function (): void {
    $other_root = hierarchyCharacterizationComment($this->content->id, $this->author->id, 'other root');
    $other_reply = hierarchyCharacterizationComment($this->content->id, $this->author->id, 'other reply', $other_root->id);

    $roots = Comment::query()
        ->with('descendants')
        ->whereIn('id', [$this->root->id, $other_root->id])
        ->orderBy('id')
        ->get();

    expect($roots->pluck('id')->all())->toBe([$this->root->id, $other_root->id])
        ->and($roots[0]->descendants->pluck('id')->sort()->values()->all())->toBe([2, 3, 4])
        ->and($roots[1]->descendants->pluck('id')->all())->toBe([$other_reply->id]);
});
