<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Exceptions\ReactionNotAllowedException;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * ReactionService 검증.
 *
 * 등록/전환/해제, reaction_counts 증감 정합성(전환 포함), use_reaction off,
 * 비활성 유형, 본인 글 차단, 스코프 검증을 검증한다 (이슈 #525 §10 테스트 범위).
 */
class ReactionServiceTest extends BoardTestCase
{
    private ReactionService $service;

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

        $this->updateBoardSettings([
            'use_reaction' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);

        $this->service = app(ReactionService::class);
    }

    /**
     * 반응이 없던 게시글에 반응하면 신규 등록되고 카운트가 +1 된다.
     *
     * @scenario case=register_first_like
     * @effects register_inserts_row_and_increments_count
     */
    public function test_react_adds_new_reaction_and_increments_count(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $result = $this->service->react($reactor->id, $this->board, $postId, $this->likeId);

        $this->assertSame('add', $result['action']);
        $this->assertSame($this->likeId, $result['reaction_type_id']);
        $this->assertSame(1, $result['reaction_counts'][(string) $this->likeId]);

        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reaction_type_id' => $this->likeId,
        ]);
    }

    /**
     * 다른 유형으로 전환하면 이전 유형 -1·신규 유형 +1 이 동시 반영된다 (단일 트랜잭션).
     *
     * @scenario case=switch_like_to_dislike
     * @effects switch_updates_row_and_adjusts_both_counts, switch_count_atomic_in_single_transaction
     */
    public function test_react_switches_type_and_adjusts_both_counts(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->service->react($reactor->id, $this->board, $postId, $this->likeId);
        $result = $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->dislikeId);

        $this->assertSame('change', $result['action']);
        $this->assertSame(0, $result['reaction_counts'][(string) $this->likeId]);
        $this->assertSame(1, $result['reaction_counts'][(string) $this->dislikeId]);

        // 사용자당 1행 유지 (전환은 UPDATE)
        $this->assertSame(1, Reaction::query()->where('user_id', $reactor->id)->where('target_id', $postId)->count());
        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
            'reaction_type_id' => $this->dislikeId,
        ]);
    }

    /**
     * 같은 유형을 재클릭하면 해제되어 이력 행이 삭제되고 카운트가 -1 된다.
     *
     * @scenario case=remove_same_type
     * @effects remove_deletes_row_and_decrements_count
     */
    public function test_react_same_type_removes_reaction_and_decrements_count(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->service->react($reactor->id, $this->board, $postId, $this->likeId);
        $result = $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->likeId);

        $this->assertSame('remove', $result['action']);
        $this->assertNull($result['reaction_type_id']);
        $this->assertSame(0, $result['reaction_counts'][(string) $this->likeId]);

        $this->assertDatabaseMissing('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
        ]);
    }

    /**
     * 카운트는 0 미만으로 내려가지 않는다 (해제 시 클램프).
     *
     * @scenario case=count_never_negative_on_over_remove
     * @effects reaction_count_never_negative
     */
    public function test_reaction_count_never_goes_below_zero(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id, 'reaction_counts' => json_encode([])]);

        $this->service->react($reactor->id, $this->board, $postId, $this->likeId);
        $result = $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->likeId);

        $this->assertGreaterThanOrEqual(0, $result['reaction_counts'][(string) $this->likeId]);
    }

    /**
     * use_reaction 이 꺼진 게시판은 반응이 차단된다.
     *
     * @scenario case=use_reaction_off_react_blocked
     * @effects use_reaction_off_react_returns_422
     */
    public function test_react_blocked_when_use_reaction_off(): void
    {
        $this->updateBoardSettings(['use_reaction' => false]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->expectException(ReactionNotAllowedException::class);
        $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->likeId);
    }

    /**
     * 게시판이 켜지 않은(비활성) 유형은 차단된다.
     *
     * @scenario case=inactive_type_react_blocked
     * @effects inactive_type_react_returns_422
     */
    public function test_react_blocked_for_inactive_type_on_board(): void
    {
        $this->updateBoardSettings(['active_reaction_types' => ['like']]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->expectException(ReactionNotAllowedException::class);
        $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->dislikeId);
    }

    /**
     * 본인 글에는 반응할 수 없다.
     *
     * @scenario case=self_post_react_blocked
     * @effects self_post_react_returns_422
     */
    public function test_react_blocked_on_own_post(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->expectException(ReactionNotAllowedException::class);
        $this->service->react($author->id, $this->board, $postId, $this->likeId);
    }

    /**
     * 다른 게시판 소속 게시글 ID 로는 반응할 수 없다 (스코프 검증).
     *
     * @scenario case=post_not_in_board
     * @effects post_not_in_board_returns_404
     */
    public function test_react_blocked_for_post_not_in_board(): void
    {
        $reactor = $this->createUser();

        $this->expectException(PostNotFoundException::class);
        $this->service->react($reactor->id, $this->board, 999999, $this->likeId);
    }
}
