<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 대댓글 깊이 제한 테스트
 *
 * 검증 목적:
 * - 저장되는 depth 가 항상 부모 depth + 1 (하드코딩 상한 없음)
 * - 게시판 설정 max_comment_depth 를 넘는 답글은 422
 * - max 를 낮게(3) / 높게(10) 설정한 두 경우 모두 설정값을 따른다
 *
 * @group board
 * @group comment
 * @group depth
 */
class CommentDepthLimitTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-depth-limit';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 깊이 제한 게시판', 'en' => 'Comment Depth Limit Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 10,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        $this->memberUser = User::factory()->create(['email' => 'comment-depth@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * max_comment_depth=10 게시판에서 depth 는 1..10 까지 정확히 증가한다
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_equals_parent_depth_plus_one, depth_follows_board_setting_not_literal
     */
    public function test_depth_increments_up_to_configured_max(): void
    {
        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 0]);

        for ($expectedDepth = 1; $expectedDepth <= 10; $expectedDepth++) {
            $response = $this->actingAs($this->memberUser)
                ->postJson($this->storeUrl($postId), [
                    'content' => "{$expectedDepth}단계 답글",
                    'parent_id' => $parentId,
                ]);

            $response->assertStatus(201);

            $parentId = (int) $response->json('data.id');

            $this->assertDatabaseHas('board_comments', [
                'id' => $parentId,
                'depth' => $expectedDepth,
            ]);
        }
    }

    /**
     * max_comment_depth=10 게시판에서 11단계 답글은 422
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_beyond_board_max_rejected_422
     */
    public function test_reply_beyond_configured_max_is_rejected(): void
    {
        $postId = $this->createTestPost();

        // depth 10 인 부모 댓글을 직접 생성 → 11단계 시도
        $parentId = $this->createTestComment($postId, ['depth' => 10]);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '11단계 답글 시도',
                'parent_id' => $parentId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    /**
     * max_comment_depth=3 게시판에서 4단계 답글은 422
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_beyond_board_max_rejected_422, depth_follows_board_setting_not_literal
     */
    public function test_reply_beyond_lowered_max_is_rejected(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 3]);

        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 3]);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '4단계 답글 시도',
                'parent_id' => $parentId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    /**
     * max_comment_depth=3 게시판에서 3단계까지는 정상 생성된다
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_equals_parent_depth_plus_one, depth_follows_board_setting_not_literal
     */
    public function test_reply_within_lowered_max_is_allowed(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 3]);

        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 2]);

        $response = $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '3단계 답글',
                'parent_id' => $parentId,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('board_comments', [
            'id' => (int) $response->json('data.id'),
            'depth' => 3,
        ]);
    }

    /**
     * 댓글 생성 엔드포인트 URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string 댓글 생성 URL
     */
    private function storeUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";
    }
}
