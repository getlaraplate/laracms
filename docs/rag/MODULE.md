# CMS module — content domain and media workflows

## Purpose

`CMS` manages the content lifecycle for Laraplate installations: content entities, publication records, taxonomy, media, and location-aware metadata. It builds on top of `Core` for identity, lifecycle traits (locking/approvals/validity/translations), and dynamic entity/preset/translation primitives, layering CMS-specific models, observers, Filament resources, and geocoding integration.

### Module boundaries

HTTP/Filament/Artisan entry points talk to CMS Eloquent models (`Content`, `Tag`, `Category`, `Contributor`, `Location`, `Preset`). Models extend Core abstractions (`Core\Models\Entity`, `Core\Models\Taxonomy`, `Core\Models\Preset`) and reuse Core traits (`HasLocks`, `HasApprovals`, `HasValidity`, `HasTranslations`, `HasTranslatedDynamicContents`, `HasPath`, `HasPlace`, `Searchable`). Side effects are orchestrated by the `ContentObserver` (auto-assignment of `entity_id` / `presettable_id`), the geocoding action `GeocodeLocationAction` over `IGeocodingService` (default `NominatimService`), the Spatie media library pipeline wired through the CMS `HasMultimedia` helper (which builds on the Core-owned `HasMedia` trait and `Core\Models\Media` / `vend_media` — media is a Core foundation, not CMS-owned), and `CmsGraphProvider` for Core Graph defaults. The `CMSPlugin` registers Filament resources for the panel.

```mermaid
flowchart TB
  subgraph entry [Entry points]
    Http[Http Controllers]
    Filament[Filament resources]
    Artisan[Artisan commands]
  end
  subgraph models [CMS Eloquent models]
    Content[Content]
    Tag[Tag]
    Category[Category]
    Contributor[Contributor]
    Location[Location]
    Preset[Preset]
  end
  subgraph cmsCore [CMS helpers and observers]
    Observer[ContentObserver]
    Multimedia[HasMultimedia]
    HasTags[HasTags]
    Geocode[GeocodeLocationAction]
  end
  subgraph coreInfra [Core infrastructure used]
    CoreEntity[Core Entity abstract]
    CoreTax[Core Taxonomy abstract]
    CorePreset[Core Preset abstract]
    Locks[HasLocks plus HasOptimisticLocking]
    Approvals[HasApprovals]
    Validity[HasValidity]
    I18n[HasTranslations plus HasTranslatedDynamicContents]
    Search[Searchable plus FieldDefinition]
    Graph[Core Graph provider]
    Geo[IGeocodingService]
    CoreMedia[Core HasMedia plus Media vend_media]
  end
  subgraph plug [Plugins and integrations]
    PluginCMS[CMSPlugin]
    Spatie[Spatie MediaLibrary]
    Nominatim[NominatimService]
  end

  Http --> Content
  Http --> Location
  Filament --> Content
  Filament --> Category
  Filament --> Tag
  Artisan --> Content
  Content --> Observer
  Content --> Multimedia
  Content --> HasTags
  Location --> Geocode
  Geocode --> Geo
  Geo --> Nominatim
  Content --> CoreEntity
  Category --> CoreTax
  Preset --> CorePreset
  Content --> Locks
  Content --> Approvals
  Content --> Validity
  Content --> I18n
  Content --> Search
  Content --> Graph
  Multimedia --> CoreMedia
  Multimedia --> Spatie
  CoreMedia --> Spatie
  PluginCMS -.-> Filament
```

## Main capabilities

### Module-owned bulk import command

CMS exposes `cms:import` as its explicit bulk-import destination. The command inherits option parsing, bootstrap loading, common output, resolution contracts, and transactional execution from Core's non-runnable `AbstractImportCommand`. CMS injects `CmsBulkImporterResolver` and `SiblingImportersDiscovery`, and accepts only `Modules\CMS\Import\Contracts\BulkImporterInterface` implementations.

The retained CMS marker extends Core's neutral importer contract, so existing Naxos API and SQL importers keep their namespace compatibility. CMS still owns content DTOs, `ImportPipeline`, upserters, preset provisioning, reference resolution, and post-processing. External plugins own source connectivity and mapping.

Dry-run rolls back writes on the connection declared by a connection-aware importer, or the default connection for legacy importers. It cannot reverse other connections or external side effects. `cms:import` is a bounded batch command, not a continuous synchronization engine. See `docs/IMPORTS.md` and `docs/rag/IMPORTS.md`.

### Dynamic content model: Entity, Preset, Presettable, Content

CMS sits on top of the Core dynamic-entity stack. `Modules/CMS/app/Models/Entity.php` extends `Core\Models\Entity` and exposes the `EntityType` enum with three CMS values: `CONTENTS`, `CONTRIBUTORS`, and `CATEGORIES`. Each `Entity` row drives schema and presets; a `Presettable` is a versioned snapshot of fields tied to one preset and one entity (table `presettables`). Domain rows (`Content`, `Contributor`, `Category`) carry both `entity_id` and `presettable_id`. The `ContentObserver` auto-assigns these on creation when missing; `Preset::migrateContentsToLastVersion()` reassigns existing rows to the latest snapshot when fields change.

```mermaid
flowchart LR
  subgraph cmsEntities [CMS Entity catalog]
    EntityRow["entities row<br/>type in CONTENTS or CONTRIBUTORS or CATEGORIES"]
  end
  subgraph presetLayer [Preset layer]
    Preset[presets row]
    Presettable["presettables row<br/>fields_snapshot + version"]
  end
  subgraph data [CMS data rows]
    Content[contents row]
    Contributor[contributors row]
    Category[taxonomies row]
  end

  EntityRow -->|drives schema| Presettable
  Preset --> Presettable
  Presettable -->|presettable_id| Content
  EntityRow -->|entity_id| Content
  Presettable -->|presettable_id| Contributor
  EntityRow -->|entity_id| Contributor
  Presettable -->|presettable_id| Category
  EntityRow -->|entity_id| Category
```

#### Content relationships and morph pivots

`Content` is the primary content aggregate for `EntityType::CONTENTS`. It links contributors, categories, locations, related contents, and tags via dedicated pivots: `contributables`, `categorizables` (FK on `taxonomy_id`), `locatables`, `relatables`, and the polymorphic `taggables` (used by `Content`, `Contributor`, `Category`, `Location` through `HasTags`). Translations live in `contents_translations` keyed by `content_id` + `locale` and store the user-facing fields `title`, `slug`, `components`. `Searchable` builds a Typesense schema that flattens contributors/categories/tags/locations and emits per-locale `title_{locale}` and `slug_{locale}` keys. `Content` also implements the Core contract `IProvidesRerankerText`: `rerankerTexts()` returns, for the keys of the hits to rerank, the title and the text components of the translation in the language of the search (cut to 2000 characters), because a search hit carries only the content's own columns and the text lives in `contents_translations`.

The generic CRUD `update` writes only fillable columns, so it never touches these many-to-many links by default. Reassigning them is done through the same generic `update` (`PATCH /app/crud/update/cms/contents`, `core.crud.replace`) by sending a `relations` map — e.g. `{ "id": 5, "relations": { "categories": [1,2], "tags": [] } }`. `Content` opts in by implementing `Modules\Core\Contracts\ProvidesSyncableRelations::syncableRelations()` (returning `tags`, `categories`, `locations`, `contributors`); that whitelist is the authorization boundary — the Core `CrudService::update` `sync`s only whitelisted many-to-many relations, in the same transaction as the column update, and rejects any other relation name (`400`). Each present relation key is synced wholesale (empty array clears it) and omitted keys are left untouched. The `Categorizable` pivot models the composite `(content_id, taxonomy_id)` key through the relation's foreign/related keys — like its `Locatable`/`Contributable` siblings — rather than a model-level array `primaryKey`, which is what lets `sync()` detach existing rows.

```mermaid
flowchart LR
  Content[contents]
  ContentTr["contents_translations<br/>locale, title, slug, components"]
  Cat[taxonomies <br/>type=CATEGORIES]
  Contributor[contributors]
  ContributorTr["contributors_translations"]
  Location[locations]
  Tag[tags]
  TagTr["tags_translations<br/>name, slug per locale"]
  Pivot1[categorizables]
  Pivot2[contributables]
  Pivot3[locatables]
  Pivot4[relatables]
  Pivot5["taggables<br/>polymorphic"]
  Media["media<br/>morph: model_type and model_id"]

  Content --> ContentTr
  Content --> Pivot1 --> Cat
  Content --> Pivot2 --> Contributor
  Contributor --> ContributorTr
  Content --> Pivot3 --> Location
  Content --> Pivot4 --> Content
  Content -.->|morph taggable| Pivot5 --> Tag
  Contributor -.->|morph taggable| Pivot5
  Cat -.->|morph taggable| Pivot5
  Location -.->|morph taggable| Pivot5
  Tag --> TagTr
  Content --> Media
  Contributor --> Media
  Cat --> Media
```

### Multilocale translations

Translations are first-class for `Content` (`contents_translations`), `Tag` (`tags_translations`), and `Contributor` (`contributors_translations`). The `HasTranslations` trait on the model exposes translatable fields (`title`/`slug`/`components` for content, `name`/`slug` for tags) and merges them into `toArray()` so callers see the active-locale view. `HasTranslatedDynamicContents` adds dynamic component schema and rules per locale on top. The `Searchable` mapping emits both default-locale fields and `title_{locale}` / `slug_{locale}` per available locale via `LocaleContext::getAvailable()`.

```mermaid
flowchart LR
  subgraph base [Base rows]
    C[contents]
    T[tags]
    Co[contributors]
  end
  subgraph trans [Translation rows]
    CT["contents_translations<br/>(content_id, locale)"]
    TT["tags_translations<br/>(tag_id, locale)"]
    CoT["contributors_translations<br/>(contributor_id, locale)"]
  end
  subgraph runtime [Runtime helpers]
    HasTrans[HasTranslations]
    HasTransDyn[HasTranslatedDynamicContents]
    Locale["LocaleContext.getAvailable()"]
  end
  Search[Searchable mapping]

  C --> CT
  T --> TT
  Co --> CoT
  C --> HasTrans
  T --> HasTrans
  Co --> HasTrans
  C --> HasTransDyn
  Co --> HasTransDyn
  HasTrans --> Search
  Locale --> Search
```

### Multimedia pipeline

The `HasMultimedia` helper wires Spatie MediaLibrary on `Content`, `Contributor`, and `Category`. It registers the standard collections `cover` (single file), `images`, `videos`, `audios`, `files`, and three image quality conversions (`thumb-high|mid|low`) plus matching video conversions extracting a frame at second 2. `cover` is exposed as an accessor returning the first media entry. The `Modules/CMS/app/Models/Media.php` model extends Spatie's base media adding `HasVersions` and `SoftDeletes`, and exposes an `expires_at` accessor that, while trashed, projects a future expiration timestamp using `core.soft_deletes.expiration_days`.

```mermaid
flowchart LR
  Owner["Content / Contributor / Category"]
  Trait[HasMultimedia]
  subgraph collections [Media collections]
    Cover[cover singleFile]
    Images[images]
    Videos[videos]
    Audios[audios]
    Files[files]
  end
  subgraph conv [Conversions]
    ImgC["thumb-high<br/>thumb-mid<br/>thumb-low"]
    VidC["video_thumb-* at second=2"]
  end
  Media[Spatie Media model + HasVersions + SoftDeletes]
  Expire[expires_at accessor]

  Owner --> Trait
  Trait --> Cover
  Trait --> Images
  Trait --> Videos
  Trait --> Audios
  Trait --> Files
  Cover --> ImgC
  Images --> ImgC
  Videos --> VidC
  Cover --> Media
  Images --> Media
  Videos --> Media
  Audios --> Media
  Files --> Media
  Media --> Expire
```

### Location and geocoding

`Location` extends `Core\Overrides\Model`, uses `HasPlace` to point to a canonical `places.id` row (Core), `HasSpatial` for geometry via `geolocation` Point, `HasSlug`, `HasPath`, and `HasTags`. Geocoding is an explicit action: `GeocodeLocationAction` injects `Core\Contracts\Geocoding\IGeocodingService`. The default binding is `NominatimService` (`Modules/CMS/app/Services/NominatimService.php`) which calls OpenStreetMap Nominatim and maps results into a partially-filled, **non-persisted** `Location`. The Searchable mapping prefers the canonical `Place` document fields when `place_id` resolves, otherwise emits a manual `geocode` array `[lat, lon]`.

```mermaid
flowchart LR
  Caller["Form / API request"]
  Action[GeocodeLocationAction]
  Iface[IGeocodingService]
  Nominatim[NominatimService]
  Result[GeocodingResult]
  Loc["Location (unsaved)"]
  Place["Core places (place_id)"]
  Search[Searchable document]

  Caller --> Action
  Action --> Iface
  Iface --> Nominatim
  Nominatim --> Result
  Result --> Loc
  Loc -.->|optional| Place
  Loc --> Search
  Place --> Search
```

### Tagging and taxonomy

Tags are polymorphic via the `taggables` morph pivot (`tag_id` + `taggable_id` + `taggable_type`) and translated through `tags_translations`. The `HasTags` trait offers `attachTag`, `detachTag`, `syncTags`, and the scopes `withAllTags`, `withAnyTags`, `withoutTags`, `withAllTagsOfAnyType`, `withAnyTagsOfAnyType`.

A tag is one per locale and slug: `cms_tags_translations` carries a unique key on `(locale, slug)` over the translations that are not soft-deleted (`MigrateUtils::liveUniqueIndex()`). `Tag::findByIdentity($name, $locale)` is the lookup every writer goes through: the tag, trashed included, whose live translation in that locale (the default one when omitted) has the slug of the name (`TagTranslation::slugFromName()`). `findOrCreateFromString()` restores a trashed match instead of creating a second tag; `findFromString()` returns only live tags. Saves are validated: the tag form and the model rules of the CRUD API refuse a slug another tag has in the same locale, and the rules check the slug a name generates when no slug is given.

A tag has an optional free-text `type` that groups it (genre, instrument, ...). The type is one value for every locale, never translated, and is not part of the identity. A lookup that asks for a type gives it to a tag that has none; `findOrCreateFromString()` throws `InvalidArgumentException` when the tag has another type, and `findFromString()` returns null for a tag of another type; no type means any type. The type is stored as a slug (`Tag::normalizeType()`, applied by a mutator on write), and every lookup by type (`findFromString`, `findOrCreateFromString`, `getWithType`, the `withType` scope, and the `HasTags` methods that take a `$type`) slugs the argument the same way, so "Musical Instrument", "musical_instrument" and "musical-instrument" name one type. A type with nothing left to slug is no type (`null`). In the backoffice the type field suggests the types already in use while it is typed, and the tag list is sorted by the slug each row shows (the content locale's, or the default locale's where it is missing), then by id.

Categories are CMS-specific taxonomies: `Category` extends `Core\Models\Taxonomy` with table `taxonomies`, sets `EntityType::CATEGORIES`, uses `HasMultimedia`, and connects to `Content` via the `categorizables` pivot keyed on `(content_id, taxonomy_id)`.

```mermaid
flowchart LR
  subgraph tagging [Tagging morph]
    Tag[tags]
    TagTr[tags_translations]
    Tg[taggables]
    Models["Content / Contributor / Category / Location"]
    Tag --> TagTr
    Models -.->|morph taggable| Tg --> Tag
  end
  subgraph taxonomy [Taxonomy]
    Tax[taxonomies]
    Cat["Category extends Taxonomy<br/>type=CATEGORIES"]
    Pivot[categorizables]
    Tax --> Cat
    Cat --> Pivot --> ContentRel[contents]
  end
```

### Provenance, references and AI-assistance disclosure

Three separate answers to "where did this come from", none of which is a column on `cms_contents`.

**Provenance** is `Content::origin()`, a `MorphOne` onto `Core\Models\RecordOrigin`
(`core_record_origins`), carrying `source_key`, `source_label`, `external_id`, `fingerprint`, `url`
and `source_updated_at`. It is a platform record rather than a CMS one, so any model can carry an
origin; `Content` is the only one that does today. The same table is what the import framework
writes through `RecordOriginRegistry` to dedupe by external identity, so an imported content and a
manually attributed one describe their source in one place and one shape. The `url` must be a
well-formed link (`nullable|url|max:2048`): a manual attribution with a malformed one is rejected,
while an import drops a malformed link from its source and keeps the record.

**References** are the bibliography: `ContentReference` on `cms_contents_references`, a scoped
`HasMany` from `Content::references()`, with `label` required, `url` optional and validated, and
`order_column` sorted per content through `buildSortQuery()`. Soft-deleted like the rest of CMS.
References are part of their content (`ContentReference` implements Core's `IsPartOfParent`) and are indexed in the
content's search document as the `references` text field (each label and the URL host with `www.` stripped); a reference
change re-sends its content through `ContentReferenceObserver`, as a system read (Core's
`RetrievedSelectGuard::forSystemRead()`), so a caller holding only the reference's own permissions can change it. Existing indexes need a rebuild to gain the field
(`php artisan scout:sync-mapping` then `php artisan scout:reindex` for `Modules\CMS\Models\Content`).

**AI-assistance disclosure** lives per translation, not per content, because a piece can be written
by a person and machine-translated. `ContentTranslation.ai_assistance` is an indexed, non-nullable
column defaulting to `none`, cast to `CMS\Enums\AiAssistance`: `none`, `generated`, `translated`,
`edited`, `summarized`. It exists to satisfy EU AI Act Article 50 disclosure, so treat it as a
record of fact rather than a hint: whoever writes the translation is responsible for setting it.

### Lifecycle: validity, approvals, locking

`Content` composes the cross-cutting Core lifecycle traits. `HasValidity` provides the `valid_from` / `valid_to` publication window and a `valid()` scope, but validity is **not** a global scope: it is a row-level authorization concern. A role-scoped ACL on `cms_contents.select`, seeded for the `guest` role of the `api` guard (`CMSDatabaseSeeder::defaultGuestApiGrants`), filters the anonymous/public reader to the current window using the `@now` ACL placeholder; staff roles carry no such ACL and read every content, so a direct `Content::query()` (Filament, imports) returns drafts too. `HasApprovals` requires explicit approval before persisting changes; `Content` overrides `requiresApprovalWhen()` so only validity-window edits demand approval. `HasLocks` and `HasOptimisticLocking` block concurrent edits and stale writes: opening a content for editing takes an owned, expiring **lease** and the lock guard refuses every other writer's save while it lasts, including `cms:import`, whose row is reported as a skip rather than overwriting work in progress. An ownerless lock is a **freeze** and closes the content to everybody. See Core's `RECORD_LOCKING_USER.md` and `RECORD_LOCKING_DEVELOPER.md`. `SortableTrait` sorts by `order_column` (renamed `scopeOrdered` to `scopePriorityOrdered` to avoid Searchable trait clashes). `Searchable` also indexes `valid_from`, `valid_to`, and `is_deleted` so the search layer reflects publication state.

```mermaid
stateDiagram-v2
  [*] --> Draft
  Draft --> PendingApproval: edit needing approval (validity window changed)
  Draft --> Published: edit not needing approval
  PendingApproval --> Published: approval granted
  PendingApproval --> Draft: approval rejected
  Published --> Expired: now > valid_to
  Published --> Archived: soft delete
  Expired --> Published: extend valid_to (with approval)
  Archived --> [*]: hard delete after retention
  Published --> Locked: pessimistic / optimistic lock acquired
  Locked --> Published: released by its holder, lifted with `unlock`, or lapsed at `locked_until`
```

### CMS graph provider

CMS is the first module provider for the Core Graph framework. It registers `CmsGraphProvider`, but Core owns `/crud/graph/expand`, `/crud/graph/search`, `/crud/graph/stats`, traversal, ACL filtering, provider rule enforcement, and response serialization. CMS contributes default content relations and presentation metadata only.

For `contents`, provider defaults expand `tags`, `categories`, `contributors`, and `locations` when the caller omits `relations[]`. Explicit `relations[]` still win. CMS summary fields include content identifiers (`title`, `slug`, `path`, `status`, `type`, timestamps), and edge labels map relation names to content semantics such as `tagged_as`, `categorized_as`, `contributed_by`, and `located_at`. Relations that expose implementation internals (`translations`, `history`, `modifications`, `locks`, `media`) are excluded from graph traversal.

### CMS application content retrieval provider

CMS explicitly registers `CmsApplicationContentRetrievalProvider` as source `cms.contents` through the Core registry. This source is used only by authenticated, non-guest `InAppAssistance` after server-side routing and authorization; it is not copied into either documentation RAG corpus and it does not authorize a session-based guest assistant.

The provider sends the natural-language query and Core-supplied ACL filters through `AdvancedSearchService`. Ranked candidates are then rehydrated from `Content::query()->valid()` with the authorization service and the same `FiltersGroup` applied again. Soft-deleted, invalid, stale, cross-connection, or no-longer-authorized candidates disappear without confirming their existence. If orchestrated search is unavailable, the provider can use its bounded title-only lexical fallback; it never performs an unbounded scan.

`CmsContentEvidenceProjector` emits only an allowlisted title, normalized plain-text component fields, locale, revision, and canonical `/app/cms/contents/{id}` reference. Unknown components, media, hidden relations, raw search payloads, persistence metadata, and authorization internals remain excluded by default.

The reproducible record-level baseline uses 30 synthetic cases in `tests/Fixtures/application-content/cms-contents.json` over the corpus seeded by `tests/Stubs/ApplicationContent/EvaluationContentCorpus.php`; the report is `docs/evaluations/application-content/2026-07-record-baseline.json`. CMS tests only its provider over the dataset (`CmsApplicationContentDatasetTest`); the scored baseline gate needs AI too, so it lives in the application's `tests/Integration/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php` and skips itself when either module is missing. Aggregate hit@5 is `0.7619`, citation precision and authorized-empty accuracy are `1.0`, while semantic/paraphrase and the passage-candidate slice are `0.0`. This is evidence for a separate passage-index evaluation, not permission to add another index automatically.

### Graph runtime benchmark

`Modules/CMS/tests/Benchmark/CmsGraphRuntimeBenchmarkTest.php` is an opt-in performance harness for Core Graph over CMS content. It is outside the normal module test suites and skips unless `CMS_GRAPH_BENCHMARK_ENABLED=true`. The dataset size is controlled by `CMS_GRAPH_BENCHMARK_CONTENTS`, `CMS_GRAPH_BENCHMARK_TAGS`, `CMS_GRAPH_BENCHMARK_CATEGORIES`, `CMS_GRAPH_BENCHMARK_CONTRIBUTORS`, `CMS_GRAPH_BENCHMARK_LOCATIONS`, and `CMS_GRAPH_BENCHMARK_ITERATIONS`. Scenarios cover provider-default expand, explicit relation expand, graph search with and without expansion, and stats. Output is JSON metrics for duration, queries, memory, nodes, edges, truncation, and ACL filtering. Use it as evidence for or against Core Graph materialization; do not run it as a standard suite gate.

### Search indexing

CMS models implementing `Searchable` build their Typesense schema declaratively through `FieldDefinition` + `IndexType`. `Content::getSearchMapping()` emits flattened arrays for contributors/categories/tags/locations, separate per-locale title/slug fields, and a vector `embedding` field. `Content::toSearchableArray()` reads translations through `HasTranslations`, hydrates pivot data, and projects `valid_from` / `valid_to` so filters can mirror the content availability window. Component values are keyed by locale; an Editor.js component (a value with `blocks`) is indexed as its plain text (`ReadingStatistics::plainText()`: text, captions, list items, table cells, raw html, tags stripped), never as the raw block JSON, whose per-block `data` shapes conflict in the engine mapping. An index created before this change maps those fields as objects and must be recreated (`scout:delete-index`, `scout:index`, `scout:import`). `Location::getSearchMapping()` adds geo + address keyword fields and reuses Core `Place::searchDocumentGeographyFields()` when `place_id` is set.

Database migrations use Core `MigrateUtils` instead of embedding driver-specific search DDL. Fuzzy intent is declared for `cms_locations.name`, `cms_contents_translations.title`, `cms_tags_translations.name`, and `cms_contributors.name`; PostgreSQL receives trigram GIN indexes and Oracle receives `CONTEXT` indexes, while unsupported engines degrade safely. Slugs keep their existing B-tree indexes and are marked as prefix fields in search schemas. Dynamic component fields are full-text fields for external search engines, but their JSON storage deliberately receives no database full-text index until CMS owns a normalized physical `search_text` projection.

```mermaid
flowchart LR
  Models[CMS models with Searchable]
  Schema[FieldDefinition + IndexType]
  Map["toSearchableArray()<br/>flattens pivots + translations"]
  Trans["HasTranslations / LocaleContext"]
  Engine["Search engine (Typesense)"]
  Place[Core Place geo fields]

  Models --> Schema
  Models --> Map
  Trans --> Map
  Place -.-> Map
  Schema --> Engine
  Map --> Engine
```

### Public API access

CMS grants the anonymous reader of `/api/v1` (the `guest` role of the `api` guard) `select` on contents, references,
media, categories, tags, locations, contributors, comments and ratings, through `CMSDatabaseSeeder::defaultGuestApiGrants()`.
Categories live in the shared `core_taxonomies` table, so their ACL keeps the CMS rows only (a relation filter on the
entity type), active and inside the published window: it is expressed with Core's scope filters (`valid` and `active`,
see `Modules/Core/docs/ACL_SYSTEM.md`), so the window follows the model.
Contents are narrowed by the published window ACL on columns (`valid_from <= @now`, `valid_to >= @now` or null); references
and media are narrowed by relation filters on their owner content, also on columns (media with the morph type `Content`),
so a media of an unpublished content or another module's attachment is never public. These three stay on columns because
search engines never receive a scope node: a scope in them would be enforced only when the hits are reloaded and would skew
the totals and page sizes of anonymous content search. A scope ACL seeded here is not rewritten into existing
installations (the seeder never overwrites an ACL), and a stored column ACL for categories keeps working. Contributors hide `user_id`, `user` and
`shared_components`, comments and ratings hide `user_id`, from the anonymous user
(`IHidesAttributesFromAnonymous`). The `guest` role of `web` holds no permission. A re-seed creates an ACL only when none
exists for the role and permission. The `publisher` role (on `web`) also reads public settings and the CMS entity
types, because related records obey their own permission.

`Content`, `Contributor` and `Category` append `cover` (first media of the `cover` collection), loaded once per result
set through `IPreloadsAppendedRelations` under the media permission and ACL of the caller; on the `api` guard it is the
safe media projection, and `null` when the caller may not read it. `ContentTranslation`, `TagTranslation`,
`CommentTranslation`, `ContributorTranslation`, `ContentReference` and `ContentRating` are parts of their parent
(`IsPartOfParent`). Comments, ratings and the translation models have no version history of their own: comments and
ratings are not part of the document (`INeverVersionedModel`), and a translation's state belongs to its owner: a change of a content, contributor, tag or comment translation, or of a category or location translation written through its owner, is a row of the owner's history (one per locale), and a save of a content with its translations is one revision. A content deleted for good keeps one snapshot set holding the content, one row per locale and one per import origin (reason `manual`, or `expired` when `model:clear-expired` purges it from the bin); a versioned model that extends contents and is deleted with them gets its own snapshot set. Anonymous responses share one cache entry per entity that uses the response cache; contents do not
use it. Full behaviour: `Modules/Core/docs/rag/API_ACCESS_CONTROL.md`.

### Filament module integration

CMS registers panel resources through `CMSPlugin` (`Modules/CMS/app/Filament/CMSPlugin.php`), which uses `Coolsam\Modules\Concerns\ModuleFilamentPlugin` to auto-discover module Filament classes. Resources cover `Contents`, `Categories`, `Tags`, `Contributors`, `Locations`, `Presets`, `Templates`, and `Entities`, plus shared utilities (`HasRecords`, `HasTable`) and the `CMSStatsWidget`.

## How to use

1. Define `Entity` rows for each domain (CONTENTS / CONTRIBUTORS / CATEGORIES) and seed presets for the structures you publish.
2. Create domain rows (`Content`, `Contributor`, `Category`) and let the observer auto-assign `entity_id` / `presettable_id` if you don't pass them explicitly.
3. Attach taxonomy/contributor/location/tag relations through the morph pivots; populate translations per locale through `HasTranslations`.
4. Use `GeocodeLocationAction` only when provider quotas/UA/contact info policies are aligned with `NominatimService` defaults.
5. Expose CMS outputs through API/theme routes following project conventions; the search layer can be queried as soon as Searchable indexes are populated.

## Internal flow (high-level)

- Resource form inputs are normalized and persisted through CMS Eloquent models with strong validation rules in `getRules()`.
- Media uploads route through `HasMultimedia` collections and run conversions (image and video keyframes) according to the configuration.
- Optional geocoding enriches `Location` rows; canonical geography lives on `Core places` when `place_id` is available.
- Search indexing is driven by `Searchable` schemas; pivots, translations, and validity dates flow into the search documents to mirror UX filters.
- CMS exposes indexed relation-field filters for content search. Supported dot paths include `contributors.id`, `contributors.slug`, `contributors.path`, `categories.id`, `categories.slug`, `categories.path`, `tags.id`, `tags.slug`, `tags.path`, `locations.id`, `locations.slug`, `locations.city`, `locations.province`, `locations.country`, `locations.postcode`, and `locations.zone`. Core translates them consistently across Elasticsearch, Typesense, and database search.
- CMS contributes Core Graph provider defaults for content relations; Graph remains a Core capability and CMS does not own traversal, route registration, or authorization behavior.
- CMS owns an opt-in graph runtime benchmark for realistic content relation datasets; it is controlled by `CMS_GRAPH_BENCHMARK_*` environment variables and is not part of the normal test suites.

## Dependencies and boundaries

- Depends on `Core` for identity, permissions, lifecycle traits, dynamic entities/presets/translations, and shared CRUD infrastructure.
- Should not duplicate cross-cutting capabilities already provided by `Core` (approvals, locking, versioning, ACL mechanics).
- `Tag` and `Media` are CMS-owned but conceptually cross-cutting: see the Shop module design spec (`docs/superpowers/specs/2026-09-17-shop-module-design.md`) for the long-term ownership decision (`Product` as anchor with FKs to `Content` and ERP `Item`, no duplication of fields).

## Common pitfalls

- Migrating legacy content without first aligning `Entity`/`Presettable` causes inconsistent structures and search-mapping drift.
- Treating CMS as a plain WYSIWYG underuses the dynamic schema, translations, and approvals already in place.
- Skipping `Preset::migrateContentsToLastVersion()` after schema changes leaves rows tied to outdated `presettable.version`.
- Querying tags by `name` directly skips translations: use `Tag::findFromString()` / scopes that JOIN `tags_translations`.
- Calling Nominatim without the User-Agent or above its rate limits is a fast way to get blocked.

## Releases

Releases are cut by the maintainers with private tooling; do not bump versions or edit `CHANGELOG.md` by hand.

## FAQ prompts for RAG

- Which content features are still owned by CMS versus moved to Core?
- How do `Entity`, `Preset`, and `Presettable` interact when I add a new content type?
- How is locale resolved for `Content`, `Tag`, and `Contributor` translations?
- How should taxonomy be modeled for multi-locale content?
- When should CMS media processing run through queues?
- What is the safest migration path for legacy CMS data imports?
- How does CMS contribute to the Searchable Typesense schema for content?
- How is geocoding wired and how do I plug in an alternative provider?
