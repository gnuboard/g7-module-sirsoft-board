<?php

namespace Modules\Sirsoft\Board\Repositories;

use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;

/**
 * 반응 이력 Repository
 *
 * 반응 이력 데이터 접근 계층을 담당합니다.
 */
class ReactionRepository implements ReactionRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function findByUserAndTarget(int $userId, string $targetType, int $targetId): ?Reaction
    {
        return Reaction::query()
            ->where('user_id', $userId)
            ->byTarget($targetType, $targetId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(int $userId, string $targetType, int $targetId, int $reactionTypeId, ?int $boardId): Reaction
    {
        return Reaction::updateOrCreate(
            [
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ],
            [
                'reaction_type_id' => $reactionTypeId,
                'board_id' => $boardId,
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Reaction $reaction): bool
    {
        return (bool) $reaction->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function adjustPostReactionCounts(int $postId, array $deltas): array
    {
        /** @var Post $post */
        $post = Post::query()->lockForUpdate()->findOrFail($postId);

        $counts = $post->reaction_counts ?? [];

        foreach ($deltas as $typeId => $delta) {
            $key = (string) $typeId;
            $next = (int) ($counts[$key] ?? 0) + $delta;
            $counts[$key] = max(0, $next);
        }

        $post->reaction_counts = $counts;
        $post->save();

        return $counts;
    }
}
