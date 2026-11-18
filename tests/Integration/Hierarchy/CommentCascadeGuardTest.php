<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Support\Hierarchy\OwnerCascadeRegistry;
use Modules\Core\Tests\Support\CascadingForeignKeys;

uses(TestCase::class, RefreshDatabase::class);

it('handles every foreign key that deletes a comment by cascade', function (): void {
    expect(CascadingForeignKeys::directOf(CMSTables::Comments->value))->toBe(['cms_comments.content_id', 'cms_comments.user_id']);
});

it('registers an owner cascade for every table whose delete reaches the comments', function (): void {
    $owners = CascadingForeignKeys::ownerTablesOf(CMSTables::Comments->value);

    expect(app(OwnerCascadeRegistry::class)->ownerTablesOf(CMSTables::Comments->value))->toBe($owners);
});
