<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
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
 * The `cover` a content, a category or a contributor appends is read from a relation the CRUD read loads once for the
 * whole result set, under the related permission and ACL of the request's guard: the number of media queries does
 * not grow with the number of rows, and a caller who may not read media still gets a null cover.
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

/**
 * A published content with a cover.
 */
function coverpreload_content(): Content
{
    $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    coverpreload_cover($content);

    return $content;
}

function coverpreload_cover(Illuminate\Database\Eloquent\Model $owner): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => 'cover',
        'name' => 'cover',
        'file_name' => 'cover-' . uniqid() . '.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 10,
        'model_type' => $owner->getMorphClass(),
        'model_id' => $owner->getKey(),
        'custom_properties' => ['alt_text' => 'Cover of ' . $owner->getKey()],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

/**
 * The number of queries a request runs on the media table, or only on the cover collection of it.
 */
function coverpreload_media_queries(Closure $request, bool $cover_only = false): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    DB::disableQueryLog();

    // The api middleware leaves its guard and user behind; the next fixtures are written by nobody, as in a seeder.
    Auth::shouldUse('web');
    Auth::forgetGuards();
    request()->setUserResolver(static fn (): null => null);

    $table = (new Media)->getTable();

    return collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains((string) $query['query'], $table) && (! $cover_only || str_contains((string) $query['query'], '"collection_name" = ?')))
        ->count();
}

/**
 * A user whose `api` role holds the given permission names, with a token carrying them as abilities.
 *
 * @param  list<class-string<Illuminate\Database\Eloquent\Model>>  $selectable
 * @return array<string, string>
 */
function coverpreload_bearer(array $selectable): array
{
    $role = Role::factory()->create(['name' => 'coverpreload_' . uniqid(), 'guard_name' => 'api']);
    $abilities = [];

    foreach ($selectable as $model_class) {
        $name = PermissionName::forClass($model_class, 'select');
        $abilities[] = $name;
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']));
    }

    $user = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
    $user->assignRole($role);

    return ['Authorization' => 'Bearer ' . $user->fresh()->createToken('coverpreload', $abilities)->plainTextToken];
}

/**
 * @return list<int|null>
 */
function coverpreload_cover_ids(TestResponse $response, string $path = 'data'): array
{
    return collect($response->json($path))->sortBy('id')->map(static fn (array $row): ?int => $row['cover']['id'] ?? null)->values()->all();
}

describe('the media queries of a list of contents', function (): void {
    it('do not grow with the number of rows, for an anonymous caller', function (): void {
        coverpreload_content();
        coverpreload_content();
        $few = coverpreload_media_queries(fn () => $this->getJson('/api/v1/select/cms/contents')->assertOk());

        foreach (range(1, 8) as $ignored) {
            coverpreload_content();
        }

        $many = coverpreload_media_queries(function () use (&$response): void {
            $response = $this->getJson('/api/v1/select/cms/contents')->assertOk();
        });

        expect($response->json('data'))->toHaveCount(10)
            ->and($many)->toBe($few)
            ->and($many)->toBeLessThanOrEqual(3);
    });

    it('do not grow with the number of rows, for staff on the app', function (): void {
        $staff = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
        $staff->assignRole(Role::query()->where(['name' => config('permission.roles.admin'), 'guard_name' => 'web'])->firstOrFail());
        $staff = $staff->fresh();

        coverpreload_content();
        coverpreload_content();
        $few = coverpreload_media_queries(fn () => $this->actingAs($staff)->getJson('/app/crud/select/cms/contents')->assertOk());

        foreach (range(1, 8) as $ignored) {
            coverpreload_content();
        }

        $many = coverpreload_media_queries(function () use ($staff): void {
            $this->actingAs($staff)->getJson('/app/crud/select/cms/contents')->assertOk();
        });

        expect($many)->toBe($few);
    });

    it('do not grow either for the contributors a content loads as a relation', function (): void {
        $bearer = coverpreload_bearer([Content::class, Contributor::class, Media::class]);
        $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        $url = '/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['contributors']]);

        $attach = static function (int $count) use ($content): void {
            foreach (range(1, $count) as $ignored) {
                $contributor = Contributor::factory()->create(['user_id' => null]);
                coverpreload_cover($contributor);
                $content->contributors()->attach($contributor->id);
            }
        };

        $attach(2);
        $few = coverpreload_media_queries(fn () => $this->getJson($url, $bearer)->assertOk());
        $attach(6);
        $many = coverpreload_media_queries(function () use ($url, $bearer, &$response): void {
            $response = $this->getJson($url, $bearer)->assertOk();
        });

        expect($response->json('data.contributors'))->toHaveCount(8)
            ->and(coverpreload_cover_ids($response, 'data.contributors'))->each->not->toBeNull()
            ->and($many)->toBe($few);
    });
});

describe('the media queries of the other read routes', function (): void {
    // The test search engine (Scout's collection engine) builds a searchable document per model while it matches,
    // which reads each model's media; a real engine does not. Only the queries of the cover collection are counted.
    it('load the covers of a search in one query, whatever the number of rows', function (): void {
        coverpreload_content();
        coverpreload_content();
        $few = coverpreload_media_queries(fn () => $this->getJson('/api/v1/search/cms/contents?' . http_build_query(['qs' => 'contents']))->assertOk(), cover_only: true);

        foreach (range(1, 8) as $ignored) {
            coverpreload_content();
        }

        $many = coverpreload_media_queries(function () use (&$response): void {
            $response = $this->getJson('/api/v1/search/cms/contents?' . http_build_query(['qs' => 'contents']))->assertOk();
        }, cover_only: true);

        expect($response->json('data'))->toHaveCount(10)
            ->and(coverpreload_cover_ids($response))->each->not->toBeNull()
            ->and($few)->toBe(1)
            ->and($many)->toBe(1);
    });

    it('give the cover of a detail without a further query per relation', function (): void {
        $content = coverpreload_content();

        $queries = coverpreload_media_queries(function () use ($content, &$response): void {
            $response = $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id]))->assertOk();
        });

        expect($response->json('data.cover.id'))->not->toBeNull()
            ->and($queries)->toBeLessThanOrEqual(3);
    });
});

describe('the media queries of a tree', function (): void {
    /**
     * Adds a category with the given number of children, each with a cover, and reads the tree of every category
     * with the descendants of each. The route is called with a JSON body, which keeps `children` a boolean as the
     * request data types it. Asking for one root by its id is not possible: the single-record tree matches two rows
     * on categories (a known problem of that route, unrelated to the cover), so the tree of all of them is read.
     *
     * The tree relation of a category is hidden from its serialization, so the nodes are loaded, with the relations
     * they read on their own, but their covers are not printed: what is counted is the loading.
     *
     * @return array{0: TestResponse, 1: int}
     */
    function coverpreload_tree(array $bearer, int $children): array
    {
        $root = Category::factory()->create();
        DB::table($root->getTable())->where('id', $root->id)->update(['parent_id' => null, 'valid_from' => now()->subDay()]);
        coverpreload_cover($root);

        foreach (range(1, $children) as $ignored) {
            $child = Category::factory()->create();
            DB::table($child->getTable())->where('id', $child->id)->update(['parent_id' => $root->id, 'valid_from' => now()->subDay()]);
            coverpreload_cover($child);
        }

        $response = null;
        $queries = coverpreload_media_queries(function () use ($bearer, &$response): void {
            $response = test()->json('GET', '/api/v1/tree/cms/categories', ['children' => true], $bearer);
        }, cover_only: true);

        return [$response, $queries];
    }

    it('load the covers of the roots in one query and the covers of all the nodes in one more, whatever their number', function (): void {
        setupCMSEntities([EntityType::Categories]);
        $bearer = coverpreload_bearer([Category::class, Media::class]);

        [$few_response, $few] = coverpreload_tree($bearer, 2);
        [$many_response, $many] = coverpreload_tree($bearer, 8);

        $few_response->assertOk();
        $many_response->assertOk();

        expect($few)->toBe(2)
            ->and($many)->toBe(2);
    });

    it('leave the nodes without a cover query when the caller may not read media', function (): void {
        setupCMSEntities([EntityType::Categories]);
        $bearer = coverpreload_bearer([Category::class]);

        [$response, $queries] = coverpreload_tree($bearer, 3);

        $response->assertOk();
        expect($queries)->toBe(0);
    });
});

describe('the preloaded cover', function (): void {
    it('is the right media of each row, in the projection on the api guard', function (): void {
        $first = coverpreload_content();
        $second = coverpreload_content();
        $covers = [$first->id => Media::query()->where('model_id', $first->id)->value('id'), $second->id => Media::query()->where('model_id', $second->id)->value('id')];

        $response = $this->getJson('/api/v1/select/cms/contents')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        expect($rows[$first->id]['cover']['id'])->toBe($covers[$first->id])
            ->and($rows[$second->id]['cover']['id'])->toBe($covers[$second->id])
            ->and($rows[$first->id]['cover']['alt_text'])->toBe('Cover of ' . $first->id)
            ->and($rows[$first->id]['cover'])->not->toHaveKeys(['custom_properties', 'disk', 'file_name']);
    });

    it('is not echoed as a media or cover_media relation of the row', function (): void {
        coverpreload_content();

        $row = $this->getJson('/api/v1/select/cms/contents')->assertOk()->json('data.0');

        expect($row)->not->toHaveKeys(['media', 'cover_media', 'coverMedia']);
    });

    it('is null for every row when the caller may not read media, with no media query at all', function (): void {
        coverpreload_content();
        coverpreload_content();
        $bearer = coverpreload_bearer([Content::class]);

        $queries = coverpreload_media_queries(function () use ($bearer, &$response): void {
            $response = $this->getJson('/api/v1/select/cms/contents', $bearer)->assertOk();
        });

        expect(coverpreload_cover_ids($response))->toBe([null, null])
            ->and($response->json('data.0'))->toHaveKey('cover')
            ->and($queries)->toBe(0);
    });

    it('is narrowed by the media ACL of the guest: the cover of a published content, not the one of its contributor', function (): void {
        $content = coverpreload_content();
        $contributor = Contributor::factory()->create(['user_id' => null]);
        coverpreload_cover($contributor);
        $content->contributors()->attach($contributor->id);

        $response = $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['contributors']]))->assertOk();

        expect($response->json('data.cover.id'))->not->toBeNull()
            ->and($response->json('data.contributors'))->toHaveCount(1)
            ->and($response->json('data.contributors.0'))->toHaveKey('cover')
            ->and($response->json('data.contributors.0.cover'))->toBeNull();
    });
});
