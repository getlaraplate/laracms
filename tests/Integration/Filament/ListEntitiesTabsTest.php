<?php

declare(strict_types=1);

use Modules\CMS\Filament\Resources\Entities\Pages\ListEntities;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

uses(TestCase::class);

it('counts the entities of each type through the builder, which quotes the reserved type column on Db2', function (): void {
    $sql = Db2PretendConnection::capture(static function (): void {
        expect((new ListEntities)->getTabs())->toBe([]);
    });

    expect($sql)->toHaveCount(1)
        ->and($sql[0])->toStartWith('select "TYPE", count(*) as aggregate_count from core_entities')
        ->and($sql[0])->toContain('group by "TYPE"');
});
