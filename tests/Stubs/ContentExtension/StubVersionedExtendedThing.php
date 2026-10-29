<?php

declare(strict_types=1);

namespace Modules\CMS\Tests\Stubs\ContentExtension;

use Modules\CMS\Contracts\ExtendsContent;
use Modules\CMS\Models\Concerns\ExtendsContentTrait;
use Override;

/**
 * Test-only content extender with a history of its own: a root of its own, whose hard delete with its content
 * writes a snapshot set of its own (D43).
 */
final class StubVersionedExtendedThing extends VersionedExtenderBase implements ExtendsContent
{
    use ExtendsContentTrait;

    public const string TABLE = 'cms_stub_versioned_extended_things';

    protected $table = self::TABLE;

    /**
     * @var list<string>
     */
    protected $fillable = ['content_id', 'brand'];

    protected array $versionable = ['brand'];

    #[Override]
    public function contentAlias(): string
    {
        return 'cms.stub_versioned';
    }

    #[Override]
    public function searchableExtension(): array
    {
        return ['brand' => $this->brand];
    }

    #[Override]
    public function searchableExtensionMapping(): array
    {
        return [];
    }
}
