# CMS module glossary

Canonical English names for CMS entities in this module. Use these terms in code, APIs, and cross-module documentation.

## Dynamic content model

| Term | Meaning |
|------|---------|
| **Entity** | CMS subclass of `Core\Models\Entity`; discriminates content trees via `EntityType`. |
| **EntityType** | Enum: `contents`, `contributors`, `categories`. |
| **Preset** | Versioned field layout for an `Entity`. |
| **Presettable** | Snapshot binding one `Preset` to one `Entity`; domain rows reference `presettable_id`. |
| **Field** | CMS field definition extending Core dynamic-field infrastructure. |
| **Content** | Primary publication record; carries `entity_id`, `presettable_id`, dynamic attributes. |
| **ContentObserver** | Auto-assigns `entity_id` / `presettable_id` on create when missing. |
| **Template** | Reusable content layout template. |

## Taxonomy and contributors

| Term | Meaning |
|------|---------|
| **Category** | Hierarchical taxonomy node (`Core\Models\Taxonomy` subclass). |
| **Tag** | Flat label attached to contents via `HasTags`. |
| **Author** | Contributor profile for bylines and attribution. |
| **Indexed CMS relation field** | Contributor/category/tag/location data copied into the content search document and filterable through schema-declared dot paths. |

## Media and location

| Term | Meaning |
|------|---------|
| **Media** | Core-owned Spatie Media Library model (`Core\Models\Media`, `vend_media` table); CMS consumes it, it is not CMS-owned. |
| **HasMultimedia** | CMS trait wiring upload collections and conversions on top of the Core `HasMedia` foundation trait. |
| **Location** | Geo-tagged place metadata for content. |
| **GeocodeLocationAction** | Resolves coordinates via Core `IGeocodingService`. |
| **HasPlace** | Core trait linking `Location` to a `Place` row. |

## Comments and ratings

| Term | Meaning |
|------|---------|
| **Comment** | User comment on a `Content`; hidden until `Modification` is approved. |
| **CommentTranslation** | Locale-specific comment body storage. |
| **CommentTranslationScope** | Scopes public queries to approved, visible comments. |
| **ContentRating** | Star or score rating linked to a comment/content. |
| **ContentRatingService** | Aggregates and persists rating data after comment approval. |
| **CommentApprovalCapture** | Builds `Modification` diff on comment save (body, locale, rating). |
| **CommentModerationContextBuilder** | Registers with Core; supplies article + body context for AI moderation. |

## Core traits reused by CMS

| Term | Meaning |
|------|---------|
| **HasApprovals** | Pending-change workflow for comments and other modifiable models. |
| **HasTranslations** | Multi-locale attribute storage on `Content`, `Comment`, etc. |
| **HasTranslatedDynamicContents** | Translates dynamic preset fields on content records. |
| **HasLocks** | Prevents concurrent edits on locked content rows. |
| **HasValidity** | Publication window (`valid_from` / `valid_to`). |
| **Searchable** | Queues indexing via Core `ModelRequiresIndexing`. |
| **Content relation-field filter** | Public search filter such as `tags.id` or `locations.country`; Core applies it through the active search driver before pagination. |

## Filament integration

| Term | Meaning |
|------|---------|
| **CMSPlugin** | Registers CMS Filament resources on the admin panel. |
| **HasRecords** | Filament utility trait for shared record helpers. |

## Cross-module orchestration

| Term | Meaning |
|------|---------|
| **ModificationRequiresModeration** | Core event emitted when a comment `Modification` is created. |
| **ModificationApproved** | Core event after human approval; triggers optional auto-translate. |
| **ModerationContextBuilderRegistry** | Core registry; CMS registers `CommentModerationContextBuilder`. |

CMS **does not** import AI classes or read `config('ai.*')` directly.

## Imports

| Term | Meaning |
|------|---------|
| **CMS bulk importer** | External or local adapter implementing the CMS marker contract and writing through the CMS destination pipeline. |
| **`cms:import`** | CMS-owned Artisan entry point built on Core's abstract import framework. |
| **Importer-declared connection** | Optional destination connection used by Core to isolate a transactional dry-run. |
| **Synchronization** | Ongoing external-system exchange with cursors, identity mapping, conflict rules, retries, and scheduling; not implied by a batch import. |

## Related reading

- `docs/COMMENT_MODERATION.md` — comment capture and approval flow
- `docs/IMPORTS.md` — CMS bulk import command and extension boundary
- `docs/rag/MODULE.md` — RAG-oriented CMS overview
- `Modules/Core/docs/EVENT_ORCHESTRATION.md` — event bus contracts
- `Modules/AI/docs/MODERATION.md` — AI-side moderation pipeline
