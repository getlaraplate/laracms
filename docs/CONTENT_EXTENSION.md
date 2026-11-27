# Content extension seam

Lets another module attach a richer domain model to a CMS `Content` without CMS knowing that model,
and without the extended rows leaking into generic CMS surfaces. The extender is a **separate table**
with a `content_id` foreign key — not a subtype of `Content`. First intended consumer: a shop
`Product` that ties a `Content` (editorial body) to an ERP `Item`.

Design record: `docs/superpowers/specs/2026-09-17-cms-content-extension-seam-design.md` (decisions
C1–C18).

## How it works

- **Self-describing column.** `contents.extended_type` is a nullable morph alias (e.g.
  `shop.product`); `null` means a normal content. It is set once, by the extender's create path, and
  is excluded from versioning (it is identity, not editorial state).
- **Dedicated registry.** `Services\ContentExtenderRegistry` maps alias → extender class. It is code,
  keyed by the stable alias, populated at boot by each extender's service provider — never a table and
  never Laravel's global morph map. With no extender registered it is empty and the whole seam is a
  no-op.
- **Default hide.** The `Scopes\HidesExtendedContent` global scope adds `WHERE extended_type IS NULL`
  to every default `Content` read. Opt back in with `Content::withExtended()`, which removes **only**
  that scope (soft-delete, validity and any other global scope stay applied).
- **Batched upcast.** `Services\ContentExtensionResolver::resolve(Collection $contents)` swaps each
  extended content for its extender, re-attaching the content via `setRelation('content', …)`. One
  query per distinct alias on the page (`whereIn('content_id', …)->without('content')`), never one per
  row. An unregistered alias or a missing owner row fails loud.
- **Back-relation.** The extender's `content()` is a `BelongsTo` with `HidesExtendedContent` removed,
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
  `searchableExtensionMapping()`. No ERP/external data is indexed. A module reindex is document-scoped
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
3. Register the alias at boot: `app(ContentExtenderRegistry::class)->register('shop.product', Product::class)`.
4. Create the pair through the extender: `$product->setTempContent($content); $product->save();`.
5. Decide `contentIsMandatory()` (default `true` = symmetric delete; `false` = orphan on content
   deletion — needs a nullable `content_id`).
6. Provide `searchableExtension()` (data) and `searchableExtensionMapping()` (Core `FieldType`
   schema properties) for the `extension` search section.

## Generic search excludes extended contents

Extended contents are **indexed** (for a future opt-in shop search) but kept out of the **generic**
content search. `Content` implements `Modules\Core\Contracts\ProvidesDefaultSearchFilters`, returning
`['extended_type' => null]`; every generic search path (Basic and each orchestrated strategy) applies
it to the Scout query after the request constraints, and `= null` compiles to `IS NULL`, so the engine
never returns extended contents for a generic search (no short pages at rehydration). On the database
engine the hide global scope already excludes them; on Elasticsearch the filter is what keeps the index
hole-free, where `extended_type` is a real indexed field. Typesense cannot filter on `null`, so it skips
the filter and extended contents are not excluded there (see Core `SEARCH_RETRIEVAL_PIPELINE.md`,
"Eligibility on every path"). A request filter on `extended_type` cannot bring extended contents back:
an opt-in surface (e.g. a shop search) reaches them through its own model, not through the generic
Content search.

End-to-end ES/Typesense verification is left to the first consumer's integration (the database engine's
own `search('*')` over `Content` has a pre-existing, unrelated column-set limitation in tests).

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

