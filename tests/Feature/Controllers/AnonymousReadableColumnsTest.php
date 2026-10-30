<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
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
 * A column the serialization withholds from the caller (hidden from the anonymous user, a media column outside the
 * projection) cannot be read through the query either: a facet, a group_by key, a sort, a filter or an aggregate
 * naming it is refused before anything is queried, on the main model and through a relation.
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

function readcols_content(): Content
{
    return Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
}

function readcols_media(Content $content): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => 'images',
        'name' => 'images',
        'file_name' => 'readcols-original.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 10,
        'model_type' => $content->getMorphClass(),
        'model_id' => $content->getKey(),
        'custom_properties' => ['alt_text' => 'Alt', 'content_hash' => 'secret-hash'],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

/**
 * @param  array<string, mixed>  $query
 */
function readcols_url(string $path, array $query): string
{
    return $path . '?' . http_build_query($query);
}

describe('an anonymous caller of the api', function (): void {
    beforeEach(function (): void {
        $content = readcols_content();
        Comment::factory()->create(['content_id' => $content->id]);
        readcols_media($content);
    });

    it('cannot read a hidden or non-projected column through the query', function (string $url): void {
        $response = $this->getJson($url);

        $response->assertStatus(400);
        expect((string) $response->json('error'))->toContain('not readable by this caller')
            ->and($response->getContent())->not->toContain('secret-hash')
            ->and($response->getContent())->not->toContain('readcols-original');
    })->with([
        'facet on the author of a comment' => readcols_url('/api/v1/facets/cms/comments', ['columns' => ['user_id']]),
        'facet on the contact data of a contributor' => readcols_url('/api/v1/facets/cms/contributors', ['columns' => ['shared_components']]),
        'facet on the custom properties of a media' => readcols_url('/api/v1/facets/core/media', ['columns' => ['custom_properties']]),
        'facet on the original file name of a media' => readcols_url('/api/v1/facets/core/media', ['columns' => ['file_name']]),
        'facet on the disk of a media' => readcols_url('/api/v1/facets/core/media', ['columns' => ['disk']]),
        'open facet on the author of a comment' => readcols_url('/api/v1/facets/cms/comments', ['facet' => ['groupBy' => 'user_id']]),
        'open facet labelled by a hidden field' => readcols_url('/api/v1/facets/cms/comments', ['facet' => ['groupBy' => 'content_id', 'fields' => ['user_id']]]),
        'group_by on the author of a comment' => readcols_url('/api/v1/select/cms/comments', ['group_by' => ['user_id']]),
        'sort on the author of a comment' => readcols_url('/api/v1/select/cms/comments', ['sort' => [['property' => 'user_id', 'direction' => 'asc']]]),
        'filter on the author of a comment' => readcols_url('/api/v1/select/cms/comments', ['filters' => [['property' => 'user_id', 'operator' => 'eq', 'value' => 1]]]),
        'filter on the original file name of a media' => readcols_url('/api/v1/select/core/media', ['filters' => [['property' => 'file_name', 'operator' => 'like', 'value' => 'readcols']]]),
        'filter on a related hidden column' => readcols_url('/api/v1/select/cms/contents', ['filters' => [['property' => 'comments.user_id', 'operator' => 'eq', 'value' => 1]]]),
        'sort on a related hidden column' => readcols_url('/api/v1/select/cms/contents', ['sort' => [['property' => 'contributors.shared_components', 'direction' => 'asc']]]),
        'aggregate over a related hidden column' => readcols_url('/api/v1/select/cms/contents', ['columns' => ['id', ['name' => 'comments.user_id', 'type' => 'sum']]]),
    ]);

    it('still facets, groups, sorts and filters on the columns it receives', function (string $url): void {
        $this->getJson($url)->assertOk();
    })->with([
        'facet on the content of a comment' => readcols_url('/api/v1/facets/cms/comments', ['columns' => ['content_id']]),
        'facet on the collection of a media' => readcols_url('/api/v1/facets/core/media', ['columns' => ['collection_name']]),
        'sort on the media size' => readcols_url('/api/v1/select/core/media', ['sort' => [['property' => 'size', 'direction' => 'asc']]]),
        'group_by on the content of a comment' => readcols_url('/api/v1/select/cms/comments', ['group_by' => ['content_id']]),
    ]);
});

it('lets a token holding the update permission of media facet on the columns of the full record', function (): void {
    readcols_media(readcols_content());
    $role = Role::factory()->create(['name' => 'readcols_media_editor_' . uniqid(), 'guard_name' => 'api']);

    foreach (['select', 'update'] as $operation) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => PermissionName::forClass(Media::class, $operation), 'guard_name' => 'api']));
    }

    $user = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
    $user->assignRole($role);
    $abilities = $user->fresh()->getAllPermissions()->where('guard_name', 'api')->pluck('name')->all();

    $response = $this->getJson(
        readcols_url('/api/v1/facets/core/media', ['columns' => ['file_name']]),
        ['Authorization' => 'Bearer ' . $user->createToken('readcols', $abilities)->plainTextToken],
    );

    $response->assertOk();
    expect(collect($response->json('data.file_name'))->pluck('value')->all())->toContain('readcols-original.jpg');
});

it('lets staff on the app facet on the author of a comment', function (): void {
    Comment::factory()->create(['content_id' => readcols_content()->id]);
    $staff = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
    $staff->assignRole(Role::query()->where(['name' => config('permission.roles.admin'), 'guard_name' => 'web'])->firstOrFail());

    $this->actingAs($staff->fresh())
        ->getJson(readcols_url('/app/crud/facets/cms/comments', ['columns' => ['user_id']]))
        ->assertOk();
});

it('never returns the embeddings of a content, as a relation explicitly requested', function (): void {
    $content = readcols_content();
    $embedding = Modules\Core\Models\ModelEmbedding::factory()->make(['embedding' => [0.1234, 0.5678, 0.9012], 'model_key' => 'readcols-model-key']);
    $embedding->forceFill(['model_type' => $content->getMorphClass(), 'model_id' => $content->getKey()])->save();

    $response = $this->getJson(readcols_url('/api/v1/select/cms/contents', ['relations' => ['embeddings']]));

    // The black-listed relation is dropped, not refused: the list answers, without it.
    $response->assertOk();
    expect(collect($response->json('data'))->firstWhere('id', $content->id))->not->toBeNull()->not->toHaveKey('embeddings')
        ->and($response->getContent())->not->toContain('readcols-model-key')
        ->and($response->getContent())->not->toContain('0.5678');
});
