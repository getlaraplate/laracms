<?php

declare(strict_types=1);

namespace Modules\CMS\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Laravel\Scout\ModelObserver;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Tag;
use Modules\Core\Concurrency\BatchTask;
use Modules\Core\Helpers\BatchSeeder;

final class DevCMSDatabaseSeeder extends BatchSeeder
{
    private const int PIVOT_CHUNK_SIZE = 1_000;

    private const int TARGET_COUNT_CONTRIBUTORS = 15_000;

    private const int TARGET_COUNT_CATEGORIES = 1_000;

    private const int TARGET_COUNT_LOCATIONS = 10_000;

    private const int TARGET_COUNT_TAGS = 300_000;

    private const int TARGET_COUNT_CONTENTS = 500_000;

    protected function execute(): void
    {
        Artisan::call('module:seed', ['module' => 'CMS', '--force' => $this->console()->option('force')], outputBuffer: $this->console()->getOutput());

        ModelObserver::disableSyncingFor(Content::class);
        ModelObserver::disableSyncingFor(Location::class);

        try {
            $this->withoutModelVersioning(
                [Content::class, Contributor::class, Category::class, Location::class, Tag::class],
                fn () => Model::unguarded(function (): void {
                    $this->seedContributors();
                    $this->seedCategories();
                    $this->seedLocations();
                    $this->seedTags();
                    $this->seedContents();
                }),
            );
        } finally {
            ModelObserver::enableSyncingFor(Content::class);
            ModelObserver::enableSyncingFor(Location::class);
        }

        Artisan::call('cache:clear');
    }

    private function seedContributors(): void
    {
        $this->createInParallelBatches(Contributor::class, self::TARGET_COUNT_CONTRIBUTORS);
    }

    private function seedCategories(): void
    {
        $this->createInParallelBatches(Category::class, self::TARGET_COUNT_CATEGORIES);
    }

    private function seedLocations(): void
    {
        $this->createInParallelBatches(Location::class, self::TARGET_COUNT_LOCATIONS);
    }

    private function seedTags(): void
    {
        $this->createInParallelBatches(Tag::class, self::TARGET_COUNT_TAGS);
    }

    private function seedContents(): void
    {
        $this->createInParallelBatches(Content::class, self::TARGET_COUNT_CONTENTS);

        // Create pivot relations after contents are created
        $this->createPivotRelations();
    }

    private function createPivotRelations(): void
    {
        // Build the candidate id pools once (picking from them in PHP avoids an
        // ORDER BY RAND() query per content), then relate contents in parallel:
        // one fork per chunk of content ids. The pools live in the parent and are
        // inherited by each fork through copy-on-write.
        $factory = Content::factory();
        $pools = $factory->buildRelationIdPools();
        $content_ids = $pools['contents'];

        if ($content_ids === []) {
            $this->console()->info('No contents to relate.');

            return;
        }

        // Only queue contents that still lack required pivots (contributors /
        // categories). createRelations() remains idempotent inside each chunk;
        // this gate avoids a full re-walk that looks like "5000 created" on a
        // re-run when everything was already related.
        $pending_ids = $factory->idsMissingRequiredRelations($content_ids);
        $already_related = count($content_ids) - count($pending_ids);

        $this->console()->info(sprintf(
            'Pivot relations: %d contents, %d need relations, %d already related.',
            count($content_ids),
            count($pending_ids),
            $already_related,
        ));

        if ($pending_ids === []) {
            $this->console()->info('All contents already have required pivot relations.');

            return;
        }

        $connection_name = Content::query()->getConnection()->getName();
        $tasks = [];

        foreach (array_chunk($pending_ids, self::PIVOT_CHUNK_SIZE) as $index => $chunk_ids) {
            $tasks[] = new BatchTask(
                id: "pivots_{$index}",
                units: count($chunk_ids),
                run: static function () use ($chunk_ids, $pools): int {
                    $contents = Content::query()->withoutGlobalScopes()->whereKey($chunk_ids)->get();
                    Content::factory()->createRelations($contents, null, $pools);

                    return count($chunk_ids);
                },
            );
        }

        $this->runParallelTasks($tasks, 'Creating pivot relations (parallel)', $connection_name, self::PIVOT_CHUNK_SIZE);

        $this->console()->info('Pivot relations created successfully!');
    }
}
