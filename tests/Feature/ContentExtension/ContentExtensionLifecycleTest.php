<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\Stubs\ContentExtension\StubExtendedThing;
use Modules\CMS\Tests\Stubs\ContentExtension\StubOptionalExtendedThing;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Search\Extensions\ExtensionRegistry;

uses(TestCase::class, RefreshDatabase::class);

function makeMandatoryExtender(): StubExtendedThing
{
    $stub = new StubExtendedThing(['brand' => 'Acme']);
    $stub->setTempContent(Content::factory()->make());
    $stub->save();

    return $stub;
}

function makeOptionalExtender(): StubOptionalExtendedThing
{
    $stub = new StubOptionalExtendedThing(['label' => 'note']);
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

    Schema::create('cms_stub_optional_extended_things', function ($table): void {
        $table->id();
        $table->foreignId('content_id')->nullable()->unique();
        $table->string('label')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $registry = app(ExtensionRegistry::class);
    $registry->register(Content::class, 'cms.stub_extended', StubExtendedThing::class);
    $registry->register(Content::class, 'cms.stub_optional', StubOptionalExtendedThing::class);
});

it('cascades an extender soft-delete to its content', function (): void {
    $stub = makeMandatoryExtender();
    $contentId = $stub->content_id;

    $stub->delete();

    expect(Content::withExtended()->find($contentId))->toBeNull()
        ->and(Content::withExtended()->withTrashed()->find($contentId))->not->toBeNull();
});

it('cascades an extender force-delete to its content', function (): void {
    $stub = makeMandatoryExtender();
    $contentId = $stub->content_id;

    $stub->forceDelete();

    expect(Content::withExtended()->withTrashed()->find($contentId))->toBeNull();
});

it('cascades an extender restore to its content', function (): void {
    $stub = makeMandatoryExtender();
    $contentId = $stub->content_id;
    $stub->delete();

    StubExtendedThing::withTrashed()->findOrFail($stub->getKey())->restore();

    expect(Content::withExtended()->find($contentId))->not->toBeNull();
});

it('cascades a direct content deletion to a mandatory extender', function (): void {
    $stub = makeMandatoryExtender();

    $stub->content->delete();

    expect(StubExtendedThing::find($stub->getKey()))->toBeNull()
        ->and(StubExtendedThing::withTrashed()->find($stub->getKey()))->not->toBeNull();
});

it('orphans an optional extender on a direct content deletion', function (): void {
    $stub = makeOptionalExtender();

    $stub->content->delete();

    $reloaded = StubOptionalExtendedThing::find($stub->getKey());

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->content_id)->toBeNull();
});
