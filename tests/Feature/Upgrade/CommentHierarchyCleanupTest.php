<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_0_3;

/**
 * 1.0.3 댓글 계층 오염 데이터 정리 업그레이드 스텝 테스트
 *
 * 검증 목적:
 * - 교차 게시글 부모를 가진 고아 댓글이 최상위로 승격된다 (본문 보존)
 * - depth 가 루트부터 부모 depth + 1 로 재계산된다
 * - 재실행해도 결과가 동일하다 (멱등)
 *
 * @group board
 * @group upgrade
 */
class CommentHierarchyCleanupTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'comment-hierarchy-cleanup';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 계층 정리 게시판', 'en' => 'Comment Hierarchy Cleanup Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 10,
        ];
    }

    /**
     * 교차 게시글 부모 댓글이 최상위로 승격되고 본문은 보존된다
     *
     * @scenario entity=board_comment, parent_target=foreign
     *
     * @effects existing_cross_post_parent_detached_content_preserved
     */
    public function test_cross_post_parent_is_detached_and_content_preserved(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        $parentInA = $this->createTestComment($postA, ['depth' => 0]);
        $orphan = $this->createTestComment($postB, [
            'parent_id' => $parentInA,
            'depth' => 1,
            'content' => '보존되어야 하는 본문',
        ]);

        $this->runCleanup();

        $row = DB::table('board_comments')->where('id', $orphan)->first();

        $this->assertNull($row->parent_id, '교차 게시글 부모는 해제되어야 합니다.');
        $this->assertSame(0, (int) $row->depth, '승격된 댓글의 depth 는 0 이어야 합니다.');
        $this->assertSame('보존되어야 하는 본문', $row->content, '본문은 보존되어야 합니다.');
        $this->assertSame($postB, (int) $row->post_id, 'post_id 는 바뀌지 않아야 합니다.');
    }

    /**
     * 클램프로 뭉개진 depth 가 부모 depth + 1 로 재계산된다
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects existing_clamped_depth_recalculated
     */
    public function test_clamped_depth_is_recalculated_from_root(): void
    {
        $postId = $this->createTestPost();

        // 8단계 체인을 만들되, 6단계 이후 depth 를 5 로 뭉개 둔다 (구 클램프 재현)
        $parentId = null;
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $storedDepth = min($i, 5);
            $parentId = $this->createTestComment($postId, [
                'parent_id' => $parentId,
                'depth' => $storedDepth,
            ]);
            $ids[$i] = $parentId;
        }

        $this->runCleanup();

        foreach ($ids as $expectedDepth => $id) {
            $this->assertSame(
                $expectedDepth,
                (int) DB::table('board_comments')->where('id', $id)->value('depth'),
                "{$expectedDepth} 단계 댓글의 depth 가 재계산되어야 합니다."
            );
        }
    }

    /**
     * 재실행해도 결과가 동일하다 (멱등)
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects cleanup_is_idempotent
     */
    public function test_cleanup_is_idempotent(): void
    {
        $postA = $this->createTestPost(['title' => '게시글 A']);
        $postB = $this->createTestPost(['title' => '게시글 B']);

        $parentInA = $this->createTestComment($postA, ['depth' => 0]);
        $this->createTestComment($postB, ['parent_id' => $parentInA, 'depth' => 1]);

        $chainRoot = $this->createTestComment($postA, ['parent_id' => $parentInA, 'depth' => 5]);
        $this->createTestComment($postA, ['parent_id' => $chainRoot, 'depth' => 5]);

        $this->runCleanup();
        $first = $this->snapshot();

        $this->runCleanup();
        $second = $this->snapshot();

        $this->assertSame($first, $second, '재실행 결과가 동일해야 합니다.');
    }

    /**
     * 1.0.3 정리 마이그레이션을 실행합니다.
     */
    private function runCleanup(): void
    {
        $context = new UpgradeContext('1.0.2', '1.0.3', '1.0.3', 'extension-upgrade');

        $step = new Upgrade_1_0_3;
        $step->run($context);
    }

    /**
     * 이 게시판 댓글의 (id, parent_id, depth) 스냅샷을 반환합니다.
     *
     * @return array<int, array{id: int, parent_id: int|null, depth: int}> 스냅샷 배열
     */
    private function snapshot(): array
    {
        return DB::table('board_comments')
            ->where('board_id', $this->board->id)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'depth'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'depth' => (int) $row->depth,
            ])
            ->all();
    }
}
