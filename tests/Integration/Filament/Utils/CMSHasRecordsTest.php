<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Preset;
use Modules\CMS\Tests\TestCase;
use Modules\CMS\Tests\Unit\Filament\Utils\CMSHasRecordsEntityHarness;
use Modules\CMS\Tests\Unit\Filament\Utils\CMSHasRecordsTraitHarness;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    if (class_exists(Modules\Core\Services\DynamicContentsService::class)) {
        Modules\Core\Services\DynamicContentsService::reset();
    }

    Cache::flush();
});

function createSecondContentsEntityWithPreset(): Entity
{
    $secondary_name = 'contents_secondary_' . uniqid();
    $secondary = Entity::query()->create([
        'name' => $secondary_name,
        'type' => EntityType::Contents,
        'slug' => Str::slug($secondary_name),
    ]);

    $secondaryPreset = Preset::query()->firstOrCreate(
        ['entity_id' => $secondary->id, 'name' => 'default'],
        ['entity_id' => $secondary->id, 'name' => 'default'],
    );

    Presettable::query()->firstOrCreate(
        ['entity_id' => $secondary->id, 'preset_id' => $secondaryPreset->id],
        ['entity_id' => $secondary->id, 'preset_id' => $secondaryPreset->id],
    );

    return $secondary;
}

it('returns filament tabs with badges when multiple content entities exist and counts are present', function (): void {
    setupCMSEntities([EntityType::Contents]);

    $secondaryEntity = createSecondContentsEntityWithPreset();

    if (class_exists(Modules\Core\Services\DynamicContentsService::class)) {
        Modules\Core\Services\DynamicContentsService::reset();
    }

    $primaryPresettable = Presettable::query()
        ->where('entity_id', Entity::query()->where('name', 'contents')->where('type', EntityType::Contents)->value('id'))
        ->firstOrFail();
    $secondaryPresettable = Presettable::query()->where('entity_id', $secondaryEntity->id)->firstOrFail();

    $model = Content::class;
    $entities = $model::fetchAvailableEntities(EntityType::Contents);
    $cache_key = 'filament_cms_tabs_' . $model . '_' . $entities->pluck('id')->sort()->values()->implode(',');
    Cache::put($cache_key, [
        'all' => 2,
        (int) $primaryPresettable->entity_id => 1,
        (int) $secondaryPresettable->entity_id => 1,
    ], config('core.filament.tabs_counts_ttl_seconds'));

    $tabs = (new CMSHasRecordsTraitHarness)->getTabs();

    expect($tabs)->not->toBeEmpty()
        ->and($tabs)->toHaveKey('all');
});

it('shows a tab for each entity holding contents, with its own count and the total on the all tab', function (): void {
    setupCMSEntities([EntityType::Contents]);

    $primary = Entity::query()->where('name', 'contents')->where('type', EntityType::Contents)->firstOrFail();
    $contents = Content::factory()->count(3)->create();

    $secondary = createSecondContentsEntityWithPreset();

    if (class_exists(Modules\Core\Services\DynamicContentsService::class)) {
        Modules\Core\Services\DynamicContentsService::reset();
    }

    Content::query()->withoutGlobalScopes()->whereKey($contents->last()->id)->update(['entity_id' => $secondary->id]);

    $tabs = (new CMSHasRecordsTraitHarness)->getTabs();

    expect($tabs)->toHaveKeys(['all', $primary->name, $secondary->name])
        ->and((int) $tabs['all']->getBadge())->toBe(3)
        ->and((int) $tabs[$primary->name]->getBadge())->toBe(2)
        ->and((int) $tabs[$secondary->name]->getBadge())->toBe(1);
});

it('returns an empty tab list when fewer than two entities are available', function (): void {
    setupCMSEntities([EntityType::Contents]);

    $tabs = (new CMSHasRecordsTraitHarness)->getTabs();

    expect($tabs)->toBeArray()->toBeEmpty();
});

it('returns no tabs when the resource model does not use dynamic contents', function (): void {
    $tabs = (new CMSHasRecordsEntityHarness)->getTabs();

    expect($tabs)->toBeArray()->toBeEmpty();
});

it('aggregates entity tab counts without hydrating eager-loaded presettable relations', function (): void {
    $contributor = new Contributor;

    if (! $contributor->getConnection()->getSchemaBuilder()->hasColumn($contributor->getTable(), 'shared_components')) {
        test()->markTestSkipped('Contributor dynamic contents require full Core runtime.');
    }

    setupCMSEntities([EntityType::Contributors]);

    $contributor = Contributor::factory()->create();
    $contributor->setTranslation(app()->getLocale(), ['slug' => 'sample-contributor', 'components' => []]);
    $contributor->save();

    $method = new ReflectionMethod(CMSHasRecordsTraitHarness::class, 'fetchEntityTabCounts');
    $method->setAccessible(true);

    $counts = $method->invoke(new CMSHasRecordsTraitHarness, Contributor::class);

    expect($counts)->toHaveKey('all')
        ->and($counts['all'])->toBeGreaterThan(0);
});
