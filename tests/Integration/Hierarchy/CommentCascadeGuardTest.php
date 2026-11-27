<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Support\Hierarchy\OwnerCascadeRegistry;
use Modules\Core\Tests\Support\CascadingForeignKeys;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Every chain of cascading foreign keys into the comments, reviewed and handled by a registered owner cascade. A new
 * chain, even between owner tables already registered, fails here until its cascade covers it.
 */
it('handles every chain of foreign keys that deletes a comment by cascade', function (): void {
    expect(CascadingForeignKeys::pathsInto(CMSTables::Comments->value))->toBe([
        'cms_contents -> cms_comments.content_id',
        'core_entities -> cms_contents.entity_id -> cms_comments.content_id',
        'core_entities -> core_presets.entity_id -> core_presettables.entity_id,preset_id -> cms_contents.presettable_id -> cms_comments.content_id',
        'core_presets -> core_presettables.entity_id,preset_id -> cms_contents.presettable_id -> cms_comments.content_id',
        'core_presettables -> cms_contents.presettable_id -> cms_comments.content_id',
        'core_templates -> core_presets.template_id -> core_presettables.entity_id,preset_id -> cms_contents.presettable_id -> cms_comments.content_id',
        'users -> cms_comments.user_id',
    ])->and(app(OwnerCascadeRegistry::class)->ownerTablesOf(CMSTables::Comments->value))
        ->toBe(CascadingForeignKeys::ownerTablesOf(CMSTables::Comments->value));
});
