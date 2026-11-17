<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Modules\CMS\Models\Content;
use Modules\CMS\Services\ContentExtenderRegistry;
use Modules\CMS\Tests\Stubs\ContentExtension\StubExtendedThing;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Search\Extensions\ExtensionRegistry;
use Modules\Core\Search\Schema\FieldType;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // getSearchMapping() only supports the concrete engines; the default test engine is Scout's
    // CollectionEngine, so pin the database engine for the mapping assertion.
    Config::set('scout.driver', 'database');
});

it('composes the extension mapping from registered extenders', function (): void {
    app(ContentExtenderRegistry::class)->register('cms.stub_extended', StubExtendedThing::class);

    $mapping = json_encode((new Content())->getSearchMapping());

    expect($mapping)->toContain('extended_type')
        ->and($mapping)->toContain('extension')
        ->and($mapping)->toContain('brand')
        ->and($mapping)->toContain('sku');
});

it('maps extended_type but no extension object when no extender is registered', function (): void {
    // Isolate from any production extender registered at boot (e.g. shop.product) so this exercises
    // the genuinely-empty-registry case.
    app()->instance(ExtensionRegistry::class, new ExtensionRegistry());
    app()->instance(ContentExtenderRegistry::class, new ContentExtenderRegistry(app(ExtensionRegistry::class)));

    $mapping = json_encode((new Content())->getSearchMapping());

    expect($mapping)->toContain('extended_type')
        ->and($mapping)->not->toContain('"extension"');
});

it('composes the extension mapping with every extender registered in the application', function (): void {
    // XS16 of spec 2026-11-17-extended-entity-search-design.md: extenders of Content share an `extension` field only
    // with one definition, and a conflict fails when the mapping is composed. Composing it here, with whatever the
    // enabled modules registered at boot (shop.product among them), makes a conflict fail before it ships.
    $mapping = (new Content())->extensionSearchMapping();

    expect($mapping === [] || $mapping['type'] === ['type' => FieldType::Keyword, 'filterable' => true])->toBeTrue();
});
