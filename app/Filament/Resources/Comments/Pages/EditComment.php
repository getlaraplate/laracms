<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Comments\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\CMS\Filament\Resources\Comments\CommentResource;
use Modules\CMS\Models\Comment;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\Core\Filament\Utils\ReportsApprovalOutcome;
use Override;

/**
 * The comment text lives on the translation of the current locale, not on a column,
 * so it is read from and written back through the model's translation accessors.
 */
final class EditComment extends EditRecord
{
    use HasCloseOrCancelFormAction;
    use ReportsApprovalOutcome;

    #[Override]
    protected static string $resource = CommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::approvalAwareDeleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Comment $comment */
        $comment = $this->getRecord();

        return [...$data, 'body' => $comment->body];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Comment $record */
        $record->body = is_string($data['body'] ?? null) ? $data['body'] : '';
        $this->saveReportingApprovalOutcome($record);

        return $record;
    }
}
