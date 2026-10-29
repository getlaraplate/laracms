<?php

declare(strict_types=1);

namespace Modules\CMS\Database\Seeders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentRating;
use Modules\CMS\Models\ContentReference;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Preset;
use Modules\CMS\Models\Tag;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\FieldType;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Field;
use Modules\Core\Models\Media;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Seeding\SeedReconciler;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Services\PresetVersioningService;
use Modules\Core\Services\SettingsCacheCoordinator;
use Modules\Core\Support\PermissionName;
use Spatie\Permission\PermissionRegistrar;

final class CMSDatabaseSeeder extends Seeder
{
    private const string PUBLISHER_ROLE = 'publisher';

    /**
     * @var Collection<string, Entity>
     */
    private Collection $entities;

    // /**
    //  * @var Collection<string, Preset>
    //  */
    // private Collection $presets;

    /**
     * @var Collection<string, Field>
     */
    private Collection $fields;

    /**
     * @return array<int, array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}>
     */
    public static function runtimeSettingDefinitions(): array
    {
        return [
            self::setting('geocoding.cache_ttl', 604800, SettingTypeEnum::Integer, 'cms', 'Geocoding cache TTL in seconds'),
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->defaultSettings();

        Model::unguarded(function (): void {
            $this->defaultFields();
            $this->defaultEntities();
            $this->defaultRoles();
            $this->defaultGuestApiGrants();
            $this->defaultSharedTableAcls();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The orchestrator already flushes the settings cache once after every node succeeds
        // (SeedOrchestrator::run()); a targeted flush here keeps standalone runs of this seeder
        // (module:seed CMS, tests calling $this->seed(CMSDatabaseSeeder::class)) correct too,
        // without the blanket `cache:clear` this used to call.
        app(SettingsCacheCoordinator::class)->flushAll();
        DynamicContentsService::getInstance()->clearAllCaches();
    }

    /**
     * @return array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}
     */
    private static function setting(string $name, mixed $value, SettingTypeEnum $type, string $group, string $description, ?array $choices = null): array
    {
        return [
            'name' => $name,
            'value' => $value,
            'encrypted' => false,
            'choices' => $choices,
            'type' => $type,
            'group_name' => $group,
            'description' => $description,
        ];
    }

    /**
     * The condition "published, visible and valid by date" of a content: it starts, and has not ended or never ends.
     * Qualified by the table when it filters the contents themselves, bare when it is nested in a relation filter
     * and evaluated on the related content.
     */
    /**
     * A condition on the entity type of the related record, as a relation filter: a dotted column path is not a
     * column of the table the ACL narrows. It keeps a table shared with other modules to its CMS rows.
     */
    private static function cmsEntityFilter(): RelationFilter
    {
        return new RelationFilter('entity', new FiltersGroup(
            filters: [new Filter('type', EntityType::values(), FilterOperator::In)],
            operator: WhereClause::And,
        ));
    }

    /**
     * The categories an anonymous reader sees: the CMS rows of the shared taxonomies table, active, and inside
     * their validity window, the same window as a published content. Expressed with the `valid` and `active`
     * scopes of the taxonomy, so the window is not copied into the ACL. Contents keep column conditions: search
     * engines never receive a scope node, so a scope there would skew the totals of an anonymous content search.
     */
    private static function publicCategoryFilters(): FiltersGroup
    {
        return new FiltersGroup(
            filters: [
                new ScopeFilter('valid'),
                new ScopeFilter('active'),
                new RelationFilter('presettable', new FiltersGroup([self::cmsEntityFilter()])),
            ],
            operator: WhereClause::And,
        );
    }

    private static function publishedContentFilters(?string $table = null): FiltersGroup
    {
        $prefix = $table === null ? '' : "{$table}.";

        return new FiltersGroup(
            filters: [
                new Filter("{$prefix}valid_from", '@now', FilterOperator::LessEquals),
                new FiltersGroup(
                    filters: [
                        new Filter("{$prefix}valid_to", '@now', FilterOperator::GreatEquals),
                        new Filter("{$prefix}valid_to", null, FilterOperator::Equals),
                    ],
                    operator: WhereClause::Or,
                ),
            ],
            operator: WhereClause::And,
        );
    }

    private function defaultSettings(): void
    {
        $this->logOperation(Setting::class);

        $outcome = app(SeedReconciler::class)->reconcile(
            self::internalSettingsDefinition('CMS', self::runtimeSettingDefinitions()),
        );

        $this->logReconciliation($outcome);
    }

    private function defaultFields(): void
    {
        $this->logOperation(Field::class);

        $field_model = new Field;
        $this->fields = $field_model->newQuery()->withoutGlobalScopes()->get()->keyBy('name');
        $created = 0;
        $unchanged = 0;

        $field_model->getConnection()->transaction(function () use (&$created, &$unchanged): void {
            foreach (['subtitle'] as $field) {
                if (! $this->fields->has($field)) {
                    $this->fields->put($field, $this->create(Field::class, ['name' => $field, 'type' => FieldType::Text, 'options' => (object) ['max_length' => 255], 'is_translatable' => true]));
                    $created++;
                } else {
                    $unchanged++;
                }
            }

            foreach (['short_content'] as $field) {
                if (! $this->fields->has($field)) {
                    $this->fields->put($field, $this->create(Field::class, ['name' => $field, 'type' => FieldType::Textarea, 'options' => (object) ['max_length' => 65535], 'is_translatable' => true]));
                    $created++;
                } else {
                    $unchanged++;
                }
            }

            foreach (['content'] as $field) {
                if (! $this->fields->has($field)) {
                    $this->fields->put($field, $this->create(Field::class, ['name' => $field, 'type' => FieldType::Editor, 'options' => (object) [], 'is_translatable' => true]));
                    $created++;
                } else {
                    $unchanged++;
                }
            }

            foreach (['period_from', 'period_to'] as $field) {
                if (! $this->fields->has($field)) {
                    $this->fields->put($field, $this->create(Field::class, ['name' => $field, 'type' => FieldType::Datetime, 'options' => (object) ['format' => 'Y-m-d H:i:s'], 'is_translatable' => false]));
                    $created++;
                } else {
                    $unchanged++;
                }
            }

            $field = 'public_email';

            if (! $this->fields->has($field)) {
                $this->fields->put($field, $this->create(Field::class, ['name' => $field, 'type' => FieldType::Email, 'options' => (object) [], 'is_translatable' => false]));
                $created++;
            } else {
                $unchanged++;
            }

            $field = 'phone';

            if (! $this->fields->has($field)) {
                $this->fields->put($field, $this->create(Field::class, ['name' => $field, 'type' => FieldType::Phone, 'options' => (object) [], 'is_translatable' => false]));
                $created++;
            } else {
                $unchanged++;
            }

            foreach (['website', 'linkedin', 'twitter', 'facebook', 'instagram'] as $field) {
                if (! $this->fields->has($field)) {
                    $this->fields->put($field, $this->create(Field::class, ['name' => $field, 'type' => FieldType::Url, 'options' => (object) [], 'is_translatable' => false]));
                    $created++;
                } else {
                    $unchanged++;
                }
            }
        });

        $this->logOutcome($created, 0, $unchanged);
    }

    private function defaultEntities(): void
    {
        $this->logOperation(Entity::class);

        $entity_model = new Entity;
        $this->entities = $entity_model->newQuery()->withoutGlobalScopes()->get()->keyBy('name');
        $created = 0;
        $unchanged = 0;

        $entity_model->getConnection()->transaction(function () use (&$created, &$unchanged): void {
            $standard = 'standard';

            $entities = [
                [
                    'name' => 'post',
                    'type' => EntityType::Contents,
                    'preset' => 'standard',
                    'is_default' => true,
                    'required_fields' => ['content'],
                    'optional_fields' => ['subtitle', 'short_content'],
                ],
                [
                    'name' => 'event',
                    'type' => EntityType::Contents,
                    'preset' => 'standard',
                    'is_default' => false,
                    'required_fields' => ['content', 'period_from'],
                    'optional_fields' => ['subtitle', 'short_content', 'period_to'],
                ],
                [
                    'name' => 'contributor',
                    'type' => EntityType::Contributors,
                    'preset' => 'standard',
                    'is_default' => true,
                    'required_fields' => ['content'],
                    'optional_fields' => ['public_email', 'phone', 'website', 'linkedin', 'twitter', 'facebook', 'instagram'],
                ],
                [
                    'name' => 'category',
                    'type' => EntityType::Categories,
                    'preset' => 'standard',
                    'is_default' => true,
                    'required_fields' => ['content'],
                    'optional_fields' => ['short_content'],
                ],
            ];

            foreach ($entities as $entity) {
                if (! $this->entities->has($entity['name'])) {
                    /** @var Entity $entity */
                    $new_entity = $this->create(Entity::class, ['name' => $entity['name'], 'type' => $entity['type'], 'is_default' => $entity['is_default']]);
                    $this->entities->put($entity['name'], $new_entity);

                    /** @var Preset $preset */
                    $preset = $this->create(Preset::class, ['name' => $standard, 'entity_id' => $new_entity->id]);

                    // required fields
                    if ($entity['required_fields'] !== []) {
                        $fields = $this->fields->filter(fn (Field $field): bool => in_array($field->name, $entity['required_fields'], true));

                        foreach ($fields as $field) {
                            $this->assignFieldToPreset($preset, $field, true);
                        }
                    }

                    // optional fields
                    if ($entity['optional_fields'] !== []) {
                        $fields = $this->fields->filter(fn (Field $field): bool => in_array($field->name, $entity['optional_fields'], true));

                        foreach ($fields as $field) {
                            $this->assignFieldToPreset($preset, $field, false);
                        }
                    }

                    // Create presettable with a frozen fields snapshot after all fields are attached.
                    // Must be called after every field change since BelongsToMany bulk operations
                    // do not fire pivot model events that would otherwise trigger versioning.
                    resolve(PresetVersioningService::class)->createVersion($preset);

                    $created++;
                } else {
                    $unchanged++;
                }
            }
        });

        $this->logOutcome($created, 0, $unchanged);
    }

    private function defaultRoles(): void
    {
        $this->logOperation(Role::class);

        /** @var class-string<Role> $role_class */
        $role_class = config('permission.models.role');

        /** @var class-string<Permission> $permission_class */
        $permission_class = config('permission.models.permission');

        // A role is identified by its name and its guard: the staff roles live on the guard of `/app`, and an
        // `api` role may share a name with one of them without receiving its grants.
        $web = CoreDatabaseSeeder::defaultGuard();

        // Permissions are named after the model table, so the CMS models must be the source:
        // categories and presets live in the shared `core_taxonomies` / `core_presets` tables,
        // which are narrowed to the CMS entities by `defaultSharedTableAcls()`.
        $cms_tables = array_map(
            static fn (string $model): string => (new $model)->getTable(),
            [Content::class, Category::class, Preset::class, Comment::class],
        );

        $name = self::PUBLISHER_ROLE;
        $created = 0;
        $unchanged = 0;

        if (! $role_class::query()->where(['name' => $name, 'guard_name' => $web])->exists()) {
            $this->create($role_class, [
                'name' => $name,
                'guard_name' => $web,
                'permissions' => fn () => $permission_class::query()
                    ->where('guard_name', $web)
                    ->whereIn('table_name', $cms_tables)
                    ->where(static fn ($query) => $query->where('name', 'like', '%.' . ActionEnum::Approve->value)
                        ->orWhere('name', 'like', '%.' . ActionEnum::Select->value))
                    ->get(),
            ]);
            $created++;
        } else {
            $unchanged++;
        }

        match ($this->grantPublisherRelatedReads($role_class, $permission_class, $name, $web)) {
            true => $created++,
            false => $unchanged++,
            null => null,
        };

        $admin = $role_class::query()->where(['name' => CoreDatabaseSeeder::getDefaultUserRoles()['admin'], 'guard_name' => $web])->first();

        $admin?->permissions()->syncWithoutDetaching(
            $permission_class::query()
                ->where('guard_name', $web)
                ->where(static fn ($query) => $query->whereIn('table_name', $cms_tables)
                    ->orWhere('name', 'like', '%.' . ActionEnum::Select->value))
                ->whereNot('name', 'like', '%.' . ActionEnum::Lock->value)
                ->pluck('id'),
        );

        $this->logOutcome($created, 0, $unchanged);
    }

    /**
     * Related records obey their own permission, so the publisher reads the entities its screens load with a
     * content: the tags, locations and contributors of the content form, the pending modifications of the
     * content list, and the settings the content list reads (the per-model settings it hydrates are checked
     * against `core_settings.select`), the latter narrowed to the public ones by an ACL, and the CMS entities that
     * categories and presets resolve (`core_entities.select`, narrowed to the CMS types by
     * {@see self::defaultSharedTableAcls()}). Given on every run, so an existing publisher role gains them too.
     * Returns what {@see self::ensureAcl()} did with the settings ACL, null when there is no publisher.
     *
     * @param  class-string<Role>  $role_class
     * @param  class-string<Permission>  $permission_class
     */
    private function grantPublisherRelatedReads(string $role_class, string $permission_class, string $name, string $guard): ?bool
    {
        $publisher = $role_class::query()->where(['name' => $name, 'guard_name' => $guard])->first(['id', 'guard_name']);

        if ($publisher === null) {
            return null;
        }

        $names = array_map(
            static fn (string $model): string => PermissionName::forClass($model, ActionEnum::Select->value),
            [Tag::class, Location::class, Contributor::class, Modification::class, Setting::class, Entity::class],
        );

        $publisher->permissions()->syncWithoutDetaching(
            $permission_class::query()->whereIn('name', $names)->where('guard_name', $guard)->pluck('id'),
        );

        $settings_table = (new Setting)->getTable();

        return $this->ensureAcl(
            $permission_class,
            $publisher,
            PermissionName::forClass(Setting::class, ActionEnum::Select->value),
            new FiltersGroup(
                filters: [new Filter("{$settings_table}.is_public", true, FilterOperator::Equals)],
                operator: WhereClause::And,
            ),
            'Publishers read only the public settings.',
        );
    }

    /**
     * Grant the `guest` role of the api guard the CMS part of the public list, each entity narrowed by its ACL:
     * the contents currently published, the references and media of those contents only (so a media of an
     * unpublished content, or a ticket attachment, is never public), the active and valid CMS categories (their
     * table is shared with other modules), and tags, locations, contributors, comments and ratings with no
     * default ACL. Validity (valid_from/valid_to) is not a global scope: the
     * `@now` placeholder of the ACL limits the anonymous reader to the current publication window, while staff
     * roles (no ACL on the permission) read every content.
     *
     * Core grants the settings of the list on the same role.
     */
    private function defaultGuestApiGrants(): void
    {
        $this->logOperation(ACL::class);

        /** @var class-string<Permission> $permission_class */
        $permission_class = config('permission.models.permission');

        /** @var class-string<Role> $role_class */
        $role_class = config('permission.models.role');

        $guest = $role_class::query()
            ->where(['name' => CoreDatabaseSeeder::getDefaultUserRoles()['guest'], 'guard_name' => CoreDatabaseSeeder::API_GUARD])
            ->first(['id', 'guard_name']);

        if ($guest === null) {
            $this->command?->line('    - api guest role missing, skipping public grants');

            return;
        }

        $grants = [
            Content::class => new FiltersGroup(filters: self::publishedContentFilters((new Content)->getTable())->filters, operator: WhereClause::And),
            ContentReference::class => new FiltersGroup([new RelationFilter('content', self::publishedContentFilters())]),
            Media::class => new FiltersGroup([new RelationFilter('model', self::publishedContentFilters(), [Content::class])]),
            Category::class => self::publicCategoryFilters(),
            Tag::class => null,
            Location::class => null,
            Contributor::class => null,
            Comment::class => null,
            ContentRating::class => null,
        ];

        $created = 0;
        $unchanged = 0;

        foreach ($grants as $model_class => $filters) {
            $permission_name = PermissionName::forClass($model_class, ActionEnum::Select->value);
            $permission_id = $permission_class::query()->where(['name' => $permission_name, 'guard_name' => $guest->guard_name])->value('id');

            if ($permission_id === null) {
                $this->command?->line("    - permission {$permission_name} missing, skipping its public grant");

                continue;
            }

            if ($guest->permissions()->syncWithoutDetaching([$permission_id])['attached'] === []) {
                $unchanged++;
            } else {
                $created++;
            }

            if ($filters instanceof FiltersGroup) {
                match ($this->ensureAcl($permission_class, $guest, $permission_name, $filters, 'Anonymous readers of the api read only what is published.')) {
                    true => $created++,
                    false => $unchanged++,
                    null => null,
                };
            }
        }

        $this->logOutcome($created, 0, $unchanged);
    }

    /**
     * Create the ACL of a permission for a role unless one exists: a re-seed never overwrites what an operator changed.
     * Returns true when it created the ACL, false when one existed, null when the permission is missing.
     *
     * @param  class-string<Permission>  $permission_class
     */
    private function ensureAcl(string $permission_class, Role $role, string $permission_name, FiltersGroup $filters, string $description): ?bool
    {
        $permission = $permission_class::query()->where(['name' => $permission_name, 'guard_name' => $role->guard_name])->first(['id']);

        if ($permission === null) {
            $this->command?->line("    - permission {$permission_name} missing, skipping its ACL");

            return null;
        }

        if (ACL::query()->where('permission_id', $permission->id)->where('role_id', $role->id)->exists()) {
            return false;
        }

        $acl = new ACL;
        $acl->setSkipValidation(true);
        $acl->forceFill([
            'permission_id' => $permission->id,
            'role_id' => $role->id,
            'filters' => $filters,
            'description' => $description,
            'unrestricted' => false,
            'priority' => 100,
            'is_active' => true,
        ]);
        $acl->save();

        return true;
    }

    /**
     * Seed the row-level ACLs that keep the CMS roles inside the CMS entities on the tables
     * every module shares (`core_taxonomies`, `core_presets`). Their permissions are not
     * CMS-specific, so granting `select` to the `publisher` role alone would expose the
     * taxonomies and presets of ERP and every other module; the ACL narrows the role to rows
     * whose entity type is a CMS one.
     */
    private function defaultSharedTableAcls(): void
    {
        $this->logOperation(ACL::class);

        /** @var class-string<Permission> $permission_class */
        $permission_class = config('permission.models.permission');

        /** @var class-string<Role> $role_class */
        $role_class = config('permission.models.role');

        $publisher = $role_class::query()->where(['name' => self::PUBLISHER_ROLE, 'guard_name' => CoreDatabaseSeeder::defaultGuard()])->first(['id', 'guard_name']);

        if ($publisher === null) {
            $this->command?->line('    - publisher role missing, skipping shared-table ACLs');

            return;
        }

        $cms_entity = self::cmsEntityFilter();

        $acls = [
            Category::class => new FiltersGroup([new RelationFilter('presettable', new FiltersGroup([$cms_entity]))]),
            Preset::class => new FiltersGroup([$cms_entity]),
            Entity::class => new FiltersGroup([new Filter('type', EntityType::values(), FilterOperator::In)]),
        ];

        $created = 0;
        $unchanged = 0;

        foreach ($acls as $model_class => $filters) {
            match ($this->ensureAcl(
                $permission_class,
                $publisher,
                PermissionName::forModel(new $model_class, ActionEnum::Select->value),
                $filters,
                'Publishers read only the CMS rows of a table shared with other modules.',
            )) {
                true => $created++,
                false => $unchanged++,
                null => null,
            };
        }

        $this->logOutcome($created, 0, $unchanged);
    }

    private function assignFieldToPreset(Preset $preset, Field $field, bool $is_required): void
    {
        $pivotAttributes = ['is_required' => $is_required, 'default' => $this->getDefaultFieldValue($field, $is_required), 'preset_id' => $preset->id];
        $preset->fields()->attach($field->id, $pivotAttributes);
    }

    private function getDefaultFieldValue(Field $field, bool $is_required): mixed
    {
        return match ($field->type) {
            FieldType::Select && isset($field->options->multiple) && $field->options->multiple => [],
            FieldType::Switch => $is_required,
            FieldType::Checkbox => [],
            default => null,
        };
    }
}
