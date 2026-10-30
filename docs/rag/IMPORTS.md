# CMS import command retrieval notes

## Command ownership

- Runnable command: `cms:import`.
- Common parent: `Modules\Core\Console\AbstractImportCommand`.
- CMS marker: `Modules\CMS\Import\Contracts\BulkImporterInterface`.
- CMS resolver: `CmsBulkImporterResolver`.
- External discovery adapter: `SiblingImportersDiscovery`.
- Core intentionally exposes no `core:import` command.

The command name selects CMS as the destination. `--importer` selects the source adapter. Core never infers the destination entity or mapping.

## Options

`--importer`, `--bootstrap`, repeatable `--arg`, `--dry-run`, `--limit`, `--no-search`, and `--index-batch` are inherited from Core. Imported contents are indexed in deferred bulk flushes of `--index-batch` records (default 500), embeddings included, queued on the `indexing` queue when `scout.queue` is on, with one `Search: ...` output line per flushed chunk; `--no-search` imports without indexing or embedding. Ctrl+C offers to index the contents imported so far before quitting. Concrete CMS commands declare `$name`, not `$signature`.

## Which entity an import writes into

An import DTO names an entity **type** — `contents`, `categories`, `contributors` — never an entity. Singular aliases (post, event, content, multimedia, category, section, folder, contributor) normalize to those three types. Which entity of that type a project uses is the project's own naming, so `EntityPresetResolver` looks it up by type and picks, in order:

1. the only entity of that type, when the project has one;
2. the entity the importer named through the DTO's `preferredEntityName`;
3. the entity flagged as the default for that type.

The import creates no entity. A type with no entity stops the run, and so does an ambiguous type where nothing is named or default: the message lists the candidates. `ImportPresetProvisioner` resolves the same way and provisions only presets, their fields, and the presettable version, which do belong to the import.

An importer supplies `preferredEntityName` when the destination project told it which entity to use. The Naxos importer reads it per type from `--arg entityContents=`, `--arg entityCategories=` and `--arg entityContributors=`, and leaves it null otherwise.

## Provenance recorded per record

Every upserted record gets a row in `core_record_origins`, written through `ExternalReferenceLocator::register()`. Identity is the pair source key plus external id, which is what makes a repeated import update instead of duplicate. Alongside it the row carries four descriptive fields, all optional and all supplied by the importer through the DTO:

- `source_label` and `url`, from `originLabel` and `originUrl`. Every import DTO declares them: contents, categories, tags, contributors and locations.
- `fingerprint`, a SHA-256 over the mapped payload, computed by `Modules\Core\Import\Support\ImportFingerprint` from the DTO itself. Key order never affects it, so only a changed value changes the hash.
- `source_updated_at`, taken from the DTO's `updatedAt`, which is the source system's own modification timestamp. A string the source sends that cannot be parsed leaves the column empty rather than failing the row.

An importer that has no url for a taxonomy leaves `originUrl` null; the origin row is still written and the record stays identifiable.

## One record across several sources

An installation imports from several sources at once (Naxos tenants, feeds, Spaceflight). The origin identifies what one source brought; whether two sources bringing the same thing meet on one record depends on the entity:

| Entity | Across sources | How an import finds an existing record |
|---|---|---|
| Tag | shared | origin of this source, then the slug in the import locale (`TagMatcher`), trashed tags included and revived |
| Location | shared | origin, then slug, then name (`LocationMatcher`) |
| Contributor | shared | slug in the import locale, then name for the names in `cms.import.contributor_dedup_names` and the default contributor, preferred over an origin whose name changed (`ContributorMatcher`) |
| Category | owned by the source | origin only: each source's categories are its own section tree, so a category with the same name from two sources stays two categories |
| Content | owned by the source | origin only |

A tag found through another source's slug gains this source's origin, so it carries one origin per source. A tag another source also brought (`ExternalReferenceLocator::hasOriginFromAnotherSource()`) is not renamed by the import, which writes its name and slug only when it creates the tag or the tag has no translation in the import locale, and is not deleted when one source marks it deleted. A source deletes only a tag it created or already owned, never one it met by slug, and a tag an editor trashed stays trashed when the source sends it deleted. A source that renames its tag into a slug another tag has keeps the old name and slug, logged as `Tag slug conflict during import`. Its type is set only when it has none; a different type is kept and logged as `Tag type conflict during import`. The slug a source sends is slugged before the lookup and stored that way, so `Unione_Europea` meets `unione-europea`. Two imports creating the same tag at once leave one: the unique key refuses the second, whose savepoint is rolled back, and the import uses the tag the other one created, read with a shared lock so that MySQL's REPEATABLE READ snapshot does not hide it. The CSV tag import uses the same identity.

## Position of categories and tags

`ImportCategoryDto::$orderColumn` and `ImportTagDto::$orderColumn` are nullable. A number is the record's position in the source: the upserter writes it, also when it creates the record (`keepPositionWhenCreating()`). Null means the source has no order of its own: an existing record keeps the position an editor gave it, and a new one goes last. The CSV category importer carries no position and behaves as null. Naxos sends a position for sections, event categories and folders when the payload has `order`; Naxos tags, feed and Spaceflight send null.

## Compatibility and boundaries

The CMS marker extends Core's neutral `import(?OutputInterface $output = null): int` contract, preserving existing Naxos importer namespaces. When the console output is passed (from `cms:import`), CMS forwards it to `ImportPipeline` / `ImportProgressLogger` for per-content progress lines; omitting it keeps pipeline-only runs quiet. CMS still owns content DTOs, mapping contracts, `ImportPipeline`, upserters, preset provisioning, reference resolution, and post-processing. External packages own source clients, credentials, readers, normalization, and source-specific mappings.

Dry-run uses the connection returned by the optional connection-aware importer contract, falling back to the default connection. It rolls back only writes on that connection. The importer must suppress files, queues, HTTP calls, other connections, and all other external side effects.

Batch import and ongoing synchronization are separate capabilities. Synchronization requires remote identity, cursors, idempotency, conflict rules, retries, scheduling, locking, and observability.
