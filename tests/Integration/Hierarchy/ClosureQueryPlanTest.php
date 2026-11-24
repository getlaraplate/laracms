<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Models\Category;
use Modules\CMS\Tests\TestCase;

/*
 * The plan SQLite picks for the closure relations of the categories. The model table carries low-cardinality
 * indexes (`is_deleted`) and global scopes with subqueries (the translation of the locale): left to choose, the
 * planner walks every category through `is_deleted` and probes the closure rows last. The relations give it a way
 * in through the closure table, so that the categories are read by key.
 */

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The query plan is read with SQLite\'s EXPLAIN QUERY PLAN.');
    }

    setupCMSEntities();
    $this->root = Category::factory()->create();
    $this->middle = Category::factory()->create(['parent_id' => $this->root->id]);
    // A page of starting nodes: the longer the list of keys, the dearer the planner prices starting from it.
    $this->leaves = Category::factory()->count(20)->create(['parent_id' => $this->middle->id]);
});

/**
 * How the plan of the closure query the callback runs reads the categories table: the detail of that step.
 */
function closureQueryCategoryAccess(Closure $callback): string
{
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, '_closure"')) {
            $queries[] = $query;
        }
    });

    $callback();

    expect($queries)->toHaveCount(1);

    $steps = array_map(
        static fn (object $row): string => (string) $row->detail,
        DB::select('explain query plan ' . $queries[0]->sql, $queries[0]->bindings),
    );

    return implode(PHP_EOL, array_filter($steps, static fn (string $step): bool => preg_match('/^(SEARCH|SCAN) core_taxonomies( |$)/', $step) === 1));
}

it('reads the eager loaded ancestors of categories by key', function (): void {
    $access = closureQueryCategoryAccess(fn () => Category::query()->whereKey($this->leaves->modelKeys())->with('ancestors')->get());

    expect($access)->toStartWith('SEARCH core_taxonomies USING ')->toContain('rowid=?');
});

it('reads the ancestors of one category by key', function (): void {
    $access = closureQueryCategoryAccess(fn () => $this->leaves->first()->ancestors()->get());

    expect($access)->toStartWith('SEARCH core_taxonomies USING ')->toContain('rowid=?');
});

it('reads the eager loaded descendants of categories by key', function (): void {
    $access = closureQueryCategoryAccess(fn () => Category::query()->whereKey([$this->root->id, $this->middle->id])->with('descendants')->get());

    expect($access)->toStartWith('SEARCH core_taxonomies USING ')->toContain('rowid=?');
});

it('reads the ancestors of the batch path by key', function (): void {
    $access = closureQueryCategoryAccess(fn () => Category::assignPaths(Category::query()->whereKey($this->leaves->modelKeys())->get()));

    expect($access)->toStartWith('SEARCH core_taxonomies USING ')->toContain('rowid=?');
});
