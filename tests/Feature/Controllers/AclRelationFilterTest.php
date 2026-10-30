<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentReference;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Media;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * The published-and-valid-by-date condition of a content, as the guest ACL of the CMS seeder states it.
 */
function aclrel_published_content(): FiltersGroup
{
    return new FiltersGroup(
        filters: [
            new Filter('valid_from', '@now', FilterOperator::LessEquals),
            new FiltersGroup(
                filters: [
                    new Filter('valid_to', '@now', FilterOperator::GreatEquals),
                    new Filter('valid_to', null, FilterOperator::Equals),
                ],
                operator: WhereClause::Or,
            ),
        ],
        operator: WhereClause::And,
    );
}

/**
 * A user whose `api` role holds the select permission of every given model, each narrowed by its ACL
 * (a null ACL leaves the permission unrestricted).
 *
 * @param  array<class-string<Illuminate\Database\Eloquent\Model>, FiltersGroup|null>  $grants
 */
function aclrel_reader(array $grants): App\Models\User
{
    $role = Role::factory()->create(['name' => 'aclrel_cms_reader_' . uniqid(), 'guard_name' => 'api']);

    foreach ($grants as $model_class => $filters) {
        $name = PermissionName::forClass($model_class, 'select');
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        $role->givePermissionTo($permission);

        if ($filters instanceof FiltersGroup) {
            $acl = new ACL;
            $acl->setSkipValidation(true);
            $acl->forceFill([
                'permission_id' => $permission->id,
                'role_id' => $role->id,
                'filters' => $filters,
                'unrestricted' => false,
                'priority' => 10,
                'is_active' => true,
            ]);
            $acl->save();
        }
    }

    $user = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * A media owned by a content.
 */
function aclrel_content_media(Content $content): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => 'cover',
        'name' => 'cover',
        'file_name' => 'cover.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 10,
        'model_type' => $content->getMorphClass(),
        'model_id' => $content->getKey(),
        'custom_properties' => [],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

/**
 * @return list<int>
 */
function aclrel_ids(Illuminate\Testing\TestResponse $response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('keeps the references of the published contents only, through a BelongsTo relation filter', function (): void {
    $live = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $draft = Content::factory()->create(['valid_from' => now()->addWeek(), 'valid_to' => null]);
    $expired = Content::factory()->create(['valid_from' => now()->subMonth(), 'valid_to' => now()->subDay()]);

    $of_live = ContentReference::factory()->create(['content_id' => $live->id]);
    ContentReference::factory()->create(['content_id' => $draft->id]);
    ContentReference::factory()->create(['content_id' => $expired->id]);

    $reader = aclrel_reader([
        ContentReference::class => new FiltersGroup([new RelationFilter('content', aclrel_published_content())]),
    ]);

    $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/content_reference');

    $response->assertOk();
    expect(aclrel_ids($response))->toBe([$of_live->id]);
});

it('keeps the media of the published contents only, through a MorphTo relation filter with its morph type', function (): void {
    $live = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $draft = Content::factory()->create(['valid_from' => now()->addWeek(), 'valid_to' => null]);

    $of_live = aclrel_content_media($live);
    aclrel_content_media($draft);
    $ticket_attachment = new Media;
    $ticket_attachment->forceFill([
        'collection_name' => 'attachments',
        'name' => 'a',
        'file_name' => 'a.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 1,
        'model_type' => 'Modules\\SAO\\Models\\Ticket',
        'model_id' => $live->id,
        'custom_properties' => [],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $ticket_attachment->saveQuietly();

    $reader = aclrel_reader([
        Media::class => new FiltersGroup([new RelationFilter('model', aclrel_published_content(), [Content::class])]),
    ]);

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    $response->assertOk();
    expect(aclrel_ids($response))->toBe([$of_live->id]);
});

it('limits the media of a content to one collection beside the owner condition', function (): void {
    $live = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $cover = aclrel_content_media($live);
    $attachment = aclrel_content_media($live);
    $attachment->forceFill(['collection_name' => 'attachments'])->saveQuietly();

    $reader = aclrel_reader([
        Media::class => new FiltersGroup([
            new Filter('collection_name', 'cover', FilterOperator::Equals),
            new RelationFilter('model', aclrel_published_content(), [Content::class]),
        ]),
    ]);

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    $response->assertOk();
    expect(aclrel_ids($response))->toBe([$cover->id]);
});

it('gives contents and refuses the media endpoint to a role that holds no media permission', function (): void {
    $live = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    aclrel_content_media($live);

    $reader = aclrel_reader([Content::class => aclrel_published_content()]);

    $contents = $this->actingAs($reader)->getJson('/api/v1/select/cms/contents');
    $media = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    $contents->assertOk();
    expect(aclrel_ids($contents))->toBe([$live->id]);
    $media->assertForbidden();
});
