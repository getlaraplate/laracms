<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentRating;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Media;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * An anonymous caller of `/api` never receives the personal attributes a model declares (the author of a comment or
 * rating, the contact data of a contributor), and receives media in the safe projection, as an entity, as a relation
 * and as the `cover` of a content. Staff on `/app` see the records as before.
 */

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-10-09 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents, EntityType::Contributors]);

    Artisan::call('permission:refresh', ['--quiet' => true]);
    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function hiddenattrs_content(): Content
{
    return Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
}

/**
 * A media of the content with the descriptive values the projection exposes and the ones it must not.
 */
function hiddenattrs_media(Content $content, string $collection): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => $collection,
        'name' => $collection,
        'file_name' => $collection . '-original-' . uniqid() . '.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 10,
        'model_type' => $content->getMorphClass(),
        'model_id' => $content->getKey(),
        'custom_properties' => ['alt_text' => 'Alt of ' . $collection, 'content_hash' => 'secret-hash', 'metadata' => ['width' => 640, 'height' => 480]],
        'manipulations' => [],
        'generated_conversions' => ['thumb-low' => true],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media->fresh();
}

/**
 * A staff user holding the seeded admin role of the `web` guard, which reads every entity of the app.
 */
function hiddenattrs_staff(): User
{
    $user = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
    $user->assignRole(Role::query()->where(['name' => config('permission.roles.admin'), 'guard_name' => 'web'])->firstOrFail());

    return $user->fresh();
}

/**
 * The contact keys the contributor presets keep in `shared_components`.
 *
 * @return array<string, string>
 */
function hiddenattrs_contact_data(): array
{
    return ['public_email' => 'jane.contributor@example.test', 'phone' => '+39 055 000 0000', 'website' => 'https://jane.example.test'];
}

/**
 * A contributor of the seeded `contributor` entity (whose preset has the contact fields), with a bridged user and
 * the contact data stored where the factory stores non-translatable preset fields.
 */
function hiddenattrs_contributor(): Contributor
{
    $entity = Entity::query()->where('name', 'contributor')->firstOrFail();
    $presettable = Presettable::query()->where('entity_id', $entity->id)->firstOrFail();
    $user = Modules\Core\Models\User::factory()->create(['name' => 'hiddenattrs_author']);
    $contributor = Contributor::factory()->create(['name' => 'hiddenattrs_contributor', 'user_id' => $user->id]);
    DB::table($contributor->getTable())->where('id', $contributor->id)->update([
        'entity_id' => $entity->id,
        'presettable_id' => $presettable->id,
        'shared_components' => json_encode(hiddenattrs_contact_data()),
    ]);

    return $contributor->fresh();
}

/**
 * @param  array<string, mixed>  $media
 */
function hiddenattrs_assert_projection(array $media, string $collection): void
{
    expect(array_keys($media))->toBe(['id', 'collection_name', 'mime_type', 'size', 'width', 'height', 'alt_text', 'url', 'conversions'])
        ->and($media['collection_name'])->toBe($collection)
        ->and($media['alt_text'])->toBe('Alt of ' . $collection)
        ->and($media['width'])->toBe(640)
        ->and($media['height'])->toBe(480)
        ->and($media['conversions'])->toHaveKey('thumb-low')
        ->and($media)->not->toHaveKeys(['file_name', 'disk', 'conversions_disk', 'custom_properties'])
        ->and(json_encode($media, JSON_THROW_ON_ERROR))->not->toContain('secret-hash');
}

describe('an anonymous caller of the api', function (): void {
    it('does not receive the author of a comment', function (): void {
        $comment = Comment::factory()->create(['content_id' => hiddenattrs_content()->id]);
        expect($comment->user_id)->not->toBeNull();

        $response = $this->getJson('/api/v1/select/cms/comments');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $comment->id);

        expect($row)->not->toBeNull()
            ->and($row)->not->toHaveKey('user_id');
    });

    it('does not receive the author of a rating', function (): void {
        $rating = ContentRating::factory()->create(['content_id' => hiddenattrs_content()->id]);

        $response = $this->getJson('/api/v1/select/cms/content_rating');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $rating->id);

        expect($row)->not->toBeNull()
            ->and($row)->not->toHaveKey('user_id')
            ->and($row['score'])->toBe($rating->score);
    });

    it('does not receive the user of a contributor, nor the contact data its presets keep in shared_components', function (): void {
        $contributor = hiddenattrs_contributor();

        $response = $this->getJson('/api/v1/select/cms/contributors');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $contributor->id);
        $json = json_encode($row, JSON_THROW_ON_ERROR);

        expect($row)->not->toBeNull()
            ->and($row['name'])->toBe('hiddenattrs_contributor')
            ->and($row)->not->toHaveKeys(['user_id', 'user', 'shared_components', 'public_email', 'phone', 'website']);

        foreach (hiddenattrs_contact_data() as $value) {
            expect($json)->not->toContain($value);
        }
    });

    it('does not receive the contributors user_id nor the comment author when they are loaded as relations of a content', function (): void {
        $content = hiddenattrs_content();
        $content->contributors()->attach(hiddenattrs_contributor()->id);
        Comment::factory()->create(['content_id' => $content->id]);

        $response = $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['contributors', 'comments']]));

        $response->assertOk();
        $contributor = $response->json('data.contributors.0');
        $comment = $response->json('data.comments.0');

        expect($contributor)->not->toBeNull()->and($contributor)->not->toHaveKeys(['user_id', 'phone', 'public_email'])
            ->and($comment)->not->toBeNull()->and($comment)->not->toHaveKey('user_id');
    });
});

it('declares the personal attributes of a contributor, with its contact data kept in shared_components (R10)', function (): void {
    expect((new Contributor)->anonymousHiddenAttributes())->toBe(['user_id', 'user', 'shared_components']);
});

describe('staff on the app', function (): void {
    it('receive the author of a comment and of a rating', function (): void {
        $content = hiddenattrs_content();
        $comment = Comment::factory()->create(['content_id' => $content->id]);
        $rating = ContentRating::factory()->create(['content_id' => $content->id]);
        $staff = hiddenattrs_staff();

        $comments = $this->actingAs($staff)->getJson('/app/crud/select/cms/comments');
        $ratings = $this->actingAs($staff)->getJson('/app/crud/select/cms/content_rating');

        expect(collect($comments->json('data'))->firstWhere('id', $comment->id)['user_id'])->toBe($comment->user_id)
            ->and(collect($ratings->json('data'))->firstWhere('id', $rating->id)['user_id'])->toBe($rating->user_id);
    });
});

describe('the media of a content on the api', function (): void {
    it('is a projection as an entity', function (): void {
        $media = hiddenattrs_media(hiddenattrs_content(), 'images');

        $response = $this->getJson('/api/v1/detail/core/media?' . http_build_query(['id' => $media->id]));

        $response->assertOk();
        hiddenattrs_assert_projection($response->json('data'), 'images');
    });

    it('is a projection as a relation', function (): void {
        $content = hiddenattrs_content();
        hiddenattrs_media($content, 'images');

        $response = $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['media']]));

        $response->assertOk();
        hiddenattrs_assert_projection($response->json('data.media.0'), 'images');
    });

    it('is a projection as the cover, which now reaches the content responses', function (): void {
        $content = hiddenattrs_content();
        hiddenattrs_media($content, 'cover');

        $detail = $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id]));
        $list = $this->getJson('/api/v1/select/cms/contents');

        $detail->assertOk();
        $list->assertOk();
        hiddenattrs_assert_projection($detail->json('data.cover'), 'cover');
        hiddenattrs_assert_projection(collect($list->json('data'))->firstWhere('id', $content->id)['cover'], 'cover');
    });

    it('leaves the cover null for a caller who may not read media', function (): void {
        $content = hiddenattrs_content();
        hiddenattrs_media($content, 'cover');
        $guest = Role::query()->where(['name' => config('permission.roles.guest'), 'guard_name' => 'api'])->firstOrFail();
        $guest->revokePermissionTo(Permission::query()->where(['name' => PermissionName::forClass(Media::class, 'select'), 'guard_name' => 'api'])->firstOrFail());
        Cache::flush();

        $response = $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id]));

        $response->assertOk();
        expect($response->json('data'))->toHaveKey('cover')
            ->and($response->json('data.cover'))->toBeNull();
    });

    it('is the full record as the cover for a caller holding the update permission of media', function (): void {
        $content = hiddenattrs_content();
        hiddenattrs_media($content, 'cover');
        $role = Role::factory()->create(['name' => 'hiddenattrs_integration_' . uniqid(), 'guard_name' => 'api']);

        foreach (['select', 'update'] as $operation) {
            foreach ([Media::class, Content::class] as $model_class) {
                $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => PermissionName::forClass($model_class, $operation), 'guard_name' => 'api']));
            }
        }

        $user = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
        $user->assignRole($role);
        $user = $user->fresh();
        $abilities = $user->getAllPermissions()->where('guard_name', 'api')->pluck('name')->all();

        $response = $this->getJson(
            '/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id]),
            ['Authorization' => 'Bearer ' . $user->createToken('integration', $abilities)->plainTextToken],
        );

        $response->assertOk();
        expect($response->json('data.cover'))->toHaveKeys(['custom_properties', 'disk', 'file_name']);
    });
});

describe('the cover on the app', function (): void {
    it('reaches the content responses of staff with the full record', function (): void {
        $content = hiddenattrs_content();
        hiddenattrs_media($content, 'cover');
        $staff = hiddenattrs_staff();

        $response = $this->actingAs($staff)->getJson('/app/crud/detail/cms/contents?' . http_build_query(['id' => $content->id]));

        $response->assertOk();
        expect($response->json('data.cover'))->toHaveKeys(['custom_properties', 'disk', 'file_name']);
    });
});
