<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\Stubs\ContentExtension\StubExtendedThing;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Search\Extensions\ExtensionRegistry;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A page of contents upcast through Core (`Content::upcastExtensions()`, Core `ExtensionResolver`), with an extender
 * registered for `Content` in Core's `ExtensionRegistry`. Moved from the tests of the CMS `ContentExtensionResolver`,
 * which delegated to Core and was removed.
 */

function makeStubExtender(string $brand): StubExtendedThing
{
    $stub = new StubExtendedThing(['brand' => $brand]);
    $stub->setTempContent(Content::factory()->make());
    $stub->save();

    return $stub;
}

beforeEach(function (): void {
    setupCMSEntities([EntityType::Contents]);

    Schema::create('cms_stub_extended_things', function ($table): void {
        $table->id();
        $table->foreignId('content_id')->unique();
        $table->string('brand')->nullable();
        $table->string('sku')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    app(ExtensionRegistry::class)->register(Content::class, 'cms.stub_extended', StubExtendedThing::class);
});

it('swaps extended contents for their extenders and keeps plain contents', function (): void {
    $extA = makeStubExtender('A');
    $extB = makeStubExtender('B');
    $plain = Content::factory()->create();

    $page = Content::withExtended()->orderBy('id')->get();
    $resolved = (new Content())->upcastExtensions($page);

    expect($resolved)->toHaveCount(3);

    $byId = $resolved->keyBy(fn ($item): mixed => $item instanceof StubExtendedThing ? $item->content_id : $item->getKey());

    expect($byId[$extA->content_id])->toBeInstanceOf(StubExtendedThing::class)
        ->and($byId[$extA->content_id]->brand)->toBe('A')
        ->and($byId[$extB->content_id])->toBeInstanceOf(StubExtendedThing::class)
        ->and($byId[$plain->getKey()])->toBeInstanceOf(Content::class);
});

it('attaches the content and resolves an entity-scoped page in one extender query', function (): void {
    makeStubExtender('A');
    makeStubExtender('B');

    $page = Content::withExtended()->get();

    DB::connection()->enableQueryLog();
    $resolved = (new Content())->upcastExtensions($page);
    $extenderQueries = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    // One alias on the page => exactly one query to load its extenders (content re-attached, not reloaded).
    expect($extenderQueries)->toBe(1);

    /** @var StubExtendedThing $first */
    $first = $resolved->first();

    // The content travels with the extender (setRelation), so accessing it triggers no further query.
    expect($first->relationLoaded('content'))->toBeTrue()
        ->and($first->content)->not->toBeNull();
});

it('fails loud on an unregistered alias', function (): void {
    $content = Content::factory()->create();
    DB::table(CMSTables::Contents->value)->where('id', $content->getKey())->update(['extended_type' => 'cms.unregistered']);

    $page = Content::withExtended()->get();

    (new Content())->upcastExtensions($page);
})->throws(InvalidArgumentException::class);

it('fails loud when a registered alias has no extender row', function (): void {
    $content = Content::factory()->create();
    DB::table(CMSTables::Contents->value)->where('id', $content->getKey())->update(['extended_type' => 'cms.stub_extended']);

    $page = Content::withExtended()->get();

    (new Content())->upcastExtensions($page);
})->throws(RuntimeException::class);
