<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Services\ContentExtenderRegistry;
use Modules\CMS\Tests\Stubs\ContentExtension\StubVersionedExtendedThing;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Models\VersionSet;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The force delete of a content force-deletes its mandatory extender from `ContentExtensionObserver`. The extender is
 * a root of its own: its snapshot is a set of its own, which the removal of the content's history leaves alone (D43).
 */
beforeEach(function (): void {
    setupCMSEntities([EntityType::Contents]);

    Schema::create(StubVersionedExtendedThing::TABLE, function ($table): void {
        $table->id();
        $table->foreignId('content_id')->unique();
        $table->string('brand')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    app(ContentExtenderRegistry::class)->register('cms.stub_versioned', StubVersionedExtendedThing::class);
});

it('writes the snapshot of the extender deleted with its content in a set of its own', function (): void {
    $extender = new StubVersionedExtendedThing(['brand' => 'Acme']);
    $extender->setTempContent(Content::factory()->make());
    $extender->save();
    $content = Content::withExtended()->findOrFail($extender->content_id);

    $content->forceDelete();

    $set = VersionSet::query()
        ->where('root_type', $extender->getMorphClass())
        ->where('root_id', (string) $extender->getKey())
        ->sole();
    $row = $set->versions()->sole();

    expect($row->change_type)->toBe(VersionChangeType::Deleted)
        ->and($row->relation_path)->toBeNull()
        ->and($row->original_contents)->toBe(['brand' => 'Acme'])
        ->and(StubVersionedExtendedThing::withTrashed()->find($extender->getKey()))->toBeNull();
});
