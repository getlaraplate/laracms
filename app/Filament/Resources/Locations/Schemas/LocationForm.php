<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Locations\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Modules\CMS\Actions\Locations\GeocodeLocationAction;
use Modules\CMS\Models\Location;
use Modules\Core\Filament\Utils\HasForm;

final class LocationForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        self::configureForm($schema);

        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('address')
                    ->suffixAction(self::geocodeAction()),
                TextInput::make('city'),
                TextInput::make('province'),
                TextInput::make('country')
                    ->required(),
                TextInput::make('postcode'),
                TextInput::make('zone'),
                TextInput::make('geolocation'),
                // Lock state is coordination metadata, not editable data: it is taken and
                // released through the lock actions, never by typing. `locked_user_id` used to
                // be rendered as a date picker because the column was wrongly declared a
                // timestamp, and `is_locked` is now computed rather than stored.
            ]);
    }

    /**
     * Map a geocoded (non-persisted) Location to the location form's geographic fields,
     * dropping empty values. Geolocation is flattened to a "lat, lng" string for the
     * text input. Shared by the Filament action and covered by its own test.
     *
     * @return array<string, string>
     */
    public static function geocodedFormState(Location $location): array
    {
        $state = [];

        foreach (['address', 'city', 'province', 'country', 'postcode', 'zone'] as $field) {
            $value = $location->getAttribute($field);

            if ($value !== null && $value !== '') {
                $state[$field] = (string) $value;
            }
        }

        $point = $location->geolocation;

        if ($point !== null) {
            $state['geolocation'] = sprintf('%s, %s', $point->latitude, $point->longitude);
        }

        return $state;
    }

    /**
     * A geocode affordance on the address field: resolves the geographic fields from
     * the current name/address/city/province/country input and fills them into the form.
     * Uses the same {@see GeocodeLocationAction} as the SPA form and the geocode endpoint.
     */
    private static function geocodeAction(): Action
    {
        return Action::make('geocode')
            ->icon('heroicon-m-map-pin')
            ->label(__('Geocode'))
            ->action(static function (Get $get, Set $set): void {
                $parts = array_values(array_filter([
                    $get('address'),
                    $get('name'),
                    $get('city'),
                    $get('country'),
                ], static fn (mixed $value): bool => is_string($value) && mb_trim($value) !== ''));

                $query = implode(', ', $parts);

                if (mb_strlen($query) < 3) {
                    return;
                }

                $result = app(GeocodeLocationAction::class)(
                    $query,
                    is_string($get('city')) ? $get('city') : null,
                    is_string($get('province')) ? $get('province') : null,
                    is_string($get('country')) ? $get('country') : null,
                );

                $location = is_array($result) ? ($result[0] ?? null) : $result;

                if (! $location instanceof Location) {
                    return;
                }

                foreach (self::geocodedFormState($location) as $field => $value) {
                    $set($field, $value);
                }
            });
    }
}
