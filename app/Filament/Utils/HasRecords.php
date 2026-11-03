<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Utils;

use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Core\Filament\Utils\HasRecords as BaseHasRecords;
use Modules\Core\Models\Concerns\HasDynamicContents;
use Modules\Core\Models\Entity;

trait HasRecords
{
    use BaseHasRecords;

    public function getTabs(): array
    {
        $model = self::getResource()::getModel();

        if ($model === null) {
            return [];
        }

        if (! class_uses_trait($model, HasDynamicContents::class)) {
            return [];
        }

        $entities = $model::fetchAvailableEntities($model::getEntityType());

        if ($entities->count() < 2) {
            return [];
        }

        $cache_key = 'filament_cms_tabs_' . $model . '_' . $entities->pluck('id')->sort()->values()->implode(',');

        /** @var array<int|string, int> $counts */
        $counts = Cache::remember($cache_key, $this->tabsCountsTtl(), fn (): array => $this->fetchEntityTabCounts($model));

        if (count($counts) < 2) {
            return [];
        }

        $tabs = [
            'all' => Tab::make(__('app.sections.all'))->badge($counts['all']),
        ];

        $entity_column = $model::query()->getModel()->getTable() . '.entity_id';

        foreach ($entities as $entity) {
            if (! $entity instanceof Entity) {
                continue;
            }

            $entity_id = is_numeric($entity->id) ? (int) $entity->id : null;

            if ($entity_id === null) {
                continue;
            }

            $totals = $counts[$entity_id] ?? 0;

            if ($totals === 0) {
                continue;
            }

            $tabs[$entity->name] = Tab::make($entity->name)
                ->badge($totals)
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->whereRaw($entity_column . ' = ?', [$entity_id]),
                );
        }

        $this->groups[] = Group::make('presettable.preset_id')
            ->label(__('cms.fields.preset'))
            ->getTitleFromRecordUsing(fn (Model $record): string => $this->presetGroupTitle($record));

        return $tabs;
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<int|string, int>
     */
    private function fetchEntityTabCounts(string $model): array
    {
        $rows = $model::query()
            ->withoutGlobalScopes(['global_ordered'])
            ->withoutEagerLoads()
            ->selectRaw('entity_id, count(*) as aggregate_count')
            ->groupBy('entity_id')
            ->pluck('aggregate_count', 'entity_id');

        $counts_by_entity = [];

        foreach ($rows as $entity_id => $count) {
            if (! is_numeric($entity_id) || ! is_numeric($count)) {
                continue;
            }

            $counts_by_entity[(int) $entity_id] = (int) $count;
        }

        return ['all' => array_sum($counts_by_entity)] + $counts_by_entity;
    }

    private function tabsCountsTtl(): int
    {
        $ttl = config('core.filament.tabs_counts_ttl_seconds', 300);

        if (is_int($ttl)) {
            return $ttl;
        }

        if (is_numeric($ttl)) {
            return (int) $ttl;
        }

        return 300;
    }

    private function presetGroupTitle(Model $record): string
    {
        $preset = $record->getAttribute('preset');

        if (! is_object($preset) || ! property_exists($preset, 'name')) {
            return '';
        }

        $name = $preset->name;

        return Str::studly(is_string($name) ? $name : '');
    }
}
