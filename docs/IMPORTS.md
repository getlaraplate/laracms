# CMS bulk imports

CMS owns the runnable `cms:import` command and the destination content pipeline. Core owns the shared command parser, plugin discovery primitives, importer resolution, and transactional runner. There is no runnable `core:import` command.

## Run an external importer

Pass an importer class and, when it is not already autoloaded, its Composer bootstrap:

```bash
php artisan cms:import \
  --bootstrap='/absolute/path/to/laraplate-importers/vendor/autoload.php' \
  --importer='Naxos\Importers\NaxosApiImporter' \
  --arg='endpoint=https://example.invalid/api' \
  --arg='token=secret' \
  --limit=100 \
  --dry-run
```

When the sibling `laraplate-importers` checkout is available, running `php artisan cms:import` without `--bootstrap` or `--importer` offers interactive plugin discovery.

## Common options

| Option | Meaning |
|---|---|
| `--importer=` | Fully qualified importer class implementing the CMS marker contract |
| `--bootstrap=` | Absolute path to an external Composer autoloader |
| `--arg=*` | Repeatable importer constructor argument in `key=value` form |
| `--dry-run` | Roll back writes made on the importer-declared connection, or the default connection |
| `--limit=` | Non-negative limit passed to the importer |
| `--no-search` | Import without search indexing: no document written, no embedding generated |
| `--index-batch=` | Distinct records indexed per bulk search flush (default `scout.chunk.searchable`, 500) |

Imported records are indexed in deferred bulk flushes, not one by one: every `--index-batch` distinct records and at the end, with the embeddings of all locales generated in one batched pass. With `scout.queue` on, each flush is queued on the `indexing` queue and a worker does the embeddings and engine writes; the command prints one `Search: ...` line per flushed chunk. `CMS_IMPORT_REINDEX` is therefore not needed to index the imported records; when enabled it reindexes every `Content`. Pressing Ctrl+C asks whether to index the records imported so far before quitting (the default), quit at once, or resume; the content being imported at that moment is rolled back. See `Modules/Core/docs/IMPORT_FRAMEWORK.md`, "Search indexing during an import".

Within one run the pipeline writes each category, contributor and tag once: a later article that carries the same entity with an identical payload reuses the local id already in the run's `ImportIdMap` instead of loading, saving and registering it again. A changed payload is written again, and a graph that rolls back clears the map, so the next article writes its entities afresh. Nothing is carried between runs.

An import owns the position of a category or tag only when the source orders them. A DTO that carries a number in `orderColumn` places the record there, new or not; a DTO that carries null leaves an existing record where an editor put it and puts a new one last. An importer whose source has no order of its own passes null, never 0: a 0 would move every record of the source to the top at each run. The CSV category import carries no position.

The command injects `dryRun` and `limit` as named constructor parameters. Source-specific arguments are intentionally not part of the common command contract.

## Dry-run boundary

An importer may implement `ConnectionAwareBulkImporterInterface` to select the transaction connection. Otherwise the current default connection is used. Dry-run rolls back database writes made on that one connection only.

It does not reverse writes on additional connections, files, object storage, queues, HTTP calls, or provider-side effects. Importers must inspect `dryRun` and suppress every non-transactional side effect. Scout is disabled automatically during dry-run.

## Develop a CMS importer

External CMS importers continue to implement `Modules\CMS\Import\Contracts\BulkImporterInterface`. This compatibility marker extends Core's neutral contract and requires:

```php
public function import(?OutputInterface $output = null): int;
```

Return the number of imported root records. When `$output` is provided (as `cms:import` does), forward it to `ImportPipeline::import()` so per-content progress lines are written through Laravel's console output. Pipeline-only callers omit it and stay silent. Use CMS DTOs, `ImportPipeline`, and domain services for destination writes. Do not move source credentials, source clients, or source-specific mappings into CMS or Core.

The command implementation is deliberately thin: `Modules\CMS\Console\ImportCommand` declares `cms:import`, adds the colored CMS suffix, and injects the CMS resolver and plugin discovery adapter into Core's `AbstractImportCommand`. CMS retains `BulkImportRunner` as a compatibility adapter because existing Naxos importers call its static `limitReached()` helper.

`Naxos\Importers\NaxosApiImporter` remains compatible with the retained CMS namespace. The Naxos SQL dump importer was removed on 2026-09-15; Naxos is imported from its REST API only.

## Cache labels

An import writes through the query builder and raw pivot rows, which fire no model event. At the end of a run, successful or not, the pipeline flushes the `table:` cache labels of the tables it can write (contents, categories, contributors, tags, locations and their pivots), once per table, so cached CRUD responses that depend on them are recomputed. See `Modules/Core/docs/rag/CACHE_LABELS_DEVELOPER.md`.

## Import is not synchronization

`cms:import` is a bounded, operator-triggered batch operation. Continuous synchronization additionally needs remote identity, cursors, direction, conflict policy, retries, locking, scheduling, and observability. Those concerns must be designed as a separate integration layer, even when it reuses CMS import mapping and destination services.
