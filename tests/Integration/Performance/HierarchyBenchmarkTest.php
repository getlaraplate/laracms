<?php

declare(strict_types=1);

use App\Models\User as AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Performance\BenchmarkRunner;
use Modules\Core\Services\AclResolverService;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;

/*
 * Baseline of the three hierarchies (role, category, comment) on the adjacency-list engine.
 *
 * Skipped unless HIERARCHY_BENCHMARK=1. HIERARCHY_BENCHMARK_ITERATIONS (default 20) sets the measured
 * iterations of every case. One line per case goes to STDERR: case, p50 and p95 duration in ms, queries
 * per iteration (median). The same test is run again after the closure-table migration to compare.
 */

uses(TestCase::class, RefreshDatabase::class);

/**
 * Inserts rows in chunks, so that the seed of thousands of rows stays a handful of statements.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function hierarchyBenchmarkInsert(string $table, array $rows): void
{
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table($table)->insert($chunk);
    }
}

/**
 * 5,000 categories, six levels deep, `order_column` unrelated to depth. A subtree of exactly 200 nodes hangs
 * under id 11 (a level-2 node), to be moved between the level-1 nodes 1 and 2.
 *
 * @return array{levels: array<int, list<int>>, subtree_root: int, subtree_size: int, parent_of: array<int, int|null>}
 */
function hierarchyBenchmarkSeedCategories(int $entity_id, int $presettable_id): array
{
    mt_srand(20261118);
    $now = now()->toDateTimeString();
    $rows = [];
    $translations = [];
    $levels = [];
    $next_id = 1;

    $add = static function (int $level, ?int $parent_id) use (&$rows, &$translations, &$levels, &$next_id, $entity_id, $presettable_id, $now): int {
        $id = $next_id++;
        $rows[] = [
            'id' => $id,
            'entity_id' => $entity_id,
            'presettable_id' => $presettable_id,
            'parent_id' => $parent_id,
            'is_active' => 1,
            'order_column' => mt_rand(1, 100_000),
            'created_at' => $now,
            'updated_at' => $now,
            'valid_from' => '2020-01-01 00:00:00',
        ];
        $translations[] = [
            'taxonomy_id' => $id,
            'locale' => 'en',
            'name' => 'Category ' . $id,
            'slug' => 'category-' . $id,
            'components' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $levels[$level][] = $id;

        return $id;
    };

    // Level 0: 10 roots; level 1: 30 nodes; level 2: 100 nodes (id 11 is the first level-2 node).
    foreach (range(1, 10) as $unused) {
        $add(0, null);
    }

    foreach (range(1, 30) as $i) {
        $add(1, $levels[0][($i - 1) % 10]);
    }

    // The level-1 nodes 1 and 2 are ids 11 and 12: the move targets. The subtree root is the first level-2 node.
    $subtree_root = $add(2, $levels[1][2]);

    foreach (range(1, 99) as $i) {
        $add(2, $levels[1][mt_rand(0, 29)]);
    }

    // The 200-node subtree: root, 4 children, 15 grandchildren, 180 great-grandchildren.
    $children = [];

    foreach (range(1, 4) as $unused) {
        $children[] = $add(3, $subtree_root);
    }

    $grandchildren = [];

    foreach (range(1, 15) as $i) {
        $grandchildren[] = $add(4, $children[($i - 1) % 4]);
    }

    foreach (range(1, 180) as $i) {
        $add(5, $grandchildren[($i - 1) % 15]);
    }

    // The rest of the 5,000 spreads over levels 3 to 5.
    while ($next_id <= 5_000) {
        $level = match (true) {
            $next_id <= 1_000 => 3,
            $next_id <= 2_500 => 4,
            default => 5,
        };
        $pool_level = $level - 1;
        $pool = $levels[$pool_level];
        $add($level, $pool[mt_rand(0, count($pool) - 1)]);
    }

    hierarchyBenchmarkInsert(CoreTables::Taxonomies->value, $rows);
    hierarchyBenchmarkInsert(CoreTables::TaxonomiesTranslations->value, $translations);

    return [
        'levels' => $levels,
        'subtree_root' => $subtree_root,
        'subtree_size' => 200,
        'parent_of' => array_column($rows, 'parent_id', 'id'),
    ];
}

/**
 * The category, its ancestors and its descendants.
 *
 * @param  array<int, int|null>  $parent_of
 * @return list<int>
 */
function hierarchyBenchmarkFamily(array $parent_of, int $id): array
{
    $family = [$id];

    for ($ancestor = $parent_of[$id]; $ancestor !== null; $ancestor = $parent_of[$ancestor]) {
        $family[] = $ancestor;
    }

    $frontier = [$id];

    while ($frontier !== []) {
        $children = array_keys(array_filter($parent_of, static fn (?int $parent): bool => in_array($parent, $frontier, true)));
        $family = [...$family, ...$children];
        $frontier = $children;
    }

    return $family;
}

/**
 * 20,000 comments of one content, in threads up to five levels deep.
 *
 * @return array{deep_parent: int}
 */
function hierarchyBenchmarkSeedComments(int $content_id, int $user_id): array
{
    mt_srand(20261119);
    $now = now()->toDateTimeString();
    $rows = [];
    $translations = [];
    $by_depth = [];
    $deep_parent = null;

    foreach (range(1, 20_000) as $id) {
        if ($id <= 2_000) {
            $depth = 1;
            $parent_id = null;
        } else {
            $parent_depth = mt_rand(1, min(4, count($by_depth)));
            $parent_id = $by_depth[$parent_depth][array_rand($by_depth[$parent_depth])];
            $depth = $parent_depth + 1;
        }

        $by_depth[$depth][] = $id;

        if ($depth === 4 && $deep_parent === null) {
            $deep_parent = $id;
        }

        $rows[] = [
            'id' => $id,
            'content_id' => $content_id,
            'user_id' => $user_id,
            'parent_id' => $parent_id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $translations[] = [
            'comment_id' => $id,
            'locale' => 'en',
            'body' => 'Comment ' . $id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    hierarchyBenchmarkInsert(CMSTables::Comments->value, $rows);
    hierarchyBenchmarkInsert(CMSTables::CommentsTranslations->value, $translations);

    return ['deep_parent' => (int) $deep_parent];
}

it('measures the baseline of the role, category and comment hierarchies', function (): void {
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents, EntityType::Categories]);
    $iterations = max(1, (int) env('HIERARCHY_BENCHMARK_ITERATIONS', 20));
    $runner = app(BenchmarkRunner::class);
    $report_lines = [];

    $record = function (string $case, Closure $operation) use ($runner, $iterations, &$report_lines): void {
        $report = $runner->run($operation, $iterations, 2);
        $report_lines[] = sprintf(
            '%-34s p50 %9.2f ms   p95 %9.2f ms   queries %6.1f',
            $case,
            $report->durationStats->p50,
            $report->durationStats->p95,
            $report->queryStats->p50,
        );
    };

    $user = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('superadmin', 'web'));

    // Seeds.
    $categories_entity = Entity::query()->where('name', 'categories')->firstOrFail();
    $categories_presettable = Presettable::query()->where('entity_id', $categories_entity->id)->latest('version')->firstOrFail();
    $categories = hierarchyBenchmarkSeedCategories($categories_entity->id, $categories_presettable->id);

    $content = createMinimalTestContentForComments();
    $comments = hierarchyBenchmarkSeedComments($content->id, $user->id);

    // The seeds above are bulk writes: they bypass the model events, so the closure tables are rebuilt.
    Category::rebuildClosure();
    Comment::rebuildClosure();

    // A chain of five roles, a permission at each level, an ACL on every other one, the user on the leaf.
    $permissions = [];
    $parent_id = null;
    $leaf_role = null;

    foreach (range(1, 5) as $level) {
        $role = Role::factory()->create(['name' => 'bench-role-' . $level, 'guard_name' => 'web', 'parent_id' => $parent_id]);
        $permissions[$level] = Permission::query()->firstOrCreate(['name' => 'default.bench_level_' . $level . '_table.select', 'guard_name' => 'web']);
        $role->givePermissionTo($permissions[$level]);

        if ($level % 2 === 1) {
            $acl = new ACL;
            $acl->setSkipValidation(true);
            $acl->forceFill([
                'permission_id' => $permissions[1]->id,
                'role_id' => $role->id,
                'filters' => new FiltersGroup([new Filter('id', [1, 2, 3], FilterOperator::In)], WhereClause::And),
                'unrestricted' => false,
                'priority' => $level,
                'is_active' => true,
            ]);
            $acl->save();
        }

        $parent_id = $role->id;
        $leaf_role = $role;
    }

    $role_user = AppUser::query()->findOrFail(User::factory()->create()->getKey());
    $role_user->assignRole($leaf_role);
    $role_user = $role_user->fresh();

    // A page of 50 contents, each in one deep category.
    $contents = Content::factory()->count(50)->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $deep_categories = $categories['levels'][5];
    hierarchyBenchmarkInsert(CMSTables::Categorizables->value, $contents->values()->map(static fn (Content $c, int $i): array => [
        'content_id' => $c->id,
        'taxonomy_id' => $deep_categories[($i * 7) % count($deep_categories)],
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ])->all());

    // Case 1: ACL resolution for a user holding the leaf of the role chain.
    $resolver = app(AclResolverService::class);
    $record('acl resolution (5 roles)', static function () use ($resolver, $role_user, $permissions): void {
        Cache::flush();
        $resolver->getEffectiveAcls($role_user, $permissions[1]);
    });

    // Case 2: a chunk of 50 contents prepared for the search engine as the indexer prepares it: the import query,
    // makeSearchableUsing() (the relations the document reads), then each document.
    $record('50 contents indexed', static function (): void {
        $indexer = new Content;
        $chunk = $indexer->makeAllSearchableUsing(Content::query())->orderBy('id')->limit(50)->get();

        foreach ($indexer->makeSearchableUsing($chunk) as $item) {
            $item->toSearchableArray();
        }
    });

    // Case 3: the tree endpoint of a category, with parents, children and both. The endpoint takes no filter
    // (a primary key makes it call sole() on the whole table), so the reader's ACL narrows the root query to
    // the family of one category: its ancestors and its descendants, each loaded with its own tree.
    $middle = $categories['levels'][3][0];
    $family = hierarchyBenchmarkFamily($categories['parent_of'], $middle);
    $reader_role = Role::factory()->create(['name' => 'bench-tree-reader', 'guard_name' => 'api']);
    $select_name = PermissionName::forClass(Category::class, 'select');
    Permission::query()->firstOrCreate(['name' => $select_name, 'guard_name' => 'web']);
    $select = Permission::query()->firstOrCreate(['name' => $select_name, 'guard_name' => 'api']);
    $reader_role->givePermissionTo($select);
    $family_acl = new ACL;
    $family_acl->setSkipValidation(true);
    $family_acl->forceFill([
        'permission_id' => $select->id,
        'role_id' => $reader_role->id,
        'filters' => new FiltersGroup([new Filter('id', $family, FilterOperator::In)], WhereClause::And),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $family_acl->save();
    $reader = AppUser::query()->findOrFail(User::factory()->create()->getKey());
    $reader->assignRole($reader_role);
    $reader = $reader->fresh();
    $tree = function (array $query) use ($reader, $family): void {
        Cache::flush();
        $response = test()->actingAs($reader)->json('GET', '/api/v1/tree/cms/categories', $query);
        $response->assertOk();
        throw_unless(count((array) $response->json('data')) === count($family), RuntimeException::class, 'The tree did not return the family of the category.');
    };
    $record('crud tree: parents', static fn () => $tree(['parents' => true]));
    $record('crud tree: children', static fn () => $tree(['children' => true]));
    $record('crud tree: parents + children', static fn () => $tree(['parents' => true, 'children' => true]));

    // Case 4: creating a leaf comment under a fourth-level comment.
    $record('create a leaf comment', static function () use ($content, $user, $comments): void {
        Comment::factory()->approved('Benchmark leaf')->create([
            'content_id' => $content->id,
            'user_id' => $user->id,
            'parent_id' => $comments['deep_parent'],
        ]);
    });

    // Case 5: moving a 200-node category subtree between two parents.
    $targets = [$categories['levels'][1][0], $categories['levels'][1][1]];
    $moves = 0;
    $record('move a 200-node category subtree', static function () use ($categories, $targets, &$moves): void {
        $subtree = Category::query()->findOrFail($categories['subtree_root']);
        $subtree->parent_id = $targets[$moves++ % 2];
        $subtree->save();
    });

    // Case 6: deleting a middle category (one per iteration, each with children).
    $victims = array_slice($categories['levels'][3], 20, $iterations + 2);
    $deleted = 0;
    $record('delete a middle category', static function () use ($victims, &$deleted): void {
        Category::query()->findOrFail($victims[$deleted++])->delete();
    });

    fwrite(STDERR, PHP_EOL . 'HIERARCHY BENCHMARK (' . DB::connection()->getDriverName() . ', ' . $iterations . ' iterations)' . PHP_EOL . implode(PHP_EOL, $report_lines) . PHP_EOL);

    expect($report_lines)->toHaveCount(8)
        ->and(Category::query()->findOrFail($categories['subtree_root'])->parent_id)->toBeIn($targets)
        ->and(Category::onlyTrashed()->count())->toBe($deleted);
})->skip(! filter_var(env('HIERARCHY_BENCHMARK', false), FILTER_VALIDATE_BOOL), 'Set HIERARCHY_BENCHMARK=1 to run the hierarchy baseline benchmark.')
    ->group('benchmark', 'hierarchy');
