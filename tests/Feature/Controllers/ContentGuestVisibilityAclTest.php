<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentReference;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Media;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The anonymous reader of `/api` is limited to the public CMS contents by the ACLs the seeders create on the
 * `guest` role of the `api` guard (the content condition itself, and the owner condition of references and
 * media). `/app` is for authenticated users only: the `guest` role of the `web` guard holds nothing.
 */

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents]);

    Artisan::call('permission:refresh', ['--quiet' => true]);
    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A live content, a draft (not yet valid) and an expired one, each with a reference and a media.
 *
 * @return array{live: Content, draft: Content, expired: Content, references: array<string, ContentReference>, media: array<string, Media>}
 */
function guestvis_contents(): array
{
    $contents = [
        'live' => Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]),
        'draft' => Content::factory()->create(['valid_from' => now()->addWeek(), 'valid_to' => null]),
        'expired' => Content::factory()->create(['valid_from' => now()->subMonth(), 'valid_to' => now()->subDay()]),
    ];

    $references = [];
    $media = [];

    foreach ($contents as $key => $content) {
        $references[$key] = ContentReference::factory()->create(['content_id' => $content->id]);
        $media[$key] = guestvis_media($content->getMorphClass(), $content->getKey());
    }

    return ['live' => $contents['live'], 'draft' => $contents['draft'], 'expired' => $contents['expired'], 'references' => $references, 'media' => $media];
}

function guestvis_media(string $owner_type, int|string $owner_id): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => 'cover',
        'name' => 'cover',
        'file_name' => 'cover-' . uniqid() . '.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 10,
        'model_type' => $owner_type,
        'model_id' => $owner_id,
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
function guestvis_ids(TestResponse $response, string $path = 'data'): array
{
    return collect($response->json($path))->pluck('id')->sort()->values()->all();
}

it('shows an anonymous caller of the api only the currently published contents, in a select and a detail', function (): void {
    $all = guestvis_contents();

    $list = $this->getJson('/api/v1/select/cms/contents');

    $list->assertOk();
    expect(guestvis_ids($list))->toBe([$all['live']->id]);

    $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $all['live']->id]))->assertOk();

    foreach (['draft', 'expired'] as $hidden) {
        $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $all[$hidden]->id]))->assertNotFound();
    }
});

it('finds an anonymous caller only the published contents in a search', function (): void {
    $all = guestvis_contents();

    $response = $this->getJson('/api/v1/search/cms/contents?' . http_build_query(['qs' => 'contents']));

    $response->assertOk();

    // The search document of every content carries its type, so the query matches the three of them in the
    // engine; the ACL is what leaves only the published one.
    expect(guestvis_ids($response))->toBe([$all['live']->id]);
});

it('finds an anonymous caller only the media of the published contents in a search, never a ticket attachment', function (string $visibility): void {
    // Also in `open` mode: the ACL of the guest is what narrows the hits, the owner mode is only an extra condition.
    config()->set('core.media.search_visibility', $visibility);
    $all = guestvis_contents();
    $attachment = guestvis_media('Modules\\SAO\\Models\\Ticket', $all['live']->id);

    $response = $this->getJson('/api/v1/search/core/media?' . http_build_query(['qs' => 'cover']));

    $response->assertOk();

    expect(guestvis_ids($response))->toBe([$all['media']['live']->id])
        ->and(guestvis_ids($response))->not->toContain($attachment->id);
})->with(['owner', 'open']);

it('shows an anonymous caller the references of the published contents only', function (): void {
    $all = guestvis_contents();

    $response = $this->getJson('/api/v1/select/cms/content_reference');

    $response->assertOk();
    expect(guestvis_ids($response))->toBe([$all['references']['live']->id]);
});

it('shows an anonymous caller the media of the published contents only, never a ticket attachment', function (): void {
    $all = guestvis_contents();
    $attachment = guestvis_media('Modules\\SAO\\Models\\Ticket', $all['live']->id);

    $response = $this->getJson('/api/v1/select/core/media');

    $response->assertOk();
    expect(guestvis_ids($response))->toBe([$all['media']['live']->id])
        ->and(guestvis_ids($response))->not->toContain($attachment->id);
});

it('does not show an anonymous caller a reference or a media of an unpublished content in a detail', function (): void {
    $all = guestvis_contents();

    $this->getJson('/api/v1/detail/cms/content_reference?' . http_build_query(['id' => $all['references']['live']->id]))->assertOk();
    $this->getJson('/api/v1/detail/core/media?' . http_build_query(['id' => $all['media']['live']->id]))->assertOk();

    foreach (['draft', 'expired'] as $hidden) {
        $this->getJson('/api/v1/detail/cms/content_reference?' . http_build_query(['id' => $all['references'][$hidden]->id]))->assertNotFound();
        $this->getJson('/api/v1/detail/core/media?' . http_build_query(['id' => $all['media'][$hidden]->id]))->assertNotFound();
    }
});

it('shows an anonymous caller only the visible references and media when they are loaded as relations', function (): void {
    $all = guestvis_contents();

    $response = $this->getJson('/api/v1/select/cms/contents?' . http_build_query(['relations' => ['references', 'media']]));

    $response->assertOk();
    $row = collect($response->json('data'))->sole();

    expect($row['id'])->toBe($all['live']->id)
        ->and(guestvis_ids($response, 'data.0.references'))->toBe([$all['references']['live']->id])
        ->and(guestvis_ids($response, 'data.0.media'))->toBe([$all['media']['live']->id]);
});

it('does not show a media or a reference of a draft through a detail of the draft either', function (): void {
    $all = guestvis_contents();

    $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $all['draft']->id, 'relations' => ['references', 'media']]))
        ->assertJsonMissing(['id' => $all['references']['draft']->id])
        ->assertJsonMissing(['file_name' => $all['media']['draft']->file_name]);
});

it('answers an anonymous session read on the app without data: the guest role of the web guard holds nothing (R11)', function (): void {
    $all = guestvis_contents();

    // An anonymous caller is not an authenticated principal, so with today's mapping the denied read answers 401;
    // an authenticated user whose roles hold no permission answers 403 (see the test below).
    $response = $this->getJson('/app/crud/select/cms/contents');

    $response->assertUnauthorized();
    $response->assertJsonMissing(['id' => $all['live']->id]);
    expect($response->json('data') ?? [])->toBe([]);
});

it('keeps the info routes of the app working for an anonymous caller', function (): void {
    $this->getJson('/app/about')->assertOk();
});

it('answers 403 to an authenticated user whose roles hold no web permission on the contents', function (): void {
    guestvis_contents();
    $user = User::factory()->create();
    $user->assignRole(Role::query()->where(['name' => config('permission.roles.guest'), 'guard_name' => 'web'])->firstOrFail());

    $this->actingAs($user->fresh())->getJson('/app/crud/select/cms/contents')->assertForbidden();
});

it('does not restrict a staff role without an ACL on the contents: the publisher lists the drafts on the app', function (): void {
    $all = guestvis_contents();
    $publisher = User::factory()->create();
    $publisher->assignRole(Role::query()->where(['name' => 'publisher', 'guard_name' => 'web'])->firstOrFail());

    $response = $this->actingAs($publisher->fresh())->getJson('/app/crud/select/cms/contents');

    $response->assertOk();
    expect(guestvis_ids($response))->toBe(collect($all)->only(['live', 'draft', 'expired'])->map->id->sort()->values()->all());
});

it('lets the seeded admin list every content on the app', function (): void {
    $all = guestvis_contents();
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where(['name' => config('permission.roles.admin'), 'guard_name' => 'web'])->firstOrFail());

    $response = $this->actingAs($admin->fresh())->getJson('/app/crud/select/cms/contents');

    $response->assertOk();
    expect(guestvis_ids($response))->toBe(collect($all)->only(['live', 'draft', 'expired'])->map->id->sort()->values()->all());
});

/**
 * Anonymous responses are cached per user, so every anonymous caller shares the cached response of an entity that
 * uses the response cache, for the whole configured TTL (`cache.duration`, its medium value). A row that stops
 * being public, or an ACL changed, inside that window keeps showing to anonymous callers until the entry
 * expires: the stale window equals the TTL, and this plan accepts it, with no cache busting on validity changes.
 * The CMS contents do not use the response cache today (the model does not use `HasCache`), so a content that
 * stops being valid disappears at once; the settings, which the guest reads on the api, are cached.
 */
it('serves the anonymous response from the response cache for the configured TTL and not longer', function (): void {
    $ttl = 120;
    config()->set('cache.duration', ['medium' => $ttl]);
    $setting = Setting::query()->withoutGlobalScopes()->where('name', 'default_language')->firstOrFail();
    DB::table($setting->getTable())->where('id', $setting->id)->update(['is_public' => true]);
    Cache::flush();

    $names = static fn (TestResponse $response): array => collect($response->json('data'))->pluck('name')->all();

    expect($names($this->getJson('/api/v1/select/core/settings')))->toContain('default_language');

    // The row stops being public without going through the model, so nothing busts the cache entry.
    DB::table($setting->getTable())->where('id', $setting->id)->update(['is_public' => false]);

    Carbon::setTestNow(now()->addSeconds($ttl - 1));

    expect($names($this->getJson('/api/v1/select/core/settings')))->toContain('default_language');

    Carbon::setTestNow(now()->addSeconds(2));

    expect($names($this->getJson('/api/v1/select/core/settings')))->not->toContain('default_language');
});

it('does not serve a stale content to an anonymous caller, because the contents are not response cached', function (): void {
    $live = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);

    expect(guestvis_ids($this->getJson('/api/v1/select/cms/contents')))->toBe([$live->id]);

    DB::table($live->getTable())->where('id', $live->id)->update(['valid_to' => now()->subHour()]);

    expect(guestvis_ids($this->getJson('/api/v1/select/cms/contents')))->toBe([]);
});
