<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Presets\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\CMS\Filament\Resources\Presets\PresetResource;
use Modules\CMS\Filament\Utils\HasRecords;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Preset;
use Override;

final class ListPresets extends ListRecords
{
    use HasRecords;

    #[Override]
    protected static string $resource = PresetResource::class;

    public function getTabs(): array
    {
        $entities = Entity::query()->get()->keyBy('id');

        $counts_by_entity = [];
        $rows = Preset::query()
            ->selectRaw('entity_id, count(*) as aggregate_count')
            ->whereIn('entity_id', $entities->keys()->all())
            ->groupBy('entity_id')
            ->get();

        foreach ($rows as $row) {
            $entity_id = $row->getAttribute('entity_id');
            $count = $row->getAttribute('aggregate_count');

            if (! is_numeric($entity_id) || ! is_numeric($count)) {
                continue;
            }

            $counts_by_entity[(int) $entity_id] = (int) $count;
        }

        $counts = array_merge(['all' => array_sum($counts_by_entity)], $counts_by_entity);

        if (count($counts) < 2) {
            return [];
        }

        $tabs = [
            'all' => Tab::make(__('app.sections.all'))->badge($counts['all']),
        ];

        $entity_column = (new Preset())->qualifyColumn('entity_id');

        foreach ($counts_by_entity as $entity_id => $count) {
            if ($count === 0) {
                continue;
            }

            $entity = $entities->get($entity_id);

            if (! $entity instanceof Entity) {
                continue;
            }

            $entity_name = Str::studly($entity->name);
            $tabs[(string) $entity_id] = Tab::make($entity_name)
                ->badge($count)
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where($entity_column, $entity_id),
                );
        }

        $this->groups[] = Group::make('entity_id')
            ->label(__('cms.fields.entity'))
            ->getTitleFromRecordUsing(function (Preset $record): string {
                $entity = $record->entity;

                return Str::studly($entity !== null ? $entity->name : '');
            });

        return $tabs;
    }
}
