<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Services\ContentExtenderRegistry;
use Modules\CMS\Tests\Stubs\ContentExtension\StubExtendedThing;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Search\Support\DefaultSearchFilters;

uses(TestCase::class, RefreshDatabase::class);

function makeExtendedContent(): StubExtendedThing
{
    $stub = new StubExtendedThing(['brand' => 'Acme']);
    $stub->setTempContent(Content::factory()->make());
    $stub->save();

    return $stub;
}

beforeEach(function (): void {
    Config::set('scout.driver', 'database');
    setupCMSEntities([EntityType::Contents]);

    Schema::create('cms_stub_extended_things', function ($table): void {
        $table->id();
        $table->foreignId('content_id')->unique();
        $table->string('brand')->nullable();
        $table->string('sku')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    app(ContentExtenderRegistry::class)->register('cms.stub_extended', StubExtendedThing::class);
});

it('excludes extended contents from the generic search through the host contract', function (): void {
    // Content declares no default search filter for it: the exclusion comes from the Core host contract
    // (spec 2026-11-17-extended-entity-search-design.md, XS11).
    expect(DefaultSearchFilters::of(new Content()))->toBe(['extended_type' => null]);
});

it('compiles the extended_type filter to IS NULL on the database engine', function (): void {
    Content::factory()->create();
    makeExtendedContent();

    // The engine applies the filter as `where('extended_type', '=', null)`, which the query builder
    // turns into `extended_type IS NULL`; assert that on a plain Eloquent query (the DB engine's own
    // `search('*')` column set is unrelated and pre-existing).
    $sql = Content::withExtended()->where('extended_type', null)->toRawSql();

    expect($sql)->toContain('"extended_type" is null');

    $ids = Content::withExtended()->where('extended_type', null)->pluck('id')->all();

    expect($ids)->toHaveCount(1);
});
