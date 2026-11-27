<?php

declare(strict_types=1);

namespace Modules\CMS\Import\Support;

use Modules\CMS\Import\Dto\ImportGraphDto;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable as CmsPresettable;
use Modules\CMS\Models\Preset;
use Modules\Core\Casts\FieldType;
use Modules\Core\Models\Field;
use Modules\Core\Services\PresetVersioningService;
use RuntimeException;

final class ImportPresetProvisioner
{
    /**
     * Presets already provisioned in this run, keyed by connection, entity type,
     * preset and preferred entity. Every graph of a run names the same few
     * presets, so checking them and their fields again for each graph only
     * repeats the same queries. {@see reset()} drops them when a graph fails,
     * because its rollback may have removed what they point at.
     *
     * @var array<string, CmsPresettable>
     */
    private array $provisioned = [];

    public function __construct(
        private readonly PresetVersioningService $preset_versioning_service,
        private readonly EntityPresetResolver $entity_preset_resolver,
    ) {}

    public function provisionFromGraph(ImportGraphDto $graph, ?ImportConnectionContext $context = null): void
    {
        $context ??= new ImportConnectionContext(new Entity);
        $this->assertContext($context);
        $this->ensurePreset($graph->content->entityName, $graph->content->presetName, $context, $graph->content->preferredEntityName);

        foreach ($graph->categories as $category) {
            $this->ensurePreset($category->entityName, $category->presetName, $context, $category->preferredEntityName);
        }

        foreach ($graph->contributors as $contributor) {
            $this->ensurePreset($contributor->entityName, $contributor->presetName, $context, $contributor->preferredEntityName);
        }
    }

    public function reset(): void
    {
        $this->provisioned = [];
        $this->entity_preset_resolver->reset();
    }

    public function assertContext(ImportConnectionContext $context): void
    {
        $context->preflight($this->participantModelClasses());
    }

    /**
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public function participantModelClasses(): array
    {
        return [Entity::class, Preset::class, CmsPresettable::class, Field::class];
    }

    /**
     * Provisions the preset an import needs on an entity that already exists.
     *
     * Entities are the project's own vocabulary, so the import never creates one:
     * {@see EntityPresetResolver} picks the entity of the requested type, and a
     * missing or ambiguous type stops the run. Presets and their fields, on the
     * other hand, belong to the import and are created on demand, once per run.
     */
    public function ensurePreset(string $entityName, string $presetName, ?ImportConnectionContext $context = null, ?string $preferredEntityName = null): CmsPresettable
    {
        $context ??= new ImportConnectionContext(new Entity);
        $memo_key = implode('|', [$context->connectionName(), ImportEntityNames::normalize($entityName), $presetName, $preferredEntityName ?? '']);

        return $this->provisioned[$memo_key] ??= $this->provisionPreset($entityName, $presetName, $context, $preferredEntityName);
    }

    private function provisionPreset(string $entityName, string $presetName, ImportConnectionContext $context, ?string $preferredEntityName): CmsPresettable
    {
        $entity_id = $this->entity_preset_resolver->entityId($entityName, $context, $preferredEntityName);
        $entity = $context->model(Entity::class)->newQuery()->whereKey($entity_id)->firstOrFail();

        $preset = $context->model(Preset::class)->newQuery()->firstOrCreate(
            ['entity_id' => $entity->id, 'name' => $presetName],
            ['entity_id' => $entity->id, 'name' => $presetName],
        );

        $definitions = $this->fieldDefinitions(ImportEntityNames::normalize($entityName), $presetName);

        if ($definitions !== []) {
            $this->syncFields($preset, $definitions, $context);
        }

        $active = $context->model(CmsPresettable::class)->newQuery()
            ->where('preset_id', $preset->id)
            ->where('entity_id', $entity->id)
            ->whereNull('deleted_at')
            ->latest('version')
            ->first();

        if ($active instanceof CmsPresettable) {
            return $active;
        }

        $version = $this->preset_versioning_service->createVersion($preset);

        // The service builds the presettable of the preset's own module, a CMS one here.
        if (! $version instanceof CmsPresettable) {
            throw new RuntimeException('The CMS preset was versioned as ' . $version::class . ', not as a CMS presettable.');
        }

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function configuredPresets(): array
    {
        /** @var array<string, mixed> $presets */
        return config('cms.import.presets', []);
    }

    /**
     * @return array<string, array{type: FieldType, translatable?: bool, required?: bool}>
     */
    private function fieldDefinitions(string $entityName, string $presetName): array
    {
        /** @var array<string, array<string, array{type: string, translatable?: bool, required?: bool}>> $presets */
        $presets = config('cms.import.presets', []);

        return $presets[$entityName][$presetName] ?? [];
    }

    /**
     * @param  array<string, array{type: FieldType|string, translatable?: bool, required?: bool, options?: array<string, mixed>}>  $definitions
     */
    private function syncFields(Preset $preset, array $definitions, ImportConnectionContext $context): void
    {
        $changed = false;

        foreach ($definitions as $field_name => $definition) {
            $field = $this->ensureField($field_name, $definition, $changed, $context);

            if (! $preset->fields()->wherePivot('field_id', $field->id)->exists()) {
                $preset->fields()->attach($field->id, [
                    'default' => null,
                    'is_required' => (bool) ($definition['required'] ?? false),
                ]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->preset_versioning_service->createVersion($preset);
        }
    }

    /**
     * Field-level attributes (type, options, is_translatable) are the source of
     * truth captured by the presettable snapshot, so they must live on the Field
     * itself and never on the fieldable pivot.
     *
     * @param  array{type: FieldType|string, translatable?: bool, required?: bool, options?: array<string, mixed>}  $definition
     */
    private function ensureField(string $fieldName, array $definition, bool &$changed, ImportConnectionContext $context): Field
    {
        $type = $definition['type'] instanceof FieldType
            ? $definition['type']
            : FieldType::from((string) $definition['type']);
        $is_translatable = (bool) ($definition['translatable'] ?? false);
        $options = (object) ($definition['options'] ?? []);

        $field_model = $context->model(Field::class);
        $field = $field_model->newQuery()->where('name', $fieldName)->first();

        if (! $field instanceof Field) {
            $field = $field_model->newInstance();
            $field->name = $fieldName;
            $field->type = $type;
            $field->is_translatable = $is_translatable;
            $field->options = $options;
            $field->save();
            $changed = true;

            return $field;
        }

        // type and is_translatable are frozen once a field exists (they define
        // where content data is stored). A mismatch is a modeling conflict that
        // must be surfaced, not silently changed: use a distinct field name.
        if ($field->type !== $type || $is_translatable !== (bool) $field->is_translatable) {
            throw new RuntimeException("Field [{$fieldName}] already exists with a different type/translatability; use a distinct field name.");
        }

        return $field;
    }
}
