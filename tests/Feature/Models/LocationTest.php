<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Modules\CMS\Models\Location;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Services\Crud\QueryBuilder;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->location = Location::factory()->create();
});

it('can be created with factory', function (): void {
    expect($this->location)->toBeInstanceOf(Location::class);
    expect($this->location->id)->not->toBeNull();
    expect($this->location->geolocation)->toBeInstanceOf(Point::class);
});

it('has fillable attributes', function (): void {
    $location = Location::factory()->create([
        'name' => 'Milan',
        'address' => 'Piazza del Duomo, Milan, Italy',
        'city' => 'Milan',
        'country' => 'Italy',
        'geolocation' => new Point(45.4642, 9.1900),
    ]);

    expect($location->name)->toBe('Milan');
    expect($location->address)->toBe('Piazza del Duomo, Milan, Italy');
    expect($location->city)->toBe('Milan');
    expect($location->country)->toBe('Italy');
    expect($location->latitude)->toBeFloat();
    expect($location->longitude)->toBeFloat();
});

it('has latitude and longitude accessors from geolocation', function (): void {
    $location = Location::factory()->create([
        'geolocation' => new Point(45.4642, 9.1900),
    ]);

    expect($location->latitude)->toBe(45.4642);
    expect($location->longitude)->toBe(9.1900);
});

it('has slug trait', function (): void {
    expect(method_exists($this->location, 'generateSlug'))->toBeTrue();
});

it('has tags trait', function (): void {
    expect(method_exists($this->location, 'attachTags'))->toBeTrue();
    expect(method_exists($this->location, 'detachTags'))->toBeTrue();
});

it('has searchable trait', function (): void {
    expect(method_exists($this->location, 'toSearchableArray'))->toBeTrue();
});

it('has soft deletes trait', function (): void {
    $this->location->delete();

    expect($this->location->trashed())->toBeTrue();
    expect(Location::withTrashed()->find($this->location->id))->not->toBeNull();
});

it('has validations trait', function (): void {
    expect(method_exists($this->location, 'getRules'))->toBeTrue();
});

it('can be created with specific attributes', function (): void {
    $location = Location::factory()->create([
        'name' => 'Rome',
        'address' => 'Colosseum, Rome, Italy',
        'city' => 'Rome',
        'country' => 'Italy',
        'geolocation' => new Point(41.9028, 12.4964),
    ]);

    expect($location->name)->toBe('Rome');
    expect($location->city)->toBe('Rome');
    expect($location->country)->toBe('Italy');
    expect($location->latitude)->toBe(41.9028);
    expect($location->longitude)->toBe(12.4964);
});

it('can be found by name', function (): void {
    $location = Location::factory()->create(['name' => 'Unique Location']);

    $foundLocation = Location::query()->where('name', 'Unique Location')->first();

    expect($foundLocation->id)->toBe($location->id);
});

it('can be found by city', function (): void {
    $location = Location::factory()->create(['city' => 'Unique City']);

    $foundLocation = Location::query()->whereHas('place', static fn ($q) => $q->where('city', 'Unique City'))->first();

    expect($foundLocation->id)->toBe($location->id);
});

it('can be found by country', function (): void {
    $location = Location::factory()->create(['country' => 'Unique Country']);

    $foundLocation = Location::query()->whereHas('place', static fn ($q) => $q->where('country', 'Unique Country'))->first();

    expect($foundLocation->id)->toBe($location->id);
});

it('has proper timestamps', function (): void {
    expect($this->location->created_at)->not->toBeNull();
    expect($this->location->updated_at)->not->toBeNull();
});

it('can be serialized to array', function (): void {
    $location = Location::factory()->create([
        'name' => 'Test Location',
        'city' => 'Test City',
    ]);
    $locationArray = $location->toArray();

    expect($locationArray)->toHaveKey('id');
    expect($locationArray)->toHaveKey('name');
    expect($locationArray)->toHaveKey('city');
    expect($locationArray['name'])->toBe('Test Location');
    expect($locationArray['city'])->toBe('Test City');
});

it('can be restored after soft delete', function (): void {
    $location = Location::factory()->create();
    $location->delete();

    expect($location->trashed())->toBeTrue();

    $location->restore();

    expect($location->trashed())->toBeFalse();
});

it('can be permanently deleted', function (): void {
    $location = Location::factory()->create();
    $locationId = $location->id;

    $location->forceDelete();

    expect(Location::withTrashed()->find($locationId))->toBeNull();
});

it('has spatial methods available', function (): void {
    expect($this->location->geolocation)->toBeInstanceOf(Point::class);
});

it('eager-loads place by default', function (): void {
    $id = Location::factory()->create()->id;

    $location = Location::query()->findOrFail($id);

    expect($location->relationLoaded('place'))->toBeTrue();
});

it('caches place on the model after the first bridged read', function (): void {
    $id = Location::factory()->create([
        'address' => 'Via Roma 1',
        'city' => 'Rome',
    ])->id;

    $location = Location::query()->without(['place'])->findOrFail($id);

    expect($location->relationLoaded('place'))->toBeFalse();

    $place_queries = 0;
    DB::listen(static function (Illuminate\Database\Events\QueryExecuted $query) use (&$place_queries): void {
        if (str_contains($query->sql, CoreTables::Places->value)) {
            $place_queries++;
        }
    });

    expect($location->address)->toBe('Via Roma 1');
    expect($location->city)->toBe('Rome');
    expect($location->relationLoaded('place'))->toBeTrue();
    expect($place_queries)->toBe(1);
});

it('list prepareQuery eager-loads place for Location', function (): void {
    $ref = new ReflectionClass(ListRequestData::class);

    /** @var ListRequestData $data */
    $data = $ref->newInstanceWithoutConstructor();
    $set = static function (object $obj, string $prop, mixed $value): void {
        $p = new ReflectionProperty($obj, $prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    };
    $set($data, 'columns', []);
    $set($data, 'relations', []);
    $set($data, 'sort', []);
    $set($data, 'filters', null);

    $query = Location::query();
    (new QueryBuilder())->prepareQuery($query, $data);

    expect($query->getEagerLoads())->toHaveKey('place');
});
