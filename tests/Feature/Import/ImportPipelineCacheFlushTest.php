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

it('flushes every table a run can write in one flush call, after the run', function (): void {
    $index = new RecordingLabelIndex;
    $this->app->instance(ICacheLabelIndex::class, $index);
    $this->app->forgetInstance(ImportPipeline::class);

    resolve(ImportPipeline::class)->import(buildImportGraphFromFixture());

    $content = new Content;
    $expected_tables = array_values(array_unique([
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
    ]));
    $expected = [];

    foreach ($expected_tables as $table) {
        $expected[] = CacheLabel::table($table);
        $expected[] = CacheLabel::rows($table);
    }

    expect(end($index->flushes))->toEqualCanonicalizing($expected);
});
