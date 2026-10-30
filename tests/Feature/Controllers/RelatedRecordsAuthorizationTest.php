<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Media;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Related records of a content obey their own permission and ACL (spec 8.1), parts of the content (its
 * translations) inherit the content's visibility, and the `cover` a content appends on its own is null for a
 * caller who may not read media.
 */

beforeEach(function (): void {
    Cache::flush();
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents, EntityType::Contributors]);

    // As `permission:refresh` does: the select permissions exist on both guards, granted to nobody. An entity
    // with no permission registered at all is outside the permission scheme and is not checked.
    foreach ([Content::class, Contributor::class, User::class, Media::class] as $model_class) {
        foreach (['web', 'api'] as $guard) {
            Permission::query()->firstOrCreate(['name' => PermissionName::forClass($model_class, 'select'), 'guard_name' => $guard]);
        }
    }
});

/**
 * A user whose role on the given guard holds the given permissions of each model, the select one narrowed by its
 * ACL when one is given.
 *
 * @param  array<class-string<Illuminate\Database\Eloquent\Model>, FiltersGroup|null>  $grants
 * @param  list<string>  $extra_permissions  full permission names granted beside the select ones
 */
function relrec_reader(array $grants, array $extra_permissions = [], string $guard = 'api'): App\Models\User
{
    $role = Role::factory()->create(['name' => 'relrec_reader_' . uniqid(), 'guard_name' => $guard]);
    $names = [];

    foreach ($grants as $model_class => $filters) {
        $name = PermissionName::forClass($model_class, 'select');
        $names[] = $name;
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => $guard === 'api' ? 'web' : 'api']);
        $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => $guard]);
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

    foreach ($extra_permissions as $name) {
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => $guard]));
    }

    $user = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

function relrec_media(Content $content, string $collection): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => $collection,
        'name' => $collection,
        'file_name' => $collection . '-' . uniqid() . '.jpg',
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
 * A content with two contributors, `relrec_visible` and `relrec_hidden`, each linked to a user of the same name.
 */
function relrec_content_with_contributors(): Content
{
    $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);

    foreach (['relrec_visible', 'relrec_hidden'] as $name) {
        $user = User::factory()->create(['name' => $name]);
        $content->contributors()->attach(Contributor::factory()->create(['name' => $name, 'user_id' => $user->id])->id);
    }

    return $content;
}

/**
 * The names of the contributors loaded on a content row.
 *
 * @param  array<string, mixed>  $data
 * @return list<string>
 */
function relrec_contributor_names(array $data): array
{
    return collect($data['contributors'] ?? [])->pluck('name')->sort()->values()->all();
}

/**
 * The ACL that lets a reader see only the contributor or user named `relrec_visible`.
 */
function relrec_only_visible(): FiltersGroup
{
    return new FiltersGroup([new Filter('name', ['relrec_visible'], FilterOperator::In)], WhereClause::And);
}

/**
 * Makes the given user the caller of an `api` request, as the API middleware does.
 */
function relrec_act_on_api(App\Models\User $user): void
{
    Illuminate\Support\Facades\Auth::shouldUse('api');
    Illuminate\Support\Facades\Auth::guard('api')->setUser($user);
    request()->setUserResolver(static fn (): App\Models\User => $user);
}

/**
 * A detail request for the content with the given relations, as the CRUD controller builds it.
 *
 * @param  list<string>  $relations
 */
function relrec_detail_data(Content $content, array $relations): Modules\Core\Casts\DetailRequestData
{
    $request = Modules\Core\Http\Requests\DetailRequest::create('/api/v1/detail/cms/contents', 'GET', ['id' => $content->id, 'relations' => $relations]);
    $request->setUserResolver(static fn (): ?Illuminate\Contracts\Auth\Authenticatable => Illuminate\Support\Facades\Auth::user());

    $data = (new ReflectionClass(Modules\Core\Casts\DetailRequestData::class))->newInstanceWithoutConstructor();

    foreach (['request' => $request, 'model' => new Content, 'columns' => [], 'relations' => $relations, 'mainEntity' => 'cms_contents'] as $property => $value) {
        (new ReflectionProperty($data, $property))->setValue($data, $value);
    }

    return $data;
}

describe('a related entity the caller may not select', function (): void {
    it('refuses contributors.user to a caller without the users select permission', function (): void {
        $content = relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => null]);

        $this->actingAs($reader)
            ->getJson('/api/v1/select/cms/contents?' . http_build_query(['relations' => ['contributors.user']]))
            ->assertForbidden();
        $this->actingAs($reader)
            ->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['contributors.user']]))
            ->assertForbidden();
    });

    it('refuses a dotted column on the users entity', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => null]);

        $this->actingAs($reader)
            ->getJson('/api/v1/select/cms/contents?' . http_build_query(['columns' => ['contributors.user.email']]))
            ->assertForbidden();
        $this->actingAs($reader)
            ->getJson('/api/v1/select/cms/contributors?' . http_build_query(['columns' => ['user.email']]))
            ->assertForbidden();
    });

    it('leaves out the user a contributor loads on its own', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => null]);

        $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/contents?' . http_build_query(['relations' => ['contributors']]));

        $response->assertOk();
        expect(collect($response->json('data.0.contributors'))->pluck('user')->filter()->all())->toBe([]);
    });
});

describe('the ACL of a related entity', function (): void {
    it('keeps only the contributors it allows in a list', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => relrec_only_visible()]);

        $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/contents?' . http_build_query(['relations' => ['contributors']]));

        $response->assertOk();
        expect(relrec_contributor_names($response->json('data.0')))->toBe(['relrec_visible'])
            ->and($response->json('meta.relations'))->toBeNull();
    });

    it('keeps only the contributors it allows in a detail and declares how many it left out', function (): void {
        $content = relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => relrec_only_visible()]);

        $response = $this->actingAs($reader)->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['contributors']]));

        $response->assertOk();
        expect(relrec_contributor_names($response->json('data')))->toBe(['relrec_visible'])
            ->and($response->json('meta.relations.contributors.hidden'))->toBe(1);
    });

    it('loads the users of the contributors through an api role that may select users, narrowed by its ACL', function (): void {
        $content = relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => null, User::class => relrec_only_visible()]);

        $this->actingAs($reader)
            ->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['contributors.user']]))
            ->assertOk();

        relrec_act_on_api($reader);

        // The user of a contributor is hidden from the serialized contributor: the loaded relation is read from
        // the records the same query returns.
        $query = Content::query()->whereKey($content->id);
        app(Modules\Core\Services\Crud\QueryBuilder::class)->prepareAuthorizedQuery(
            $query,
            relrec_detail_data($content, ['contributors.user']),
            app(Modules\Core\Services\Crud\RelationAuthorizer::class),
        );

        $users = $query->sole()->contributors->mapWithKeys(static fn (Contributor $contributor): array => [$contributor->name => $contributor->user?->name])->sortKeys()->all();

        expect($users)->toBe(['relrec_hidden' => null, 'relrec_visible' => 'relrec_visible']);
    });

    it('declares on a detail how many media it left out', function (): void {
        $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        relrec_media($content, 'cover');
        relrec_media($content, 'images');
        relrec_media($content, 'images');

        $reader = relrec_reader([
            Content::class => null,
            Media::class => new FiltersGroup([new Filter('collection_name', 'cover', FilterOperator::Equals)], WhereClause::And),
        ]);

        $response = $this->actingAs($reader)->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['media']]));

        $response->assertOk();
        expect(collect($response->json('data.media'))->pluck('collection_name')->all())->toBe(['cover'])
            ->and($response->json('meta.relations.media.hidden'))->toBe(2);
    });
});

describe('parts of a content', function (): void {
    it('loads the translations of a content without a permission of their own', function (): void {
        Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        $reader = relrec_reader([Content::class => null]);

        $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/contents?' . http_build_query(['relations' => ['translations']]));

        $response->assertOk();
        expect($response->json('data.0.translations'))->not->toBeEmpty();
    });
});

describe('the cover of a content', function (): void {
    it('is null for a caller who may not read media, and the media are not loaded for it', function (): void {
        $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        relrec_media($content, 'cover');
        relrec_act_on_api(relrec_reader([Content::class => null]));

        $reloaded = Content::query()->findOrFail($content->id);

        expect($reloaded->cover)->toBeNull()
            ->and($reloaded->relationLoaded('media'))->toBeFalse();
    });

    it('is given to a caller who may read media', function (): void {
        $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        $cover = relrec_media($content, 'cover');
        relrec_act_on_api(relrec_reader([Content::class => null, Media::class => null]));

        expect(Content::query()->findOrFail($content->id)->cover?->id)->toBe($cover->id);
    });

    it('is null when the media ACL hides the cover', function (): void {
        $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        relrec_media($content, 'cover');
        relrec_act_on_api(relrec_reader([
            Content::class => null,
            Media::class => new FiltersGroup([new Filter('collection_name', 'images', FilterOperator::Equals)], WhereClause::And),
        ]));

        expect(Content::query()->findOrFail($content->id)->cover)->toBeNull();
    });
});

describe('a soft-deleted related record in a filter', function (): void {
    it('is matched only when the token carries the related delete ability', function (bool $with_ability, bool $expected): void {
        $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        $contributor = Contributor::factory()->create(['user_id' => null]);
        $content->contributors()->attach($contributor->id);
        $contributor->delete();

        $select_names = [PermissionName::forClass(Content::class, 'select'), PermissionName::forClass(Contributor::class, 'select')];
        $delete_name = PermissionName::forClass(Contributor::class, 'delete');
        $reader = relrec_reader([Content::class => null, Contributor::class => null], [$delete_name]);
        $token = $reader->createToken('relrec', $with_ability ? [...$select_names, $delete_name] : $select_names)->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/select/cms/contents?' . http_build_query([
            'filters' => [['property' => 'contributors.deleted_at', 'operator' => 'gt', 'value' => '2000-01-01 00:00:00']],
        ]));

        $response->assertOk();
        expect(collect($response->json('data'))->pluck('id')->contains($content->id))->toBe($expected);
    })->with([
        'without the ability' => [false, false],
        'with the ability' => [true, true],
    ]);
});

describe('the seeded staff roles through /app', function (): void {
    beforeEach(function (): void {
        Artisan::call('permission:refresh');
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CMSDatabaseSeeder::class);
    });

    it('lets an admin read a content with its media and contributors', function (): void {
        $content = relrec_content_with_contributors();
        $cover = relrec_media($content, 'cover');
        $admin = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
        $admin->assignRole(Role::query()->where(['name' => config('permission.roles.admin'), 'guard_name' => 'web'])->firstOrFail());

        $response = $this->actingAs($admin)->getJson('/app/crud/detail/cms/contents?' . http_build_query([
            'id' => $content->id,
            'relations' => ['media', 'contributors.user'],
        ]));

        $response->assertOk();
        expect(collect($response->json('data.media'))->pluck('id')->all())->toBe([$cover->id])
            ->and(relrec_contributor_names($response->json('data')))->toBe(['relrec_hidden', 'relrec_visible']);
    });

    it('lets a publisher open a content with the relations of the content form and the pending modifications', function (): void {
        $content = relrec_content_with_contributors();
        $publisher = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
        $publisher->assignRole(Role::query()->where(['name' => 'publisher', 'guard_name' => 'web'])->firstOrFail());

        // Modifications have no generated permission at all: they are read like any entity outside the
        // permission scheme. (The content list itself is not reachable for a seeded publisher: it reads the
        // per-model settings, and the publisher holds no core_settings permission.)
        $this->actingAs($publisher)->getJson('/app/crud/detail/cms/contents?' . http_build_query([
            'id' => $content->id,
            'relations' => ['tags', 'categories', 'locations', 'contributors', 'modifications'],
        ]))->assertOk();
    });
});

describe('a relation a package declares only in PHPDoc', function (): void {
    it('loads the parent of a category, now that the relation declares its return type', function (): void {
        setupCMSEntities([EntityType::Categories]);

        $parent = Category::factory()->create();
        $parent->parent_id = null;
        $parent->save();

        $child = Category::factory()->create();
        $child->parent_id = $parent->id;
        $child->save();

        $reader = relrec_reader([Category::class => null]);

        $response = $this->actingAs($reader)->getJson('/api/v1/detail/cms/categories?' . http_build_query(['id' => $child->id, 'relations' => ['parent']]));

        $response->assertOk();
        expect($response->json('data.parent.id'))->toBe($parent->id);
    });
});

describe('group_by through a relation', function (): void {
    it('is refused to a caller who may not select the related entity', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Contributor::class => null]);

        $this->actingAs($reader)->getJson('/api/v1/select/cms/contributors?' . http_build_query(['group_by' => ['user.name']]))->assertForbidden();
    });

    it('groups only by the related values the related ACL allows', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Contributor::class => null, User::class => relrec_only_visible()]);

        $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/contributors?' . http_build_query(['group_by' => ['user.name']]));

        $response->assertOk();
        expect(array_keys((array) $response->json('data')))->toContain('relrec_visible')->not->toContain('relrec_hidden');
    });
});

describe('group_by through a relation the model does not load on its own', function (): void {
    it('groups only by the related records the related ACL allows', function (): void {
        $live = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        $draft = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        Modules\CMS\Models\ContentReference::factory()->create(['content_id' => $live->id]);
        Modules\CMS\Models\ContentReference::factory()->create(['content_id' => $draft->id]);

        $reader = relrec_reader([
            Modules\CMS\Models\ContentReference::class => null,
            Content::class => new FiltersGroup([new Filter('id', [$live->id], FilterOperator::In)], WhereClause::And),
        ]);

        $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/content_reference?' . http_build_query(['group_by' => ['content.id']]));

        // The groups come back as a list (integer keys): what tells them apart is the content each row loaded.
        $response->assertOk();
        $loaded = collect($response->json('data'))->flatten(1)->pluck('content.id')->filter()->values()->all();

        expect($loaded)->toContain($live->id)->not->toContain($draft->id);
    });
});

describe('facets through a relation', function (): void {
    it('refuse a related column facet, a related label and a related filter to a caller without the related permission', function (string $url): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => null]);

        $this->actingAs($reader)->getJson($url)->assertForbidden();
    })->with([
        'related column' => '/api/v1/facets/cms/contributors?' . http_build_query(['facet' => ['groupBy' => 'user.name']]),
        'related label field' => '/api/v1/facets/cms/contributors?' . http_build_query(['facet' => ['groupBy' => 'user_id', 'fields' => ['user.name']]]),
        'related label search' => '/api/v1/facets/cms/contributors?' . http_build_query(['facet' => ['groupBy' => 'user_id', 'labelField' => 'user.name', 'search' => 'relrec']]),
        'request relation filter' => '/api/v1/facets/cms/contributors?' . http_build_query(['columns' => ['name'], 'filters' => [['property' => 'user.name', 'operator' => 'eq', 'value' => 'relrec_hidden']]]),
    ]);

    it('refuse a many-to-many relation facet to a caller without the related permission', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null]);

        $this->actingAs($reader)
            ->getJson('/api/v1/facets/cms/contents?' . http_build_query(['facet' => ['groupBy' => 'id', 'relation' => 'contributors']]))
            ->assertForbidden();
    });

    it('keep only the related values the related ACL allows', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => relrec_only_visible(), User::class => relrec_only_visible()]);
        $visible = Contributor::query()->where('name', 'relrec_visible')->sole();
        $hidden_user = User::query()->where('name', 'relrec_hidden')->sole();

        $column = $this->actingAs($reader)->getJson('/api/v1/facets/cms/contributors?' . http_build_query(['facet' => ['groupBy' => 'user.name']]));
        $relation = $this->actingAs($reader)->getJson('/api/v1/facets/cms/contents?' . http_build_query(['facet' => ['groupBy' => 'id', 'relation' => 'contributors']]));
        $labels = $this->actingAs($reader)->getJson('/api/v1/facets/cms/contributors?' . http_build_query(['facet' => ['groupBy' => 'user_id', 'fields' => ['user.name']]]));

        $column->assertOk();
        $relation->assertOk();
        $labels->assertOk();
        expect(collect($column->json('data.values'))->pluck('key')->all())->toBe(['relrec_visible'])
            ->and(collect($relation->json('data.values'))->pluck('key')->all())->toBe([$visible->id])
            ->and(collect($labels->json('data.values'))->pluck('attributes')->pluck('user.name')->filter()->values()->all())->not->toContain('relrec_hidden')
            ->and(collect($labels->json('data.values'))->firstWhere('key', $hidden_user->id)['attributes']['user.name'] ?? null)->toBeNull();
    });
});

describe('an ACL on an intermediate relation', function (): void {
    it('narrows the intermediate hop of a nested path', function (): void {
        relrec_content_with_contributors();
        $reader = relrec_reader([Content::class => null, Contributor::class => relrec_only_visible(), User::class => null]);

        $response = $this->actingAs($reader)->getJson('/api/v1/select/cms/contents?' . http_build_query(['relations' => ['contributors.user']]));

        $response->assertOk();
        expect(relrec_contributor_names($response->json('data.0')))->toBe(['relrec_visible']);
    });
});

describe('the pending modifications of a content', function (): void {
    beforeEach(function (): void {
        $this->content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
        Modules\Core\Models\Modification::query()->create([
            'modifiable_type' => $this->content->getMorphClass(),
            'modifiable_id' => $this->content->id,
            'md5' => md5('relrec'),
            'modifications' => ['valid_to' => ['modified' => '2099-01-01 00:00:00']],
        ]);
    });

    it('are refused to the anonymous caller with 401', function (): void {
        config()->set('permission.users.guest', 'anonymous');
        $anonymous = App\Models\User::query()->where('name', 'anonymous')->first() ?? App\Models\User::query()->findOrFail(User::factory()->create(['name' => 'anonymous', 'username' => 'anonymous'])->getKey());
        $role = Role::factory()->create(['name' => 'relrec_guest_' . uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => PermissionName::forClass(Content::class, 'select'), 'guard_name' => 'api']));
        $anonymous->assignRole($role);

        $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $this->content->id]))->assertOk();
        $this->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $this->content->id, 'relations' => ['modifications']]))->assertUnauthorized();
    });

    it('are refused with 403 to a reader who may only select the content', function (): void {
        $reader = relrec_reader([Content::class => null]);

        $this->actingAs($reader)
            ->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $this->content->id, 'relations' => ['modifications']]))
            ->assertForbidden();
        $this->actingAs($reader)
            ->getJson('/api/v1/select/cms/contents?' . http_build_query(['group_by' => ['modifications.id']]))
            ->assertForbidden();
    });

    it('are given to a reader who may update or approve the content', function (string $operation): void {
        $reader = relrec_reader([Content::class => null], [PermissionName::forClass(Content::class, $operation)]);

        $response = $this->actingAs($reader)->getJson('/api/v1/detail/cms/contents?' . http_build_query(['id' => $this->content->id, 'relations' => ['modifications']]));

        $response->assertOk();
        expect($response->json('data.modifications'))->toHaveCount(1);
    })->with(['update', 'approve']);
});
