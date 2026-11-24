<?php

declare(strict_types=1);

namespace Modules\CMS\Services;

use Illuminate\Support\Collection;
use JsonException;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\ContentRating;
use Modules\CMS\Models\Translations\CommentTranslation;
use Modules\Core\Approvals\Operation;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Modification;

final class CommentApprovalCapture
{
    /**
     * @param  array<string, array{original: mixed, modified: mixed}>  $diff
     * @return array<string, array{original: mixed, modified: mixed}>
     */
    public static function enrichDiff(Comment $comment, array $diff): array
    {
        if ($comment->hasPendingBodyForCurrentLocale()) {
            $existing = $comment->getOriginalTranslation();
            $modified_body = $comment->getAttribute('body');
            $original_body = $existing instanceof CommentTranslation ? $existing->body : null;

            $diff['body'] = [
                'original' => $original_body,
                'modified' => is_string($modified_body) ? $modified_body : '',
            ];
        }

        if (isset($diff['body'])) {
            $diff['locale'] = [
                'original' => null,
                'modified' => LocaleContext::get(),
            ];
        }

        if ($comment->getAttribute('content_id') !== null) {
            $diff['content_id'] = [
                'original' => $comment->getOriginal('content_id'),
                'modified' => $comment->content_id,
            ];
        }

        if ($comment->parent_id !== null) {
            $diff['parent_id'] = [
                'original' => $comment->getOriginal('parent_id'),
                'modified' => $comment->parent_id,
            ];
        }

        if ($comment->pending_rating_score !== null) {
            $existing_score = ContentRating::query()
                ->where('content_id', $comment->content_id)
                ->where('user_id', $comment->user_id)
                ->value('score');

            $diff['rating_score'] = [
                'original' => is_numeric($existing_score) ? (int) $existing_score : null,
                'modified' => $comment->pending_rating_score,
            ];
        }

        return $diff;
    }

    /**
     * Turn a comment write into a pending modification. Returns that modification, or null when the
     * comment has nothing to capture and may be saved directly.
     */
    public static function capture(Comment $comment): ?Modification
    {
        $diff = Collection::make($comment->getDirty())
            ->transform(static fn (mixed $value, string $key): array => [
                'original' => $comment->getOriginal($key),
                'modified' => $comment->{$key},
            ])
            ->all();

        $diff = self::enrichDiff($comment, $diff);

        if ($diff === []) {
            return null;
        }

        $encoded_diff = self::encodeDiff($diff);
        $modifications_relation = $comment->modifications();
        $modifications_table = $modifications_relation->getRelated()->getTable();
        $diff_hash = md5($encoded_diff);

        $has_modification_pending = $modifications_relation
            ->where($modifications_table . '.active', 1)
            ->where($modifications_table . '.md5', $diff_hash)
            ->first();

        $modifier = auth()->user();

        /** @var Modification $modification */
        $modification = $has_modification_pending ?? new Modification();
        $modification->active = true;
        $modification->modifications = $diff;
        $modification->approvers_required = 1;
        $modification->disapprovers_required = 1;
        $modification->md5 = md5($encoded_diff);

        if ($modifier !== null) {
            $modifier_class = $modifier::class;
            $modifier_instance = new $modifier_class();
            $modification->modifier_id = $modifier->{$modifier_instance->getKeyName()};
            $modification->modifier_type = $modifier_class;
        }

        $modification->operation = $comment->getKey() === null ? Operation::Create : Operation::Update;

        $modification->save();

        if ($has_modification_pending === null) {
            $comment->modifications()->save($modification);
        }

        return $modification;
    }

    /**
     * @param  array<string, array{original: mixed, modified: mixed}>  $diff
     */
    private static function encodeDiff(array $diff): string
    {
        try {
            return json_encode($diff, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }
    }
}
