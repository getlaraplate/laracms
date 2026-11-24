<?php

declare(strict_types=1);

namespace Modules\CMS\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\User;
use Override;

/**
 * @extends Factory<Comment>
 */
final class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<model-property<Comment>, mixed>
     */
    public function definition(): array
    {
        return [
            'content_id' => Content::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * A factory comment is a published one: it carries its text and skips moderation, the way an
     * approved comment is written.
     */
    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(static function (Comment $comment): void {
            $comment->setForcedApprovalUpdate(true);
        });
    }

    public function withBody(string $body, ?string $locale = null): static
    {
        return $this->afterCreating(function (Comment $comment) use ($body, $locale): void {
            $locale ??= LocaleContext::get();

            $comment->translations()->updateOrCreate(
                ['locale' => $locale],
                ['body' => $body],
            );
        });
    }

    public function approved(string $body = 'Approved comment body.'): static
    {
        return $this->withBody($body);
    }
}
