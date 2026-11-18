<?php

declare(strict_types=1);

namespace Modules\CMS\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CMS\Models\Content;
use Modules\Core\Contracts\IExtendsModel;

/**
 * Implemented by a domain model that extends a {@see Content} through the content-extension seam.
 *
 * The extender lives in its own table with a `content_id` foreign key; CMS knows it only by its
 * stable alias, resolved through the Core {@see \Modules\Core\Search\Extensions\ExtensionRegistry} under
 * {@see Content}. CMS never references the concrete extender class. It is the Core extender contract
 * ({@see IExtendsModel}) for the host {@see Content}; {@see \Modules\CMS\Models\Concerns\ExtendsContentTrait}
 * implements the Core part.
 */
interface ExtendsContent extends IExtendsModel
{
    /**
     * The stable morph alias for this extender (convention `module.model`, e.g. `shop.product`).
     */
    public function contentAlias(): string;

    /**
     * Whether the extender cannot exist without its content.
     *
     * `true` (the common case): deleting the content cascades to delete the extender — there is no
     * bodiless-orphan state. `false`: deleting the content orphans the extender (its `content_id` is
     * nulled) rather than deleting it. See seam decision C9.
     */
    public function contentIsMandatory(): bool;

    /**
     * The back-relation to the extended content, with the hide scope removed so it resolves.
     *
     * @return BelongsTo<Content, Model>
     */
    public function content(): BelongsTo;

    /**
     * The extender's own data, merged into the content's search document under a nested `extension`.
     *
     * @return array<string, mixed>
     */
    public function searchableExtension(): array;

    /**
     * The mapping fragment CMS composes into the `contents` index under the nested `extension`
     * object. Keyed by field name, each value in the Core search-schema `properties` format:
     * a `FieldType` (e.g. `FieldType::Keyword`) or `['type' => FieldType, 'filterable' => bool]`.
     *
     * @return array<string, mixed>
     */
    public function searchableExtensionMapping(): array;
}
