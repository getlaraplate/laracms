<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentRating;
use Modules\CMS\Models\ContentReference;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Preset;
use Modules\CMS\Models\Tag;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Media;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Support\PermissionName;

uses(TestCase::class, RefreshDatabase::class);

it('seeds CMS runtime settings stamped with the CMS module', function (): void {
    $this->seed(CMSDatabaseSeeder::class);

    $names = collect(CMSDatabaseSeeder::runtimeSettingDefinitions())->pluck('name');

    $settings = Setting::query()->withoutGlobalScopes()->whereIn('name', $names)->get();

    expect($settings)->toHaveCount($names->count())
        ->and($settings->pluck('module')->unique()->all())->toBe(['CMS']);
});

it('is idempotent and leaves an operator-changed value untouched on a second run', function (): void {
    $this->seed(CMSDatabaseSeeder::class);

    Setting::query()->withoutGlobalScopes()
        ->where('name', 'geocoding.cache_ttl')
        ->update(['value' => json_encode(1), 'description' => 'drifted']);

    $this->seed(CMSDatabaseSeeder::class);

    $setting = Setting::query()->withoutGlobalScopes()
        ->where('name', 'geocoding.cache_ttl')->sole();

    expect($setting->value)->toBe(1)
        ->and($setting->description)->toBe('Geocoding cache TTL in seconds');
});

it('gives the publisher the shared taxonomy permissions, narrowed to CMS rows by an ACL', function (): void {
    $this->artisan('permission:refresh')->assertSuccessful();
    $this->seed(CMSDatabaseSeeder::class);

    $publisher = Role::query()->where(['name' => 'publisher', 'guard_name' => 'web'])->firstOrFail();
    $permission = Permission::query()->where(['name' => 'default.core_taxonomies.select', 'guard_name' => 'web'])->firstOrFail();

    expect($publisher->permissions->pluck('name'))->toContain('default.core_taxonomies.select', 'default.cms_contents.select');

    $acl = ACL::query()->where('permission_id', $permission->id)->where('role_id', $publisher->id)->sole();

    $relation = $acl->filters->filters[0];

    expect($relation)->toBeInstanceOf(RelationFilter::class)
        ->and($relation->relation)->toBe('presettable')
        ->and($relation->filters->filters[0])->toBeInstanceOf(RelationFilter::class)
        ->and($relation->filters->filters[0]->relation)->toBe('entity')
        ->and($relation->filters->filters[0]->filters->filters[0]->property)->toBe('type');

    $this->seed(CMSDatabaseSeeder::class);

    expect(ACL::query()->where('permission_id', $permission->id)->where('role_id', $publisher->id)->count())->toBe(1);
});

/**
 * Seeds the roles and the grants the way a deployment does: permissions first, then Core, then CMS.
 */
function seedCmsRoles(object $test): void
{
    $test->artisan('permission:refresh', ['--quiet' => true])->assertSuccessful();
    $test->seed(CoreDatabaseSeeder::class);
    $test->seed(CMSDatabaseSeeder::class);
}

/**
 * The public list of the `guest` role of the `api` guard: the entities an anonymous reader may select.
 *
 * @return list<class-string<Illuminate\Database\Eloquent\Model>>
 */
function publicApiEntities(): array
{
    return [Setting::class, Content::class, ContentReference::class, Media::class, Category::class, Tag::class, Location::class, Contributor::class, Comment::class, ContentRating::class];
}

/**
 * @return list<string>
 */
function seededPermissionNames(string $role_name, string $guard): array
{
    return Role::query()->where(['name' => $role_name, 'guard_name' => $guard])->firstOrFail()
        ->permissions()->pluck('name')->sort()->values()->all();
}

it('gives the guest of the api guard exactly the public list, as select permissions of the api guard', function (): void {
    seedCmsRoles($this);

    $expected = collect(publicApiEntities())->map(fn (string $model): string => PermissionName::forClass($model, 'select'))->sort()->values()->all();
    $guest = Role::query()->where(['name' => config('permission.roles.guest'), 'guard_name' => 'api'])->firstOrFail();

    expect(seededPermissionNames((string) config('permission.roles.guest'), 'api'))->toBe($expected)
        ->and($guest->permissions()->where('guard_name', '!=', 'api')->count())->toBe(0);
});

it('gives the guest of the web guard nothing and no staff role any api permission', function (): void {
    seedCmsRoles($this);

    expect(seededPermissionNames((string) config('permission.roles.guest'), 'web'))->toBe([]);

    foreach (['admin', 'publisher', 'system'] as $key) {
        $name = $key === 'publisher' ? 'publisher' : (string) config("permission.roles.{$key}");

        expect(Role::query()->where('name', $name)->pluck('guard_name')->all())->toBe(['web'])
            ->and(Role::query()->where('name', $name)->firstOrFail()->permissions()->where('guard_name', 'api')->count())->toBe(0);
    }
});

it('limits the guest of the api guard on the contents, references and media, and leaves tags, locations, contributors, comments and ratings unrestricted', function (): void {
    seedCmsRoles($this);

    $guest = Role::query()->where(['name' => config('permission.roles.guest'), 'guard_name' => 'api'])->firstOrFail();
    $acl_for = static fn (string $model): ?ACL => ACL::query()
        ->where('role_id', $guest->id)
        ->where('permission_id', Permission::query()->where(['name' => PermissionName::forClass($model, 'select'), 'guard_name' => 'api'])->value('id'))
        ->first();

    foreach ([Tag::class, Location::class, Contributor::class, Comment::class, ContentRating::class] as $model) {
        expect($acl_for($model))->toBeNull($model . ' has no default ACL');
    }

    $published = static fn (FiltersGroup $group): bool => (new FiltersGroup(
        filters: [
            new Filter('valid_from', '@now', FilterOperator::LessEquals),
            new FiltersGroup(
                filters: [new Filter('valid_to', '@now', FilterOperator::GreatEquals), new Filter('valid_to', null, FilterOperator::Equals)],
                operator: WhereClause::Or,
            ),
        ],
        operator: WhereClause::And,
    ))->toArray() === $group->toArray();

    $contents = $acl_for(Content::class);
    $references = $acl_for(ContentReference::class);
    $media = $acl_for(Media::class);

    expect($contents)->not->toBeNull()
        ->and($contents->filters->filters[0]->property)->toBe('cms_contents.valid_from')
        ->and($references->filters->filters)->toHaveCount(1)
        ->and($references->filters->filters[0])->toBeInstanceOf(RelationFilter::class)
        ->and($references->filters->filters[0]->relation)->toBe('content')
        ->and($references->filters->filters[0]->morph_types)->toBeNull()
        ->and($published($references->filters->filters[0]->filters))->toBeTrue()
        ->and($media->filters->filters)->toHaveCount(1)
        ->and($media->filters->filters[0])->toBeInstanceOf(RelationFilter::class)
        ->and($media->filters->filters[0]->relation)->toBe('model')
        ->and($media->filters->filters[0]->morph_types)->toBe([Content::class])
        ->and($published($media->filters->filters[0]->filters))->toBeTrue();
});

it('limits the guest of the api guard to the active, valid categories of the CMS in the shared taxonomies table, by scopes', function (): void {
    seedCmsRoles($this);

    $guest = Role::query()->where(['name' => config('permission.roles.guest'), 'guard_name' => 'api'])->firstOrFail();
    $permission_id = Permission::query()->where(['name' => PermissionName::forClass(Category::class, 'select'), 'guard_name' => 'api'])->value('id');
    $filters = ACL::query()->where(['role_id' => $guest->id, 'permission_id' => $permission_id])->sole()->filters->filters;
    $entity = $filters[2]->filters->filters[0];

    expect($filters)->toHaveCount(3)
        ->and($filters[0])->toBeInstanceOf(ScopeFilter::class)
        ->and($filters[0]->scope)->toBe('valid')
        ->and($filters[0]->parameters)->toBe([])
        ->and($filters[1])->toBeInstanceOf(ScopeFilter::class)
        ->and($filters[1]->scope)->toBe('active')
        ->and($filters[2])->toBeInstanceOf(RelationFilter::class)
        ->and($filters[2]->relation)->toBe('presettable')
        ->and($entity)->toBeInstanceOf(RelationFilter::class)
        ->and($entity->relation)->toBe('entity')
        ->and($entity->filters->filters[0]->value)->toBe(EntityType::values());
});

it('leaves a stored column ACL of the guest categories untouched on a re-seed', function (): void {
    $this->artisan('permission:refresh', ['--quiet' => true])->assertSuccessful();
    $this->seed(CoreDatabaseSeeder::class);

    $guest = Role::query()->where(['name' => config('permission.roles.guest'), 'guard_name' => 'api'])->firstOrFail();
    $permission = Permission::query()->where(['name' => PermissionName::forClass(Category::class, 'select'), 'guard_name' => 'api'])->firstOrFail();
    $column_filters = new FiltersGroup([new Filter('core_taxonomies.is_active', true, FilterOperator::Equals)]);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $guest->id,
        'filters' => $column_filters,
        'description' => 'Stored by an earlier installation.',
        'unrestricted' => false,
        'priority' => 100,
        'is_active' => true,
    ])->save();

    $this->seed(CMSDatabaseSeeder::class);

    $stored = ACL::query()->where(['role_id' => $guest->id, 'permission_id' => $permission->id])->sole()->filters->filters;

    expect($stored)->toHaveCount(1)
        ->and($stored[0])->toBeInstanceOf(Filter::class)
        ->and($stored[0]->property)->toBe('core_taxonomies.is_active');
});

it('gives the publisher the settings it needs to open the staff screens, narrowed to the public ones, and keeps its related reads', function (): void {
    seedCmsRoles($this);

    $publisher = Role::query()->where(['name' => 'publisher', 'guard_name' => 'web'])->firstOrFail();
    $names = $publisher->permissions->pluck('name');

    foreach ([Setting::class, Tag::class, Location::class, Contributor::class, Entity::class] as $model) {
        expect($names)->toContain(PermissionName::forClass($model, 'select'));
    }

    expect($publisher->permissions->pluck('guard_name')->unique()->all())->toBe(['web']);

    $settings_permission = Permission::query()->where(['name' => PermissionName::forClass(Setting::class, 'select'), 'guard_name' => 'web'])->firstOrFail();
    $acl = ACL::query()->where(['permission_id' => $settings_permission->id, 'role_id' => $publisher->id])->sole();

    expect($acl->filters->filters[0]->property)->toBe('core_settings.is_public');

    $entities_permission = Permission::query()->where(['name' => PermissionName::forClass(Entity::class, 'select'), 'guard_name' => 'web'])->firstOrFail();
    $entities_acl = ACL::query()->where(['permission_id' => $entities_permission->id, 'role_id' => $publisher->id])->sole();

    expect($entities_acl->filters->filters[0]->property)->toBe('type')
        ->and($entities_acl->filters->filters[0]->value)->toBe(EntityType::values());
});

it('grants by guard: an api role that shares the name of a web role never receives the web grants', function (): void {
    $this->artisan('permission:refresh', ['--quiet' => true])->assertSuccessful();
    $this->seed(CoreDatabaseSeeder::class);

    $api_publisher = Role::query()->create(['name' => 'publisher', 'guard_name' => 'api']);
    $api_admin = Role::query()->create(['name' => (string) config('permission.roles.admin'), 'guard_name' => 'api']);

    $this->seed(CMSDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);

    $web_publisher = Role::query()->where(['name' => 'publisher', 'guard_name' => 'web'])->firstOrFail();

    expect($web_publisher->permissions->pluck('name'))->toContain(PermissionName::forClass(Content::class, 'select'))
        ->and($api_publisher->fresh()->permissions()->count())->toBe(0)
        ->and($api_admin->fresh()->permissions()->count())->toBe(0)
        ->and(Role::query()->where('name', 'publisher')->count())->toBe(2);
});

it('does not duplicate a role, a grant or an ACL when the seeders run again', function (): void {
    seedCmsRoles($this);

    $before = [Role::query()->count(), ACL::query()->count(), DB::table('vend_role_has_permissions')->count()];

    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);

    expect([Role::query()->count(), ACL::query()->count(), DB::table('vend_role_has_permissions')->count()])->toBe($before);
});

it('lets the seeded publisher list on the app the categories of the CMS entities and not the ones of another module', function (): void {
    seedCmsRoles($this);
    setupCMSEntities([EntityType::Contents, EntityType::Categories]);

    $cms_category = Category::factory()->create();
    $other_category = Category::factory()->create();

    // The second category belongs to a preset of an entity of another module: same shared table, another owner.
    $presettables_table = (new Presettable)->getTable();
    $foreign_entity_id = DB::table((new Entity)->getTable())->insertGetId([
        'name' => 'foreign_entity',
        'slug' => 'foreign-entity',
        'type' => 'tickets',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $row = (array) DB::table($presettables_table)->where('id', $other_category->presettable_id)->first();
    unset($row['id'], $row['is_deleted']);
    $row['entity_id'] = $foreign_entity_id;
    $row['preset_id'] = DB::table((new Preset)->getTable())->insertGetId(['entity_id' => $foreign_entity_id, 'name' => 'default', 'created_at' => now(), 'updated_at' => now()]);
    $foreign_presettable_id = DB::table($presettables_table)->insertGetId($row);
    DB::table($other_category->getTable())->where('id', $other_category->id)->update(['presettable_id' => $foreign_presettable_id]);

    $publisher = User::factory()->create();
    $publisher->assignRole(Role::query()->where(['name' => 'publisher', 'guard_name' => 'web'])->firstOrFail());

    $response = $this->actingAs($publisher->fresh())->getJson('/app/crud/select/cms/categories');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toContain($cms_category->id)
        ->not->toContain($other_category->id);
});

it('seeds an empty list as the default of a multiple select, and nothing for a single one', function (): void {
    // The match arm read `FieldType::Select && isset(...) && ...`, a boolean compared with the enum, so it never
    // matched. No seeded field is a multiple select today; the private method is reached directly for that reason.
    $default = new ReflectionMethod(CMSDatabaseSeeder::class, 'getDefaultFieldValue');
    $seeder = resolve(CMSDatabaseSeeder::class);

    $multiple = new Modules\Core\Models\Field(['type' => Modules\Core\Casts\FieldType::Select, 'options' => ['multiple' => true]]);
    $single = new Modules\Core\Models\Field(['type' => Modules\Core\Casts\FieldType::Select, 'options' => ['multiple' => false]]);

    expect($default->invoke($seeder, $multiple, false))->toBe([])
        ->and($default->invoke($seeder, $single, false))->toBeNull();
});
