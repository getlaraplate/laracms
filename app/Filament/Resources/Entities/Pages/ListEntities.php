<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Entities\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Filament\Resources\Entities\EntityResource;
use Modules\CMS\Filament\Utils\HasRecords;
use Modules\CMS\Models\Entity;
use Override;

final class ListEntities extends ListRecords
{
    use HasRecords;

    #[Override]
    protected static string $resource = EntityResource::class;

    public function getTabs(): array
    {
        $counts_by_type = [];
        $rows = Entity::query()
            ->select('type')
            ->selectRaw('count(*) as aggregate_count')
            ->groupBy('type')
            ->get();

        foreach ($rows as $row) {
            $type = $row->getAttribute('type');
            $count = $row->getAttribute('aggregate_count');

            if (! is_string($type) || ! is_numeric($count)) {
                continue;
            }

            $counts_by_type[$type] = (int) $count;
        }

        $counts = array_merge(['all' => array_sum($counts_by_type)], $counts_by_type);

        if (count($counts) < 2) {
            return [];
        }

        $tabs = [
            'all' => Tab::make(__('app.sections.all'))->badge($counts['all']),
        ];

        $type_column = (new Entity())->qualifyColumn('type');

        foreach (EntityType::cases() as $type) {
            $totals = $counts_by_type[$type->value] ?? 0;

            if ($totals === 0) {
                continue;
            }

            $label = Str::studly($type->value);

            $tabs[$type->value] = Tab::make($label)
                ->badge($totals)
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where($type_column, $type->toScalar()),
                );
        }

        $this->groups[] = Group::make('type')
            ->label(__('cms.fields.type'))
            ->getTitleFromRecordUsing(fn (Entity $record): string => Str::studly($record->type->toScalar()));

        return $tabs;
    }
}
