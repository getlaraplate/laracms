<?php

declare(strict_types=1);

namespace Modules\CMS\Tests\Stubs\ContentExtension;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\CMS\Contracts\ExtendsContent;
use Modules\CMS\Models\Content;
use Override;

/**
 * Minimal non-model stub implementing {@see ExtendsContent}, used to exercise the
 * {@see \Modules\CMS\Services\ContentExtenderRegistry} in isolation. The registry never calls
 * {@see self::content()}, so it throws rather than build a real relation.
 */
final class FakeContentExtender implements ExtendsContent
{
    #[Override]
    public function contentAlias(): string
    {
        return 'cms.fake_extender';
    }

    #[Override]
    public function contentIsMandatory(): bool
    {
        return true;
    }

    #[Override]
    public function content(): BelongsTo
    {
        throw new LogicException('FakeContentExtender::content() is not used by the registry.');
    }

    #[Override]
    public function searchableExtension(): array
    {
        return [];
    }

    #[Override]
    public function searchableExtensionMapping(): array
    {
        return [];
    }

    #[Override]
    public function extendedModel(): string
    {
        return Content::class;
    }

    #[Override]
    public function extensionAlias(): string
    {
        return $this->contentAlias();
    }

    #[Override]
    public function extensionHostKey(): string
    {
        return 'content_id';
    }

    #[Override]
    public function extensionHostRelation(): string
    {
        return 'content';
    }

    #[Override]
    public function extensionOwnColumns(): array
    {
        return ['id', 'content_id'];
    }

    #[Override]
    public function extensionEntityType(): ?string
    {
        return null;
    }
}
