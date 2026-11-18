<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Import\Pipeline\ImportPipeline;
use Modules\CMS\Import\Support\ImportIdMap;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['scout.driver' => 'null']);

    $content = new Content;

    if (! $content->getConnection()->getSchemaBuilder()->hasTable($content->getTable())) {
        $this->markTestSkipped('CMS import tests require full schema.');
    }

    setupCMSEntities();
    DynamicContentsService::reset();
    resolve(ImportIdMap::class)->reset();
});

it('flushes the table label of every table a run wrote once, after the run', function (): void {
    $index = new RecordingLabelIndex;
    $this->app->instance(ICacheLabelIndex::class, $index);
    $this->app->forgetInstance(ImportPipeline::class);

    resolve(ImportPipeline::class)->import(buildImportGraphFromFixture());

    $content = new Content;
    $expected_tables = [
        $content->getTable(),
        (new Category)->getTable(),
        (new Contributor)->getTable(),
        (new Tag)->getTable(),
        (new Location)->getTable(),
        $content->categories()->getTable(),
        $content->contributors()->getTable(),
        $content->tags()->getTable(),
        $content->locations()->getTable(),
        $content->related()->getTable(),
    ];
    $expected = array_map(CacheLabel::table(...), array_values(array_unique($expected_tables)));
    $tail = array_slice($index->flushes, -count($expected));
    $tail_labels = array_map(static fn (array $labels): string => $labels[0], $tail);

    expect(array_map('count', $tail))->each->toBe(1)
        ->and($tail_labels)->toEqualCanonicalizing($expected);
});
