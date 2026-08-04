<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 반응 API Feature 테스트.
 *
 * 등록/전환/해제, reaction_counts 증감 정합성(전환 포함), use_reaction off·비활성 유형
 * 차단, 본인 글 차단, 비로그인 차단을 종단 검증한다 (이슈 #525 §10 테스트 범위).
 */
class ReactionApiTest extends BoardTestCase
{
    private int $likeId;

    private int $dislikeId;

    protected function setUp(): void
    {
        parent::setUp();

        // FK(restrictOnDelete) 순서: 이력 먼저 정리 후 유형 삭제 (타 테스트 잔여 데이터 대비)
        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->updateBoardSettings([
            'use_reaction' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);
    }

    /**
     * 반응 API 엔드포인트 URL 을 조립합니다.
     */
    private function reactUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/react";
    }

    /**
     * 반응 등록 → 카운트 +1, my_reaction_type_id 반영.
     */
    public function test_register_reaction(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $response = $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'add')
            ->assertJsonPath('data.my_reaction_type_id', $this->likeId)
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 1);

        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
            'reaction_type_id' => $this->likeId,
        ]);
    }

    /**
     * 다른 유형으로 전환 → 이전 -1·신규 +1 (증감 정합성).
     */
    public function test_switch_reaction_adjusts_counts(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertOk();

        $response = $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->dislikeId]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'change')
            ->assertJsonPath('data.my_reaction_type_id', $this->dislikeId)
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 0)
            ->assertJsonPath("data.reaction_counts.{$this->dislikeId}", 1);
    }

    /**
     * 같은 유형 재클릭 → 해제, 카운트 -1, my_reaction_type_id null.
     */
    public function test_remove_reaction_on_same_type(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertOk();

        $response = $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'remove')
            ->assertJsonPath('data.my_reaction_type_id', null)
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 0);

        $this->assertDatabaseMissing('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
        ]);
    }

    /**
     * 비로그인 요청은 401 로 차단된다 (확정 07).
     */
    public function test_guest_cannot_react(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertUnauthorized();
    }

    /**
     * use_reaction 이 꺼진 게시판은 422 로 차단된다.
     */
    public function test_react_blocked_when_use_reaction_off(): void
    {
        $this->updateBoardSettings(['use_reaction' => false]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);
    }

    /**
     * 게시판이 켜지 않은(비활성) 유형은 검증 단계(422)에서 차단된다.
     */
    public function test_react_blocked_for_inactive_type(): void
    {
        $this->updateBoardSettings(['active_reaction_types' => ['like']]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->dislikeId])
            ->assertStatus(422);
    }

    /**
     * 본인 글에는 반응할 수 없다 (422, 확정 08).
     */
    public function test_cannot_react_to_own_post(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($author, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);
    }

    /**
     * 다른 게시판 소속이 아닌(존재하지 않는) 게시글은 404 로 차단된다.
     */
    public function test_react_to_missing_post_returns_404(): void
    {
        $reactor = $this->createUser();

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl(999999), ['reaction_type_id' => $this->likeId])
            ->assertNotFound();
    }
}
