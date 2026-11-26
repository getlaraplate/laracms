<?php

declare(strict_types=1);

use MatanYadaev\EloquentSpatial\Objects\Point;
use Modules\CMS\Models\Location;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Models\Place;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

uses(TestCase::class);

it('never writes geolocation when a location point is set on Db2', function (): void {
    $sql = Db2PretendConnection::capture(static function (): void {
        $place = new Place(['city' => 'Milano']);
        $place->exists = true;
        $place->setAttribute('id', 7);
        $place->syncOriginal();

        $location = new Location();
        $location->setAttribute('place_id', 7);
        $location->setRelation('place', $place);

        Place::withoutEvents(static fn (): mixed => $location->setAttribute('geolocation', new Point(45.4, 9.1)));

        expect($place->latitude)->toBe(45.4)
            ->and($place->longitude)->toBe(9.1);
    });

    expect($sql)->not->toBeEmpty()
        ->and(mb_strtolower(implode("\n", $sql)))->not->toContain('geolocation');
});
