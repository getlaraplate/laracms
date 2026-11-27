<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Modules\CMS\Filament\Resources\Locations\Pages\EditLocation;
use Modules\CMS\Filament\Resources\Locations\Schemas\LocationForm;
use Modules\CMS\Models\Location;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Tests\Support\HttpContext;

uses(TestCase::class, RefreshDatabase::class);

function locationStoredOnPlace(): Location
{
    return Location::factory()->create([
        'address' => 'Via Roma 1',
        'city' => 'Milano',
        'province' => 'MI',
        'country' => 'IT',
        'postcode' => '20100',
        'zone' => 'Centro',
        'latitude' => 45.4642,
        'longitude' => 9.19,
    ]);
}

it('fills the edit form with the geography stored on the place', function (): void {
    $location = locationStoredOnPlace();
    HttpContext::panelActorWithoutApproval(new Location, ['select', 'update']);

    Livewire::test(EditLocation::class, ['record' => $location->getKey()])
        ->assertSchemaStateSet([
            'address' => 'Via Roma 1',
            'city' => 'Milano',
            'province' => 'MI',
            'country' => 'IT',
            'postcode' => '20100',
            'zone' => 'Centro',
            'geolocation' => '45.4642, 9.19',
        ]);
});

it('keeps the address and the coordinates when the edit form is saved unchanged', function (): void {
    $location = locationStoredOnPlace();
    HttpContext::panelActorWithoutApproval(new Location, ['select', 'update']);

    Livewire::test(EditLocation::class, ['record' => $location->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = Location::query()->findOrFail($location->getKey());

    expect($fresh->address)->toBe('Via Roma 1')
        ->and($fresh->city)->toBe('Milano')
        ->and($fresh->country)->toBe('IT')
        ->and($fresh->latitude)->toBe(45.4642)
        ->and($fresh->longitude)->toBe(9.19);
});

it('saves the geolocation only when it changed, as a point or as null', function (string $typed, bool $sent, ?array $expected): void {
    $location = locationStoredOnPlace();
    HttpContext::panelActorWithoutApproval(new Location, ['select', 'update']);

    $state = Livewire::test(EditLocation::class, ['record' => $location->getKey()])
        ->fillForm(['geolocation' => $typed])
        ->instance()
        ->form
        ->getState();

    if (! $sent) {
        expect($state)->not->toHaveKey('geolocation');

        return;
    }

    expect($state)->toHaveKey('geolocation');

    if ($expected === null) {
        expect($state['geolocation'])->toBeNull();

        return;
    }

    expect($state['geolocation'])->toBeInstanceOf(Point::class)
        ->and($state['geolocation']->latitude)->toBe($expected[0])
        ->and($state['geolocation']->longitude)->toBe($expected[1]);
})->with([
    'unchanged' => ['45.4642, 9.19', false, null],
    'unchanged, typed differently' => ['45.46420,9.19', false, null],
    'new point' => ['41.9028, 12.4964', true, [41.9028, 12.4964]],
    'cleared' => ['', true, null],
]);

it('rejects a geolocation that is not a latitude and longitude pair', function (string $geolocation): void {
    $location = locationStoredOnPlace();
    HttpContext::panelActorWithoutApproval(new Location, ['select', 'update']);

    Livewire::test(EditLocation::class, ['record' => $location->getKey()])
        ->fillForm(['geolocation' => $geolocation])
        ->call('save')
        ->assertHasFormErrors(['geolocation']);
})->with([
    'text' => 'somewhere',
    'one number' => '45.4642',
    'latitude out of range' => '95, 9.19',
    'longitude out of range' => '45.4642, 190',
]);

it('reads a point from the latitude and longitude typed in the form', function (?string $state, ?array $expected): void {
    $point = LocationForm::pointFromFormState($state);

    if ($expected === null) {
        expect($point)->toBeNull();

        return;
    }

    expect($point)->toBeInstanceOf(Point::class)
        ->and($point->latitude)->toBe($expected[0])
        ->and($point->longitude)->toBe($expected[1]);
})->with([
    'pair' => ['45.4642, 9.19', [45.4642, 9.19]],
    'no space, negative' => ['-33.8688,-151.2093', [-33.8688, -151.2093]],
    'integers' => ['45, 9', [45.0, 9.0]],
    'empty' => ['', null],
    'null' => [null, null],
    'text' => ['somewhere', null],
    'out of range' => ['95, 9.19', null],
]);
