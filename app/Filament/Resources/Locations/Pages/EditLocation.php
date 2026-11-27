<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Locations\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Modules\CMS\Filament\Resources\Locations\LocationResource;
use Modules\CMS\Filament\Resources\Locations\Schemas\LocationForm;
use Modules\CMS\Models\Location;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Override;

final class EditLocation extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = LocationResource::class;

    /**
     * The geography lives on the related Place, so the record's own attributes do not carry it: without it the form
     * would open empty and saving it would erase the address.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Location) {
            return $data;
        }

        return [...$data, ...LocationForm::geocodedFormState($record)];
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
