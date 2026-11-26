<?php

declare(strict_types=1);

use Modules\CMS\Filament\Resources\Tags\Tables\TagsTable;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

uses(TestCase::class);

it('quotes the reserved locale column in the order of the shown slug on Db2', function (): void {
    $sql = '';

    Db2PretendConnection::capture(static function () use (&$sql): void {
        $sql = (new ReflectionMethod(TagsTable::class, 'shownSlug'))->invoke(null)->toSql();
    });

    expect($sql)->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 ELSE 1 END');
});
