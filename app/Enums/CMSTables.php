<?php

declare(strict_types=1);

namespace Modules\CMS\Enums;

use Modules\Core\Enums\Concerns\HasModuleTablesUtils;

enum CMSTables: string
{
    use HasModuleTablesUtils;

    // cms models
    case Tags = 'cms_tags';
    case Taggables = 'cms_taggables';
    case Contributors = 'cms_contributors';
    case Locations = 'cms_locations';
    case Contents = 'cms_contents';
    case Comments = 'cms_comments';
    case ContentRatings = 'cms_contents_ratings';
    case ContentsReferences = 'cms_contents_references';

    // translations
    case ContentsTranslations = 'cms_contents_translations';
    case ContributorsTranslations = 'cms_contributors_translations';
    case TagsTranslations = 'cms_tags_translations';
    case CommentsTranslations = 'cms_comments_translations';

    // pivots
    case Categorizables = 'cms_categorizables';
    case Locatables = 'cms_locatables';
    case Relatables = 'cms_relatables';
    case Contributables = 'cms_contributables';

    // closure tables of the hierarchies ({@see \Modules\Core\Helpers\MigrateUtils::closureTable()})
    case CommentsClosure = 'cms_comments_closure';
}
