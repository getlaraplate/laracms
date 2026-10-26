# Changelog

All notable changes to this project will be documented in this file.

## [1.47.2] - 2026-10-26

### 🚀 Features

- *(cms)* Comments and ratings are never versioned (D8)
- *(cms)* Content, comment, contributor and tag translations record on their owner (D28)

### 🐛 Bug Fixes

- *(location)* GetSearchMapping() returns the mapping keyed by name

### 🚜 Refactor

- *(cms)* Update ContentsController to extend Controller and adjust list method usage

### 📚 Documentation

- *(cms)* Comments, ratings and translations have no history of their own
- *(cms)* Translations are rows of their owner's history

## [1.47.1] - 2026-10-21

### 🚀 Features

- *(cms)* Related records obey their own permission and ACL
- Guest is empty on web and holds the public list on api
- Hide attributes from anonymous callers and project media on the api guard
- Read the cover from a relation preloaded once per result set
- *(cms)* Index content references in the content document
- *(cms)* The content edit page refuses a stale save
- *(cms)* Anonymous api readers see active, valid CMS categories by default
- *(cms)* Guest categories ACL by valid and active scopes
- *(cms)* Translation keys named like the database
- *(cms)* Translated resource names and navigation groups
- *(cms)* Backoffice strings from the cms lang file
- *(cms)* A content's updated_at follows its translations and relations

### 🐛 Bug Fixes

- *(cms)* Comments use the typed adjacency relations; read requests run no undeclared content method
- *(cms)* A soft delete keeps the tags of a content, only a hard delete detaches them
- *(cms)* Re-index a reference's content as a system read
- *(cms)* The content create page writes the translated fields
- *(cms)* The content position is edited only once the content exists
- *(cms)* Hide the tag and category position on create, drop is_deleted toggles
- *(cms)* An import owns a taxonomy position only when its source orders it
- *(cms)* Read the default locale through LocaleContext
- *(cms)* Tag, category and contributor pages load and save translated fields
- *(cms)* Restore singular translations for comments, entities, and presets in multiple languages
- *(cms)* Add missing newline at end of module.json

### 📚 Documentation

- API access control
- Verbatim AGPL-3.0 licence text, copyright in the README
- *(cms)* Anonymous categories ACL on scope filters, contents kept on columns
- *(extension)* The extender in the CRUD API
- Point the links to the getlaraplate organization
- Fix a typo in the work in progress notice
- Use the Laraplate brand logo in the README

### 🧪 Testing

- Follow the renamed API switch
- An authenticated denial answers 403
- Add AclRelationFilter tests for published content and media access
- *(cms)* Isolate content-extender-registry assertions from production extenders
- Build AdvancedSearchService from a strategy resolver
- *(cms)* Related records through group_by, facets and nested paths obey their permission and ACL
- *(cms)* The pending modifications of a content need the content's update or approve permission
- History access by entity, tree cover loading, cover ACL narrowing
- *(cms)* Anonymous readable columns and embeddings on the api
- *(cms)* References hydrated with the content permission, dynamic fields in history
- *(cms)* Parts hydrate with their own select or the content's
- *(cms)* The reference fallback test exercises the fallback
- *(cms)* A scope ACL never widens the per-id media endpoints
- *(cms)* Pin the per-id media outcome of scope and placeholder ACLs (scope filters task 3)
- *(contents)* Filter on translated and dynamic fields by plain name
- *(contents)* Operators, OR groups and relation leaves on stored properties
- *(cms)* Scope filters in content search requests and ACLs (scope filters task 5)
- *(cms)* Facets stay within the ACL under a request root OR (scope filters task 5 review)
- *(cms)* Backoffice content language, removed translations, stable fixtures
- *(cms)* A content deleted for good takes its embeddings and modifications
- *(cms)* A hard delete closes the pending modifications and keeps them

### ⚙️ Miscellaneous Tasks

- *(cms)* Remove the unused nwidart module scaffold
- Rename the Composer package to the getlaraplate vendor
- Align the package description with the repository description
- Align the module description with the repository description
- Call the organization CLA workflow
- Rename the repository and package from laraplate-<module> to lara<module>

## [1.47.0] - 2026-10-07

### 🚀 Features

- *(cms)* Comments validate their content, text and rating and can be posted and moderated through the CRUD API
- *(cms)* Content provides the text a reranker reads
- *(cms)* Domain interface labels in lang/{locale}/cms.php

### 🐛 Bug Fixes

- *(cms)* An extender save reindexes its content
- *(cms)* A captured comment carries its pending request
- *(seeder)* Publisher permissions on real tables, CMS-only ACL on shared tables

### 💼 Other

- Own CMS plugin, translation fallback and runtime setting tests

Moved from Core so Core tests no longer depend on CMS.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016bQ9UWLJjnRQA6sGEYNGPL
- Test preset versioning resolves the CMS presettable

Moved from Core, which can no longer use CMS classes in its tests.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016bQ9UWLJjnRQA6sGEYNGPL

### 📚 Documentation

- *(cms)* Align content-extension seam references to the Shop module
- *(cms)* The graph benchmark now informs a closed decision

### ⚡ Performance

- *(cms)* Imports write each shared category, contributor and tag once per run

### 🧪 Testing

- *(cms)* A content origin rejects a malformed url
- *(cms)* Content list query count does not grow with rows
- *(cms)* Tuning profile regression gate on the application content baseline
- *(cms)* Test the content provider over its dataset without the AI module
- *(cms)* Cover Core's model:create-entity on CMS entities
- *(cms)* Cover Preset::activePresettable() on a CMS preset
- *(locations)* A location can be saved with its own unique name

### ⚙️ Miscellaneous Tasks

- *(cms)* Module priority 10, first tier after Core

## [1.46.0] - 2026-09-29

### 🚀 Features

- *(content-extension)* Add nullable extended_type column to contents (C1, C3)
- *(content-extension)* ContentExtenderRegistry + ExtendsContent contract (C2, C7, C13)
- *(content-extension)* Default-hide scope + withExtended() on Content (C3, C4, C16)
- *(content-extension)* ExtendsContentTrait + stub extender (C2, C8, C15, C17)
- *(content-extension)* Batched upcast resolver (C5, C6, C12, C15)
- *(content-extension)* Both-direction lifecycle with cascade guard (C9, C10)
- *(content-extension)* Index extended contents with an extension section (C11)
- *(content-extension)* Compose the extension index mapping from extenders (C11, C14)
- *(content-extension)* Filter extended contents out of generic search (point 4, C11)
- *(contents)* Add extended_type column to contents table for content extension support
- *(cms)* Dashboard shows editorial work waiting for someone
- *(cms)* Comments resource right after contents
- *(cms)* Drafts are deleted directly, comment deletes skip moderation
- *(cms)* Create and edit pages say Close until something is unsaved
- *(cms)* Content, comment and preset pages report writes sent for approval
- *(search)* Media attached to a content follow the content's visibility (M16)
- *(readme)* Update README with new logo and PHP version badge
- *(filament)* Attach media curation relation manager to Content (media analysis Task 13b, M22)

### 🐛 Bug Fixes

- *(cms)* Content::getEntityType() is public, as the trait declares it
- *(cms)* Run the content extender save on the model connection
- *(cms)* Scope presets to their own entity's type
- *(cms)* The post-import reindex honours the null search driver
- *(cms)* Index Editor.js components as plain text

### 🚜 Refactor

- *(Content)* Remove unused childTypes property and makeFromEntity method
- *(models)* Drop the IdeHelper mixins, declare the validity contract
- *(models)* Declare the search contract on the indexed models
- Narrow findOrFail to a single record with whereKey()->firstOrFail()
- *(cms)* Spell out the row shapes the graph queries return
- *(cms)* Comment adapter exposes its model, drop unread locale setting
- *(cms)* Seed settings without the module prefix
- *(cms)* Replace ucfirst with Str::studly for consistent naming conventions
- *(cms)* Comment capture rides the shared listener
- *(cms)* Comment captures record their operation
- *(cms)* Content no longer overrides the decided-modification flags
- *(cms)* Comments leave the approved event to the vote service

### 📚 Documentation

- *(rag)* Describe how the module is released from the application
- *(content-extension)* Developer doc for the content-extension seam
- *(content-extension)* Generic search now filters extended contents (point 4 built)
- Add work in progress caution to README
- Approvals cover deletes and restores
- *(cms)* Imports queue their search flushes and report them

### ⚡ Performance

- *(migrations)* Index all foreign-key and row-scoping columns
- *(cms)* Eager-load category ancestors for the search document
- *(cms)* Provision import presets and fields once per run
- *(cms)* Imports reuse the resolved presettable instead of reloading it

### 🧪 Testing

- *(casts)* Expect the validation rule Laravel actually has
- Move the last UnitShell file to Integration and drop the directory

### ⚙️ Miscellaneous Tasks

- Rimuove docblock ide-helper generati dai model
- Add IdeHelper mixin annotations to various models for improved IDE support
- Instance call for a locally used static method, and docblock trim
- *(cms)* TagFactory returns a string to uniqueValue()
- *(cms)* Drop commented config entries moved to runtime settings
- *(cms)* Comment capture no longer reads the approval package config

## [1.45.0] - 2026-09-15

### 🚀 Features

- *(cms)* Add default flags to entity configurations in CMSDatabaseSeeder
- *(filament)* Own the module navigation group in the plugin
- *(cms)* Add color attribute to module and update widget description colors
- *(cms)* Include 'is_default' flag in entity creation within CMSDatabaseSeeder
- *(search)* Index Content as locale-keyed objects with locales and agnostic embeddings
- *(search)* Resolve Content mapping analyzers from Core config
- *(search)* Incremental re-embed on ContentTranslation save/delete

### 🐛 Bug Fixes

- *(cms)* Prevent Content edit form Livewire serialization errors
- *(cms)* Record full provenance for imported taxonomies, and filter by preset through the pivot
- *(cms)* Resolve the import entity by type instead of creating one by name
- *(search)* Decouple ContentTranslationObserver from Modules\AI

### 📚 Documentation

- *(cms)* Document provenance, references and AI-assistance disclosure
- *(imports)* The Naxos SQL dump importer no longer exists
- *(changelog)* Regenerate with the corrected git-cliff configuration

### 🎨 Styling

- Format with the application's Pint configuration
- Apply the module's own mb_str_functions rule

### 🧪 Testing

- *(eval)* Regenerate CMS application-content baseline with @k metrics
- *(search)* Assert Scout import query indexes mono-language content while runtime queries stay LocaleScope-filtered
- *(search)* Exercise Scout's real makeAllSearchableQuery() import path
- *(cms)* Move category path tests to feature suite

### ⚙️ Miscellaneous Tasks

- Clean up comments in Content model by removing unnecessary asterisks
- The module carries functionality, not the toolchain
- Rely on Core for the packages it already requires

## [1.44.0] - 2026-09-09

### 🚀 Features

- *(cms)* Log operation for default settings in CMSDatabaseSeeder
- *(cms)* Surface the corrected lock state on contents and entities

### 🐛 Bug Fixes

- *(cms)* Check the comment approve permission by its registered name
- *(cms)* Declare Content as the application-content permission model
- *(cms)* Declare Content embedding vector dimensions from config

### 🚜 Refactor

- *(cms)* Declare always-loaded relations with $with

## [1.43.0] - 2026-09-01

### 🚀 Features

- *(cms)* CMS assistant evaluation dataset

### 🐛 Bug Fixes

- *(cms)* Eager-load place on Location by default

### 🚜 Refactor

- Remove unnecessary comments in Content and Taggable models

### 🧪 Testing

- *(cms)* Flush once() memoization instead of the removed permission cache reset
- *(cms)* Enable the CRUD API via CrudApiExposure instead of Config::set

## [1.42.3] - 2026-08-27

### 🐛 Bug Fixes

- *(tests)* Pin cms.import.locale to en in import feature tests

## [1.42.2] - 2026-08-26

### 🚜 Refactor

- *(factory)* Streamline relation handling in ContentFactory

## [1.42.1] - 2026-08-26

### 🐛 Bug Fixes

- *(factory)* Enhance ContributorFactory to generate unique contributor names

## [1.42.0] - 2026-08-25

### 🚀 Features

- *(cms)* Declare Content facet label source for content type
- *(cms)* Facet categories by parent with the translated parent name
- *(cms)* Map-locations and tag co-occurrence insight endpoints
- *(cms)* Geocode action on the Filament location form
- *(cms)* Endpoint to sync a content's m2m relations by id
- *(cms)* Content validity scope becomes authoring-surface aware
- *(cms)* Validity via a guest-scoped ACL, not a global scope
- *(cms)* Model the tag↔content morph pivot explicitly with timestamps
- *(import)* Register cms.tag, cms.contributor, cms.category entities
- *(cms/import)* Cms.content importer attaching relations by natural key

### 🐛 Bug Fixes

- *(cms)* Serve content relation-sync as a session web route
- *(cms)* Actually filter contents by a relation's translated name or slug
- *(cms)* Seed a locale translation in ContributorFactory so contributors are queryable

### 🚜 Refactor

- *(cms)* Consume the Core-owned media foundation
- *(cms)* Serve the locations map via CRUD select, drop the bespoke endpoint
- *(cms)* Sync content relations via Core CRUD update, drop bespoke route

### ⚡ Performance

- *(cms)* Parallelize dev content pivot relation creation
- *(cms)* Skip versioning during dev content seeding

### 🧪 Testing

- *(cms)* Prove content category facet with translated labels end-to-end
- *(cms)* Prove contributor and location relation facets on contents
- *(cms)* Facet locations by place country via the to-one column facet
- *(cms)* Assert a magic-accessor facet groupBy fails fast
- *(cms)* Map-select payload eager-loads place (mirror the client, avoid N+1)
- *(cms)* Realign stale model/import tests with the translated-content model
- *(cms)* Cover default-presettable resolution from the entity enum
- *(cms)* Opt the relation pagination test into counted totals
- *(cms)* Feature coverage for the generic media API against contents
- *(cms)* Cover media pending-bucket, claim, prune and row-level ACL
- *(cms)* Cover the unified media upload + server-minted draft token

## [1.41.1] - 2026-08-07

### 🐛 Bug Fixes

- *(cms)* Stop hiding created_at and updated_at on models

### 🚜 Refactor

- *(tests)* Update schema column checks in tests
- *(cms)* Optimize content relation creation by using pre-built ID pools

## [1.41.0] - 2026-08-05

### 🚀 Features

- *(cms)* Require approval on live content edits

### 🐛 Bug Fixes

- *(cms)* Soft-keep content modifications after approve/disapprove

### 🚜 Refactor

- *(import)* Emit progress via optional OutputInterface

## [1.40.2] - 2026-08-03

### 🐛 Bug Fixes

- *(migration)* Update lock version column to be non-nullable with default value

### 🚜 Refactor

- *(cms)* Use core import origin registry
- *(cms)* Seed definitions via SeedReconciler, stamped CMS-owned

### 📚 Documentation

- *(cms)* Clarify non-guest retrieval boundary

### 🧪 Testing

- Enforce model connection affinity in CMS helpers
- *(filament)* Assert HasForm strips duplicate entity/presettable fields
- *(cms)* Expect the lock version field in the Content form schema

## [1.40.1] - 2026-07-29

### 🚜 Refactor

- *(filament)* Wire Core HasForm into CMS form schemas
- *(filament)* Delegate entity/preset fields to HasForm

### ⚙️ Miscellaneous Tasks

- *(cms)* Mark module as laraplate_owned

## [1.40.0] - 2026-07-22

### 🐛 Bug Fixes

- Preserve model connections during cms imports

### 🚜 Refactor

- *(cms)* Use Core import command framework

### 📚 Documentation

- *(cms)* Document Core-backed imports

## [1.39.0] - 2026-07-21

### 🚀 Features

- *(cms)* Provide authorized content evidence retrieval

### 📚 Documentation

- *(cms)* Document content retrieval provider

### 🧪 Testing

- *(cms)* Isolate content locale fallback
- *(cms)* Add application content retrieval baseline

## [1.37.1] - 2026-07-16

### 🚀 Features

- *(cms)* Enhance ImportCommand with sibling importers discovery

## [1.37.0] - 2026-07-14

### 🚀 Features

- *(search)* Declare CMS database index intent
- *(cms)* Integrate HasModuleTablesUtils into CMSTables enum for enhanced functionality

### 📚 Documentation

- *(cms)* Document graph provider

### 🧪 Testing

- *(cms)* Add graph runtime benchmark

### ⚙️ Miscellaneous Tasks

- *(docs)* Update README and GLOSSARY with search indexing details

## [1.36.9] - 2026-07-13

### 🚀 Features

- *(Content)* Enhance search schema with filterable relation fields

## [1.36.8] - 2026-07-13

### 🚀 Features

- *(cms)* Implement caching in CMSStatsWidget for improved performance

## [1.36.7] - 2026-07-12

### 🚀 Features

- *(cms)* Register graph provider

### 🚜 Refactor

- *(Content)* Clean up PHPDoc comments by removing unnecessary asterisks
- *(HasRecords)* Optimize entity count aggregation by removing eager loading

### 🧪 Testing

- *(cms)* Migrate ImportEntityNamesTest to RefreshDatabase
- *(cms)* Cover graph expand smoke path
- *(cms)* Cover cross module graph expansion

## [1.36.6] - 2026-07-09

### 🐛 Bug Fixes

- *(import)* Enhance contributor resolution logic in upserter
- *(import)* Add hasImportedRecord method and configuration for skipping existing content

## [1.36.5] - 2026-07-09

### 🚀 Features

- *(cms)* Improve location import matching and expand test coverage

## [1.36.4] - 2026-07-09

### 🚀 Features

- *(import)* Dedup contributors across sources and provision default locally

### 🚜 Refactor

- *(migrations)* Comment out fulltext index creation for slug in locations and tags translations tables

## [1.36.3] - 2026-07-09

### 🚀 Features

- *(cms)* Streamline import entity handling and enhance logging

## [1.36.2] - 2026-07-08

### 🐛 Bug Fixes

- *(import)* Correct cache clearing method in ImportPostProcessor

## [1.36.1] - 2026-07-07

### 🚀 Features

- *(cms)* Enhance import functionality with soft-delete handling and new options

## [1.36.0] - 2026-07-07

### 🚀 Features

- *(cms)* Implement bulk import functionality with supporting DTOs and upserters
- *(cms)* Add generic cms:import command with bulk import runner
- *(cms)* Track import identity and provenance via record origins

### 🚜 Refactor

- *(cms)* Consolidate ai assistance and origin metadata into contents table
- *(cms)* Update ai_assistance handling in contents and translations tables

### ⚙️ Miscellaneous Tasks

- *(cms)* Update PHPDoc for ContentReference model to include IdeHelper annotation

## [1.35.0] - 2026-07-02

### 🚀 Features

- *(cms)* Add content provenance, references and ai assistance metadata

### ⚙️ Miscellaneous Tasks

- *(cms)* Normalize PHPDoc spacing in comment and tag models

## [1.34.4] - 2026-07-01

### 🧪 Testing

- *(cms)* Enhance HasTable trait and improve test coverage

## [1.34.3] - 2026-06-30

### 🧪 Testing

- *(cms)* Align geocoding contract tests and type hints

## [1.34.2] - 2026-06-28

### 🚜 Refactor

- *(cms)* Enhance type hinting and improve query handling

## [1.34.1] - 2026-06-27

### 🚀 Features

- *(cms)* Expand analytics queries and content helpers

## [1.34.0] - 2026-06-27

### 🚀 Features

- *(cms)* Expand taggable helpers and trait integrations

### 🚜 Refactor

- *(Comment)* Update imports and improve type hinting

## [1.33.1] - 2026-06-23

### 🚜 Refactor

- *(cms)* Update Core model and factory concern imports

## [1.33.0] - 2026-06-11

### 🚀 Features

- *(docs)* Added swagger documentation definition inside the module

### 🚜 Refactor

- *(CommentApprovalCapture)* Improve type hinting in transformation function

### 📚 Documentation

- *(docs)* Update paths in phpstan configuration and add glossaries for CMS module

## [1.32.1] - 2026-05-30

### 🐛 Bug Fixes

- *(locations)* Enhance geocoding functionality and secure routes

## [1.32.0] - 2026-05-28

### 🚀 Features

- *(config)* Clean up configuration files and update media library settings

### 🚜 Refactor

- *(tests)* Enhance testing framework with integration tests for CMS module

## [1.31.0] - 2026-05-17

### 🚀 Features

- Enhance CMS module with comments and content ratings functionality

## [1.30.1] - 2026-05-15

### 🚜 Refactor

- Replace hardcoded table names with enum values in model relationships

## [1.30.0] - 2026-05-15

### ⚙️ Miscellaneous Tasks

- Update CMS module configuration and dependencies

## [1.29.0] - 2026-05-09

### 🚀 Features

- Add GeocodeLocationJob for asynchronous geocoding and enhance Content model relations
- Implement Location and Place observers for geocoding jobs

### 🐛 Bug Fixes

- Update pivot keys in Category and Content models, enhance Location model logic, and improve seeder constants
- Remove outdated override attribute in Content model

### 🚜 Refactor

- Update module.json and improve documentation for CMS module

### 📚 Documentation

- Enhance MODULE.md with detailed CMS architecture and functionality

### ⚙️ Miscellaneous Tasks

- *(deps-dev)* Bump axios from 1.15.0 to 1.15.2
- Remove outdated CMS module rules and add new module context file

## [1.28.4] - 2026-05-01

### 🐛 Bug Fixes

- Correct trait usage in Content model and update documentation for CMS module
- Update SoftDeletes trait usage across models and tests

## [1.28.3] - 2026-05-01

### 🚜 Refactor

- Reorganize Presettable model imports and update related methods in Preset and Entity models for consistency
- Integrate PresetVersioningService into CmsDatabaseSeeder and Pest tests to ensure accurate snapshot creation for presettable fields
- Remove FieldResource and related page and schema classes from the CMS module
- Refactorrename module to CMS (namespace Modules\CMS)
- Streamline test setup and remove unused stubs in CMS module

### ⚙️ Miscellaneous Tasks

- *(cms)* Drop empty phpdoc line in Presettable
- *(deps-dev)* Bump postcss from 8.5.6 to 8.5.12

## [1.28.2] - 2026-04-23

### 🚜 Refactor

- Enhance Category model by adding 'logo' and 'logo_full' to fillable attributes and adjusting constructor for clarity
- Change isUserAttribute method visibility from private to protected in Contributor model
- Update categorizable and category translation models to use 'taxonomy_id' instead of 'category_id'; add new migration files for tags, taggables, and related models
- Remove 'relatables' and 'locatables' migration schemas from contributables migration file
- Remove CategoryTranslation model and associated migration files for categories and translations
- Clean up Category, Location, and EventServiceProvider models by removing unused traits, methods, and comments; update CmsDatabaseSeeder to reference the correct Preset model

## [1.28.1] - 2026-04-18

### 🚀 Features

- Introduce CMS-specific models and traits for dynamic content management; add FieldType enum and helper traits for path and slug handling; enhance testing stubs for Core integration
- Add ReadingStatistics cast for calculating reading metrics from Editor.js blocks; integrate with Content model for enhanced content statistics

### 🚜 Refactor

- Remove CreateEntityCommand and associated tests to streamline console command structure and improve code maintainability
- Update model references to use Core module; enhance query methods in PresetResource and ListPresets for improved entity filtering; adjust Entity and Preset models for better integration with Core
- Simplify Entity model by extending CoreEntity and removing unused traits; update Preset model documentation for clarity
- Remove unused traits from models and update validation rules to use parent methods; enhance model documentation with mixin annotations
- Remove FieldType and ObjectCast classes; update CmsDatabaseSeeder to clear caches and enhance test schema for versioning consistency
- Remove SoftDeletes trait from multiple models and enhance migration files with additional fields and timestamps
- Remove unnecessary comments from model documentation to improve clarity
- Update geocoding functionality by replacing IGeocodingService implementation and enhancing Location model with place_id; remove deprecated geocoding services
- Simplify Category model by removing unused traits and methods; change inheritance from Model to Taxonomy and adjust fillable attributes

### ⚙️ Miscellaneous Tasks

- *(deps-dev)* Bump vite from 6.4.1 to 6.4.2
- *(deps-dev)* Bump axios from 1.13.2 to 1.15.0
- *(deps-dev)* Bump follow-redirects from 1.15.11 to 1.16.0

## [1.28.0] - 2026-04-02

### 🚜 Refactor

- Streamline testing commands in composer.json and enhance code coverage settings in phpunit.xml; improve validation hints in CreateEntityCommand and optimize query methods in HasTable and Preset models
- Remove to Core module traits and streamline entity-related models; implement dynamic entity type handling in Category, Content, and Contributor models; enhance Entity model by extending CoreEntity and updating validation rules

### ⚙️ Miscellaneous Tasks

- Update versioning script to enhance composer.json version management and improve error handling

## [1.27.3] - 2026-04-01

### 🚜 Refactor

- Update composer.json and phpstan.neon for improved autoloading and analysis configurations; refactor analytics classes to use dynamic cache TTLs and enhance type hinting in models. Refactored translations models and migrations. Created Factory common abstract class.

### 🧪 Testing

- Harden Cms standalone suite and split integration flow

### ⚙️ Miscellaneous Tasks

- *(deps)* Bump immutable from 5.1.4 to 5.1.5
- Update composer.json to restructure test autoloading, separating unit and feature tests into distinct directories and adding a classmap for TestCase.php
- *(deps)* Bump picomatch

## [1.27.2] - 2026-03-13

### 🚀 Features

- Add comprehensive rules for Cms module, including coding standards, architecture patterns, performance optimization, error handling, testing strategies, and specific guidelines for content management. This establishes a structured approach to development within the module, ensuring consistency and best practices across the codebase.

### 🐛 Bug Fixes

- Update HasDynamicContentFactory to improve component handling and add datetime field support; modify DevCmsDatabaseSeeder to manage model syncing during seeding process.

### 💼 Other

- Set composer package type as laravel-module
- Started refactoring tests

### 🚜 Refactor

- Enhance ListCategories and ListContents to improve table queries; add eager loading for ancestors in Category model and implement path-building logic; update tests for Category model to validate new functionality.
- Implement versioning for presets and enhance dynamic content handling; add snapshot functionality for fields in Presettable model; introduce FieldableObserver for version management; update migrations and tests to support new versioning logic.
- Rename 'authors' to 'contributors' across the module; update related functionality and documentation to reflect this change.
- Enhance ListEntities and ListPresets to include grouping functionality by type and entity; update query modifications and improve tab labels for better clarity.
- Update ListEntities and HasTable to use 'presettable' prefix for entity and preset names, improving consistency in data handling.
- Remove unnecessary accessibility setting for attributes in CategoryTest, streamlining test code.
- Enhance table query configurations in CategoryResource, ContentResource, and PresetResource to include related entities for improved data retrieval.

### ⚙️ Miscellaneous Tasks

- Update license to AGPL-3.0-or-later; add license compliance checker dependency and related test command; adjust versioning script in composer.json
- *(deps)* Bump rollup from 4.53.3 to 4.59.0
- Add composer.lock to .gitignore to prevent tracking of dependency lock file
- *(deps)* Add filament/filament dependency to composer.json for enhanced functionality

## [1.27.1] - 2026-01-30

### 🚜 Refactor

- Update AuthorsTable to set default sorting by name; remove persistence field from CategoryForm and CategoriesTable; enhance ContentsTable with default sorting by created_at; refactor dynamic content handling in HasDynamicContents trait; streamline slug handling in HasSlug trait; adjust translation models to utilize HasSlug; remove persistence from Category model and factory; implement partitioning logic for contents table in migration.

## [1.27.0] - 2026-01-30

### 🚜 Refactor

- Update Pest usage in HasSlugTest to utilize the new 'uses' syntax for improved clarity and consistency
- Replace usage of CoreHasTable with CmsHasTable in various table classes and introduce CmsHasTable trait for enhanced table configuration
- Update Pest test cases to use non-static function syntax for improved readability and consistency across various test files
- Enhance ContentsController constructor to include CrudService and improve dependency injection; add type hint for Response in NominatimService for better clarity

## [1.26.0] - 2026-01-28

### 🚜 Refactor

- Rename slugFields method to slugPlaceholders in HasSlug trait and related models; enhance slug generation logic to support dynamic placeholders and formatting options
- Simplify RouteServiceProvider by removing redundant route mapping methods and updating name property visibility

### 📚 Documentation

- Update caution note in README to include warning emoji for better visibility

## [1.25.0] - 2026-01-21

### 🚜 Refactor

- Update usage of getTranslatableFields method to use static context in HasTranslatedDynamicContents trait and related models; implement ITranslated interface in translation models for improved consistency

### 📚 Documentation

- Clarify AI integration details in README; specify that AI features are provided by the AI module when enabled and enhance notes on embedding extraction and automated content analysis

## [1.24.0] - 2026-01-15

### 🚜 Refactor

- Update versioning mechanism in composer.json and enhance command descriptions; remove unused translation commands

## [1.23.0] - 2026-01-09

### 🚀 Features

- Add CmsStatsWidget for displaying content statistics including total contents and authors

### 🚜 Refactor

- Enhance dynamic content management by introducing shared_components for non-translatable fields, updating related traits and models to support this new structure

## [1.22.7] - 2025-12-22

### 🚜 Refactor

- Simplify content translation handling in ContentFactory by removing unnecessary log statements and using existing content properties for title, slug, and components
- Remove searchableAs method from Content model to streamline code and improve maintainability

## [1.22.6] - 2025-12-22

### 🚜 Refactor

- Update various closures to use static function syntax for improved performance and consistency across the codebase
- Enhance dynamic content handling by adding #[Override] attributes to getAttribute and setAttribute methods in HasDynamicContents and HasTranslatedDynamicContents traits, and update Content model to use presettable_id for improved clarity and consistency
- Remove #[Override] attributes from getAttribute and setAttribute methods in HasDynamicContents, HasTranslatedDynamicContents, Content, and Field models for improved clarity and consistency
- Simplify Elasticsearch client configuration in AbstractAnalytics by utilizing ClientBuilder::fromConfig for improved readability and maintainability

## [1.22.5] - 2025-12-19

### 🚜 Refactor

- Optimize role retrieval in CmsDatabaseSeeder by caching all roles, improving performance and code clarity
- Simplify translation handling in HasTranslatedDynamicContents trait by removing unnecessary checks and directly returning merged translations

### ⚙️ Miscellaneous Tasks

- Update cliff.toml to disable filtering of unconventional commits and add parser to skip initial commit messages for improved commit history management

## [1.22.4] - 2025-12-19

### ⚙️ Miscellaneous Tasks

- Enhance version update script to support dry-run and allow-dirty options, and update cliff.toml to skip divergent merge conflict messages

## [1.22.3] - 2025-12-19

### ⚙️ Miscellaneous Tasks

- Add commit parsers to skip merge-related messages in cliff.toml for cleaner commit history

## [1.22.2] - 2025-12-19

### 🚜 Refactor

- Update migration files to support MySQL and MariaDB triggers for self-referencing constraints, enhance geolocation handling across different database drivers, and improve fulltext index conditions for better compatibility

## [1.22.0] - 2025-12-17

### 🚜 Refactor

- Upgrade Pest testing framework to version 4.0, update test syntax to use 'it' instead of 'test', and enhance method existence checks for improved clarity and consistency across various model tests
- Update Category model to use self::DEFAULT_RULE for default validation, enhance ordered query method for better scope handling, and improve test setup for Author, Category, and Content models with necessary entity and preset creation
- Enhance dynamic content handling in HasDynamicContents and HasTranslatedDynamicContents traits, improve attribute access and setting methods, and update related models and factories for better integration and clarity

### ⚙️ Miscellaneous Tasks

- Update PHP version requirement to 8.5 in composer.json and README.md, refactor GeocodeLocationAction to use readonly properties, and improve type checks in DynamicContentsService and related classes for better code quality

## [1.21.0] - 2025-12-13

### 🚀 Features

- Implement GetContentsByRelationAction and GeocodeLocationAction for enhanced content retrieval and geocoding functionality, introduce DynamicContentsService for caching, and refactor geocoding services to use an abstract base class for improved structure and maintainability

### 🚜 Refactor

- Update translation service resolution method in TranslateContentCommand and TranslateMissingCommand, and improve attribute visibility in Content model for better encapsulation
- Standardize function syntax in HasDynamicContents and HasPath traits, enhance model documentation with IdeHelper annotations, and clean up unused imports in migration and seeder files for improved code clarity
- Update PHPStan configuration level, enhance model documentation with template annotations, and improve method return type hints across various models for better type safety and clarity

## [1.20.2] - 2025-12-05

### 🚜 Refactor

- Update dynamic content handling in HasDynamicContentFactory and HasDynamicContents, improve attribute setting methods, and enhance Category and Content models with new dynamic content features

## [1.20.1] - 2025-12-05

### 🚜 Refactor

- Simplify loading of presettable in HasDynamicContentFactory and remove commented-out code for improved clarity and maintainability
- Enhance HasTranslatedDynamicContents with new component attribute methods and update DevCmsDatabaseSeeder to use parallel batch creation for improved performance

## [1.20.0] - 2025-12-02

### 🚜 Refactor

- Remove tightenco/parental dependency, delete unused CreateContentModelCommand, and implement translation models for authors, categories, contents, and tags to enhance dynamic content management and maintainability

## [1.19.0] - 2025-11-17

### 🚜 Refactor

- Rename 'preset_id' to 'presettable_id' across various models and forms, update relationships accordingly, and remove unused columns from tables to enhance code clarity and maintainability

### ⚙️ Miscellaneous Tasks

- Update package dependencies, enhance database transaction handling, and improve GeocodeRequest documentation for better clarity and maintainability

## [1.18.0] - 2025-10-28

### 🚀 Features

- Introduce Presettable model and update relationships in content management, enhancing dynamic content handling and database structure

### 🚜 Refactor

- Update exception handling and logging in analytics and geocoding services, improve string formatting in various commands and models for better readability and maintainability

## [1.17.0] - 2025-10-21

### 🚀 Features

- Implement migration updates and add comprehensive tests for content management, including category, location, and content models, enhancing database integrity and functionality

### ⚙️ Miscellaneous Tasks

- Update composer scripts and configurations for improved linting and testing processes, enhance PHPStan settings, and refactor Filament resource classes to be final for better code integrity
- Code lint with pint and rector

## [1.16.1] - 2025-10-13

### 🐛 Bug Fixes

- Remove unused import of Parental\HasChildren and add import for Modules\Core\Helpers\HasChildren to improve code clarity and maintainability

## [1.16.0] - 2025-09-30

### 🚀 Features

- Enhance Content model with search mapping and improve data structure for authors, categories, tags, and locations. Updated post-commit hook to reference the correct version script path.
- Add silent mode logging to version update script for improved user feedback
- Enhance version determination logic to analyze commit messages since the last tag, improving version bump accuracy and user feedback
- Add commit importance analysis function to enhance version determination logic, allowing for better categorization of changes based on commit messages
- Add debug logging in silent mode for version update process to enhance visibility of version changes

### 🐛 Bug Fixes

- Add check for up-to-date version in version update script to prevent unnecessary updates
- Ensure proper return value in is_already_tagged function for accurate tagging checks
- Refactor version determination logic to return values instead of echoing for improved consistency and clarity
- Refactor version determination logic to use echo statements for improved readability and maintainability
- Remove unnecessary echo statements in update_version function for cleaner output and improved maintainability
- Remove deprecated determine_release_type function to streamline version determination logic and improve maintainability
- Remove unnecessary echo statements in determine_release_type function to improve clarity and maintainability of version determination logic
- Remove redundant echo statement in determine_release_type function to enhance clarity and maintainability of version determination logic

### ⚙️ Miscellaneous Tasks

- Remove version v1.15.0 from composer.json to streamline version management
- Update composer.json to include version script for streamlined version management

## [1.15.0] - 2025-09-27

### 🚀 Features

- Added Locatable pivot model and updated Content model to include location relationships. Refactored code for consistency and readability.

### 💼 Other

- Merge divergent conflict

### ⚙️ Miscellaneous Tasks

- Enable strict types and enhance code consistency

## [1.14.0] - 2025-09-25

### 🚀 Features

- Add textual content extraction and FieldType enhancement

## [1.13.4] - 2025-09-23

### 🚜 Refactor

- Enhance multimedia handling and table configurations

## [1.13.3] - 2025-09-20

### 🚜 Refactor

- Introduce HasRecords trait and streamline list pages

## [1.13.1] - 2025-09-19

### 🐛 Bug Fixes

- Correct function name and add uncommitted changes check in version script

## [1.13.0] - 2025-09-19

### 🚜 Refactor

- Update navigation icons and enhance table configurations

## [1.12.0] - 2025-09-05

### 🚜 Refactor

- Enhance content factory and validation rules
- Standardize route comments in controller files
- Improve dynamic content handling and geocoding services
- Update README and enhance dynamic content handling

## [1.11.5] - 2025-07-24

### 🐛 Bug Fixes

- Correct changelog output command in version script

### 🚜 Refactor

- Update module.json and improve content handling

### ⚙️ Miscellaneous Tasks

- Add versioning scripts to composer.json

## [1.11.3] - 2025-06-23

### 💼 Other

- Update README.md with complete configuration documentation

### 🚜 Refactor

- Improve code clarity and type safety in NominatimService and GeocodingServiceInterface
- Update service interfaces and improve code clarity
- Enhance singleton pattern implementation in geocoding services
- Improve PHPDoc comments for IGeocodingService interface
- Update README and PHPDoc comments for models
- Update model properties and improve PHPDoc comments
- Enhance code structure and improve PHPDoc comments
- Update dynamic content handling and improve code structure
- Update FieldType and enhance dynamic content rules

### ⚙️ Miscellaneous Tasks

- Add changelog and configuration for git-cliff
- Add configuration files for PHPStan, Pint, and Rector
- Update configuration and scripts for version management

## [1.11.2] - 2025-05-05

### 🚜 Refactor

- Clean up PHPDoc comments in Field and Location models
- Enhance type safety and code clarity in analytics and helpers
- Enhance type safety and clarity in model methods

## [1.11.1] - 2025-05-05

### 🚜 Refactor

- Enhance analytics and entity management
- Implement singleton pattern in NominatimService and enhance analytics functionality
- Implement singleton pattern in GoogleMapsService for improved instance management
- Enhance geocoding services and improve caching logic
- Standardize code structure and enhance type safety across services and models
- Improve code consistency and type safety in services and analytics

## [1.10.5] - 2025-04-07

### 🚜 Refactor

- Enhance category model and factory logic

## [1.10.4] - 2025-04-06

### 🚜 Refactor

- Enhance approval logic and improve factory definitions

## [1.10.3] - 2025-04-06

### 🚜 Refactor

- Improve slug generation logic in HasSlug trait

## [1.10.2] - 2025-04-05

### ⚙️ Miscellaneous Tasks

- Update PHP version requirement and refactor analytics classes

## [1.10.1] - 2025-03-31

### 🚜 Refactor

- Optimize caching in analytics classes

## [1.10.0] - 2025-03-28

### 🚜 Refactor

- Improve path and slug handling in models and factories

## [1.9.0] - 2025-03-20

### 🚀 Features

- Implement analytics classes for content and location metrics

## [1.8.0] - 2025-03-11

### 🚀 Features

- Add soft delete support for media files

## [1.7.1] - 2025-03-07

### 🚀 Features

- Register GeocodingServiceProvider in CmsServiceProvider

## [1.7.0] - 2025-03-07

### ⚙️ Miscellaneous Tasks

- Update project dependencies and configuration files

## [1.6.0] - 2025-03-03

### 🚀 Features

- Add GeocodingServiceProvider for dynamic geocoding service configuration

## [1.5.1] - 2025-02-28

### 🚜 Refactor

- Modernize PHP codebase with PHP 8.2 features and code improvements

## [1.5.0] - 2025-02-26

### 🚜 Refactor

- Enhance geocoding services with improved caching and error handling

## [1.4.0] - 2025-02-21

### 🚀 Features

- Implement geocoding services with flexible provider support

## [1.3.0] - 2025-02-19

### 🚜 Refactor

- Improve dependency injection and error handling in CMS module

## [1.2.0] - 2025-02-18

### 🚜 Refactor

- Update console commands to use custom Command override

## [1.1.2] - 2025-02-16

### 🐛 Bug Fixes

- Improve entity caching with error handling

## [1.1.1] - 2025-02-04

### 🐛 Bug Fixes

- Adjust CMS service provider and API route configuration

## [1.1.0] - 2025-01-29

### 🚜 Refactor

- Improve model and factory configurations

## [1.0.0] - 2025-01-25

### 🚀 Features

- Enhance version management with composer.json update

### 💼 Other

- Init project
- Add version management scripts and Git hooks

<!-- generated by git-cliff -->
