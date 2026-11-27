<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Locations\Schemas;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use MatanYadaev\EloquentSpatial\Objects\Point;
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
                // Typed as "latitude, longitude" and written as a Point only when it changed: rewriting an unchanged
                // point would only cost a write, and clearing the field clears the coordinates.
                TextInput::make('geolocation')
                    ->rules([
                        static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if ($value === null || $value === '') {
                                return;
                            }

                            if (! is_string($value) || ! self::pointFromFormState($value) instanceof Point) {
                                $fail(__('cms.messages.geolocationInvalid'));
                            }
                        },
                    ])
                    ->dehydrated(static fn (?Location $record, ?string $state): bool => self::geolocationChanged($record, $state))
                    ->dehydrateStateUsing(static fn (?string $state): ?Point => self::pointFromFormState($state)),
                // Lock state is coordination metadata, not editable data: it is taken and
                // released through the lock actions, never by typing. `locked_user_id` used to
                // be rendered as a date picker because the column was wrongly declared a
                // timestamp, and `is_locked` is now computed rather than stored.
            ]);
    }

    /**
     * Map a Location to the location form's geographic fields, dropping empty values.
     * Geolocation is flattened to a "lat, lng" string for the text input. Shared by the
     * geocode action (a non-persisted Location) and by the edit page, which fills the
     * fields from the related Place because they are not columns of the location row.
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
     * Read the "latitude, longitude" text of the geolocation field. Empty, malformed or out-of-range text gives null.
     */
    public static function pointFromFormState(?string $state): ?Point
    {
        if ($state === null || preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $state, $matches) !== 1) {
            return null;
        }

        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];

        if (abs($latitude) > 90.0 || abs($longitude) > 180.0) {
            return null;
        }

        return new Point($latitude, $longitude);
    }

    /**
     * Whether the geolocation typed in the form differs from the point the location holds.
     */
    private static function geolocationChanged(?Location $record, ?string $state): bool
    {
        $typed = self::pointFromFormState($state);
        $stored = $record?->geolocation;

        if (! $typed instanceof Point || ! $stored instanceof Point) {
            return $typed instanceof Point || $stored instanceof Point;
        }

        return $typed->latitude !== $stored->latitude || $typed->longitude !== $stored->longitude;
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
