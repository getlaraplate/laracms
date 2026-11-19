# Content extension seam

Lets another module attach a richer domain model to a CMS `Content` without CMS knowing that model,
and without the extended rows leaking into generic CMS surfaces. The extender is a **separate table**
with a `content_id` foreign key — not a subtype of `Content`. First intended consumer: a shop
`Product` that ties a `Content` (editorial body) to an ERP `Item`.

Design record: `docs/superpowers/specs/2026-09-17-cms-content-extension-seam-design.md` (decisions
C1–C18) and `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md` (decisions XS1–XS17: search and
list of extenders, the host side of the seam in Core).

The host side of the seam is generic and lives in Core: `Content` implements `Modules\Core\Contracts\IExtendableModel`
with the `Modules\Core\Models\Concerns\HasExtensions` trait and declares only its type column. The extender side
(`ExtendsContentTrait`, the save bridge, the lifecycle cascade) stays in CMS and implements
`Modules\Core\Contracts\IExtendsModel`.

## How it works

- **Self-describing column.** `contents.extended_type` is a nullable morph alias (e.g.
  `shop.product`); `null` means a normal content. It is set once, by the extender's create path, and
  is excluded from versioning (it is identity, not editorial state).
- **Dedicated registry.** Core's `Search\Extensions\ExtensionRegistry` maps alias → extender class,
  per host: `register(Content::class, 'shop.product', Product::class)`. It is code, keyed by the stable
  alias, populated at boot by each extender's service provider — never a table and never Laravel's
  global morph map. With no extender registered it is empty and the whole seam is a no-op. CMS has no
  registry or resolver of its own: the former `Services\ContentExtenderRegistry` and
  `Services\ContentExtensionResolver` were removed once every extender registered in Core.
- **Default hide.** Core's `Search\Extensions\HidesExtendedRows` global scope adds `WHERE extended_type IS
  NULL` to every default `Content` read. Opt back in with `Content::withExtended()` or
  `queryWithExtensions()`, which remove **only** that scope (soft-delete, validity and any other global
  scope stay applied).
- **Batched upcast.** `$content->upcastExtensions(Collection $contents)` (Core
  `Search\Extensions\ExtensionResolver`) swaps each extended content for its extender, re-attaching the
  content through the extender's host relation. One query per distinct alias on the page, never one per
  row. An unregistered alias or a missing owner row fails loud.
- **Back-relation.** The extender's `content()` is a `BelongsTo` with `HidesExtendedRows` removed,
  so `$extender->content` always resolves.
- **Lifecycle.** The extender owns the content: its soft/force delete and restore cascade to the
  content (restore via Core's `reviveInMemory()` + `save()`). A directly deleted content is applied to
  the extender per policy — **mandatory** content cascades to delete the extender, **optional** content
  orphans it (`content_id` nulled). A shared `Services\ContentExtensionCascade` re-entrancy guard stops
  the two directions colliding.
- **Search.** One physical `contents` index. `makeAllSearchableUsing()` includes extended contents in
  the bulk import (per-save indexing already sees them). `toSearchableArray()` adds a filterable
  `extended_type` and a nested typed `extension` object from the extender's `searchableExtension()`.
  `getSearchMapping()` composes the `extension` mapping from every registered extender's
  `searchableExtensionMapping()` (`HasExtensions::extensionSearchMapping()`), and refuses a field two
  extenders declare differently (see "Becoming a consumer"). No ERP/external data is indexed. A module reindex is document-scoped
  (`Content::withExtended()->where('extended_type', $alias)->searchable()`), never an index-lifecycle
  command against `contents`: only Core's search engines and commands create or drop an index, which
  `Modules/Core/tests/Unit/Architecture/SearchIndexLifecycleOwnershipTest.php` enforces. An extender
  never has to reindex its content by hand: `ExtendsContentTrait` re-sends the content to the index after
  every extender save, since the content is saved before its extender and an extender edit alone never
  touches the content.

## Becoming a consumer

1. Create the extender model with a `content_id` **unique** foreign key to `contents`.
2. Implement `Contracts\ExtendsContent`, or `use Models\Concerns\ExtendsContentTrait` (which provides
   the back-relation, `$with = ['content']`, `setTempContent()`, the create-path `save()` that stamps
   `extended_type`, the lifecycle cascade, and a `contentIsMandatory()` default of `true`).
3. Register the alias at boot in Core's registry:
   `app(ExtensionRegistry::class)->register(Content::class, 'shop.product', Product::class)`.
4. Create the pair through the extender: `$product->setTempContent($content); $product->save();`.
5. Decide `contentIsMandatory()` (default `true` = symmetric delete; `false` = orphan on content
   deletion — needs a nullable `content_id`).
6. Provide `searchableExtension()` (data) and `searchableExtensionMapping()` (Core `FieldType`
   schema properties) for the `extension` search section. Declare every key you write, and nothing you do
   not write: Core's `ExtensionDocumentCheck` lists the differences, and
   `tests/Feature/ContentExtension/ContentExtensionMappingTest.php` runs it over every registered extender.
   Mark `filterable` the fields a search or an ACL must filter on: only those reach the index from the
   extender's own routes (see "Searching and listing extenders").
7. Return your own columns from `extensionOwnColumns()` (the trait reads them from the schema) and, when
   the content rows of your extender use a preset entity of their own, its type from `extensionEntityType()`
   (the trait returns null).

### Two naming rules

Both were chosen on 2026-11-17 (spec `2026-11-17-extended-entity-search-design.md`), not forced by the code.
Each check names its decision in its message.

- **Your own columns never share a name with an attribute of `Content`, except the native keys (XS17).** No
  column of the extender may be named like a column, a translated field or a dynamic component of `Content`;
  `id`, the timestamps and the soft-delete columns are the only shared names. This holds even when the two have
  the same type. Why: the merge routes a key the extender owns to the extender and every other key to the
  content, by name only, so a shared name makes the content's field unreachable through the extender, silently;
  and the search translation of filters (XS10) relies on the same split. Enforced by a test in the extender's
  module (Shop: `ProductColumnNamesTest`), and by Core when a preset field of the extender's entity is created or
  renamed with such a name (see `Modules/Core/docs/rag/MODULE.md`).
- **`extension` fields shared with another extender must have the same definition (XS16).** All extenders write
  into one `extension` object of the `contents` mapping, where a field has one type. Two extenders may declare
  the same field only with the same type and the same `filterable` and `facet` flags; otherwise composing the
  mapping fails with `ExtensionFieldConflict`, naming both aliases and the field. The fix is to rename the field
  in one extender. Why: with the first definition silently kept, the second extender's values would be indexed
  under the wrong type or refused by the engine; failing loudly keeps the paths short (`extension.kind`, no
  prefix per alias). `extension.type` is reserved: it holds the alias.

## Generic search excludes extended contents

Extended contents are **indexed** but kept out of the **generic** content search and list. Core derives
`extended_type IS NULL` from the host contract (`IExtendableModel::extensionTypeColumn()`), on every search
path and engine: `Content` declares no default search filter for it. The engine never returns an extended
content for a generic search, so a page is not cut short when the hits are reloaded. On Elasticsearch the
condition is `must_not exists`; on Typesense a null `extended_type` is indexed as a placeholder value (Core
`TypesenseEngine::NULL_PLACEHOLDER`), and the validity window of the public ACLs works on the dates Typesense indexes
as timestamps (see `Modules/Core/docs/rag/MODULE.md`). A Typesense collection indexed before that needs a rebuild.

## Searching and listing extenders

- **An extender's own routes.** `/select/shop/products` reads the extender table with its permission and ACL.
  `/search/shop/products` runs on the `contents` index, kept to the extender's alias, with the extender's
  permission and ACL; the hits reload as extenders. Filters and ACL are translated: an attribute the extender
  mounts from its content filters the content field of the same name, an own column filters
  `extension.<column>` when the extender indexes it as filterable; any other request filter answers 400, any
  other ACL condition holds when the hits are reloaded.
- **The mixed list.** `with_extended=true` on `/select/cms/contents` and `/search/cms/contents` adds the extended
  contents of the aliases whose table the user may `select`, each passing the `contents` ACL and its own entity's
  ACL, and returns each as its extender: `extended_type` tells the client which shape a row has (`null` for a
  plain content). The parameter is described in `Modules/Core/docs/CRUD_SYSTEM.md`.

## The extender in the CRUD API

An extender that merges its content in PHP (`Shop`'s `Product` reads and writes `$product->title`) presents
the same merge to the CRUD API, so a client never has to know the content is a separate record.

- **Serialization.** `toArray()` overlays the merged content's own array (translation and expanded dynamic
  fields included) onto the extender's, for the keys the extender does not own. `id`, `created_at`, the
  relations and every column of the extender stay its own. The nested `content` object stays too, for readers
  that already use it. The content serializes through its own rules, so a key it hides is not overlaid. Like
  the translation and place overlays, the overlay does not follow the `columns` a client requests. An
  unloaded content adds nothing and never triggers a lazy load.
- **Filters.** The extender implements `Modules\Core\Contracts\IMountsRelatedAttributes`
  (`mountedRelation()` is `content`, `isMountedAttribute()` is "not a native key"). A plain filter property
  the extender does not own (`title`, `slug`, a dynamic field) becomes the same filter on `content`, which
  resolves it in the locale of the request (see `Modules/Core/docs/CRUD_SYSTEM.md`, "Translated and Dynamic
  Fields"). `product.title` on a variant works through the ordinary relation path.
- **Where the code lives.** For now in `Product`, next to the `getAttribute()` and `setAttribute()` it
  completes; when a second extender exists the merge moves into `ExtendsContentTrait`.
- **Not resource classes.** The CRUD wraps every model in a generic `JsonResource` that calls `toArray()`, and
  the output rules (hidden attributes, anonymous hiding, requested columns, relation authorization) hang on
  model attributes, so the overlay lives in the model like `HasTranslations::toArray` and
  `HasPlace::toArray`. Not covered: sorting and facets on mounted attributes, and references between modules.

