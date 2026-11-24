<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\Stubs\ContentExtension\StubExtendedThing;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Search\Extensions\ExtensionRegistry;

uses(TestCase::class, RefreshDatabase::class);

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

it('adds extended_type and a nested extension section to an extended content document', function (): void {
    $stub = new StubExtendedThing(['brand' => 'Acme', 'sku' => 'SKU-9']);
    $stub->setTempContent(Content::factory()->make());
    $stub->save();

    $document = Content::withExtended()->findOrFail($stub->content_id)->toSearchableArray();

    expect($document['extended_type'])->toBe('cms.stub_extended')
        ->and($document['extension'])->toBe([
            'type' => 'cms.stub_extended',
            'brand' => 'Acme',
            'sku' => 'SKU-9',
        ]);
});

it('sets extended_type null and no extension section for a normal content', function (): void {
    $content = Content::factory()->create();

    $document = $content->toSearchableArray();

    expect($document['extended_type'])->toBeNull()
        ->and($document)->not->toHaveKey('extension');
});

it('includes extended contents in the bulk search import query', function (): void {
    $stub = new StubExtendedThing(['brand' => 'Acme']);
    $stub->setTempContent(Content::factory()->make());
    $stub->save();

    $ids = (new Content())->makeAllSearchableUsing(Content::query())->pluck('id')->all();

    expect($ids)->toContain($stub->content_id);
});

it('re-sends the content to the index when its extender changes', function (): void {
    $stub = new StubExtendedThing(['brand' => 'Acme', 'sku' => 'SKU-9']);
    $stub->setTempContent(Content::factory()->make());
    $stub->save();

    Event::fake([ModelRequiresIndexing::class]);

    $stub->refresh();
    $stub->brand = 'Globex';
    $stub->save();

    Event::assertDispatched(
        ModelRequiresIndexing::class,
        static fn (ModelRequiresIndexing $event): bool => $event->model instanceof Content && $stub->content_id === $event->model->getKey(),
    );
    expect(Content::withExtended()->findOrFail($stub->content_id)->toSearchableArray()['extension']['brand'])->toBe('Globex');
});
