<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * ReactionRepository / ReactionTypeRepository 검증.
 *
 * upsert(등록/전환)·delete(해제)·adjustPostReactionCounts(원자 카운트) 및
 * 유형 조회(getActive/findByCodes/findById/findByIds)를 검증한다.
 */
class ReactionRepositoryTest extends BoardTestCase
{
    private ReactionRepositoryInterface $reactionRepository;

    private ReactionTypeRepositoryInterface $reactionTypeRepository;

    private int $likeId;

    private int $dislikeId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->reactionRepository = app(ReactionRepositoryInterface::class);
        $this->reactionTypeRepository = app(ReactionTypeRepositoryInterface::class);
    }

    /**
     * upsert 는 없으면 INSERT, 있으면 유형만 UPDATE 한다 (사용자당 1행 유지).
     */
    public function test_upsert_inserts_then_updates_single_row(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $first = $this->reactionRepository->upsert($user->id, 'post', $postId, $this->likeId, $this->board->id);
        $this->assertSame($this->likeId, (int) $first->reaction_type_id);

        $second = $this->reactionRepository->upsert($user->id, 'post', $postId, $this->dislikeId, $this->board->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($this->dislikeId, (int) $second->reaction_type_id);
        $this->assertSame(1, \Modules\Sirsoft\Board\Models\Reaction::query()
            ->where('user_id', $user->id)->where('target_id', $postId)->count());
    }

    /**
     * findByUserAndTarget 은 사용자+대상 유일 키로 조회한다.
     */
    public function test_find_by_user_and_target(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();
        $this->reactionRepository->upsert($user->id, 'post', $postId, $this->likeId, $this->board->id);

        $found = $this->reactionRepository->findByUserAndTarget($user->id, 'post', $postId);
        $this->assertNotNull($found);
        $this->assertSame($this->likeId, (int) $found->reaction_type_id);

        $this->assertNull($this->reactionRepository->findByUserAndTarget($user->id, 'post', $postId + 999));
    }

    /**
     * delete 는 이력 행을 삭제한다.
     */
    public function test_delete_removes_reaction(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();
        $reaction = $this->reactionRepository->upsert($user->id, 'post', $postId, $this->likeId, $this->board->id);

        $this->assertTrue($this->reactionRepository->delete($reaction));
        $this->assertNull($this->reactionRepository->findByUserAndTarget($user->id, 'post', $postId));
    }

    /**
     * adjustPostReactionCounts 는 유형별 증감을 적용하고 0 미만으로 내려가지 않는다.
     */
    public function test_adjust_post_reaction_counts_applies_deltas_and_clamps(): void
    {
        $postId = $this->createTestPost(['reaction_counts' => json_encode([(string) $this->likeId => 2])]);

        $counts = $this->reactionRepository->adjustPostReactionCounts($postId, [
            $this->likeId => -1,
            $this->dislikeId => 1,
        ]);

        $this->assertSame(1, $counts[(string) $this->likeId]);
        $this->assertSame(1, $counts[(string) $this->dislikeId]);

        // 클램프: 0 아래로 내려가지 않음
        $clamped = $this->reactionRepository->adjustPostReactionCounts($postId, [$this->likeId => -5]);
        $this->assertSame(0, $clamped[(string) $this->likeId]);

        $post = Post::findOrFail($postId);
        $this->assertSame(0, $post->reaction_counts[(string) $this->likeId]);
    }

    /**
     * getActive 는 활성 유형을 display_order 순으로 반환한다.
     */
    public function test_get_active_returns_ordered_active_types(): void
    {
        $active = $this->reactionTypeRepository->getActive();

        $this->assertSame(['like', 'dislike'], $active->pluck('code')->all());
    }

    /**
     * findByCodes 는 code 목록으로 활성 유형만 조회한다.
     */
    public function test_find_by_codes(): void
    {
        $found = $this->reactionTypeRepository->findByCodes(['dislike']);
        $this->assertSame(['dislike'], $found->pluck('code')->all());

        $this->assertTrue($this->reactionTypeRepository->findByCodes([])->isEmpty());
    }

    /**
     * findById / findByIds 로 유형을 조회한다.
     */
    public function test_find_by_id_and_ids(): void
    {
        $this->assertSame('like', $this->reactionTypeRepository->findById($this->likeId)?->code);
        $this->assertNull($this->reactionTypeRepository->findById(999999));

        $byIds = $this->reactionTypeRepository->findByIds([$this->likeId, $this->dislikeId]);
        $this->assertSame(['like', 'dislike'], $byIds->pluck('code')->all());
        $this->assertTrue($this->reactionTypeRepository->findByIds([])->isEmpty());
    }
}
