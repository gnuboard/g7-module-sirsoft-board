<?php

namespace Modules\Sirsoft\Board\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Exceptions\ReactionNotAllowedException;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;

/**
 * 반응 서비스
 *
 * 게시글 반응의 등록/전환/해제를 단일 진입점(`react()`)으로 처리합니다.
 * 확정 03(글당 1개, 전환 시 대체), 07(로그인 필수 — 컨트롤러 미들웨어),
 * 08(본인 글 차단), 11(비활성 유형 차단)을 강제합니다.
 */
class ReactionService
{
    /**
     * 현재 지원하는 반응 대상 타입 (확정 10 — 게시글만, 향후 comment 확장 가능).
     */
    private const TARGET_POST = 'post';

    /**
     * @param  ReactionRepositoryInterface  $reactionRepository  반응 이력 Repository
     * @param  ReactionTypeRepositoryInterface  $reactionTypeRepository  반응 유형 Repository
     * @param  PostRepositoryInterface  $postRepository  게시글 스코프 조회 Repository
     */
    public function __construct(
        private readonly ReactionRepositoryInterface $reactionRepository,
        private readonly ReactionTypeRepositoryInterface $reactionTypeRepository,
        private readonly PostRepositoryInterface $postRepository,
    ) {}

    /**
     * 게시글에 반응을 남깁니다 (등록/전환/해제 통합).
     *
     * 게시글이 대상 게시판 소속인지 스코프 검증을 먼저 수행합니다 (교차 접근 차단).
     *
     * - 기존 반응 없음 → 신규(INSERT), 해당 유형 +1
     * - 기존 반응이 같은 유형 → 해제(DELETE), 해당 유형 -1
     * - 기존 반응이 다른 유형 → 전환(UPDATE), 이전 유형 -1 · 신규 유형 +1
     *
     * 카운트 증감과 이력 쓰기는 단일 트랜잭션으로 원자 처리합니다 (확정 04·동시성).
     *
     * @param  int  $userId  반응한 사용자 ID
     * @param  Board  $board  대상 게시판 (컨트롤러가 slug 로 해석 후 전달)
     * @param  int  $postId  대상 게시글 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return array{action: string, reaction_type_id: int|null, reaction_counts: array<string, int>}
     *
     * @throws PostNotFoundException 게시글이 이 게시판 소속이 아니거나 미존재
     * @throws ReactionNotAllowedException 반응 비활성 게시판 / 비활성 유형 / 본인 글
     */
    public function react(int $userId, Board $board, int $postId, int $reactionTypeId): array
    {
        // 게시글이 이 게시판 소속인지 스코프 검증 (교차 접근 차단)
        $post = $this->postRepository->findByBoardId($board->id, $postId);

        if ($post === null) {
            throw new PostNotFoundException($postId);
        }

        // 반응 기능 off (확정 07 전제)
        if (! $board->use_reaction) {
            throw ReactionNotAllowedException::disabled();
        }

        // 본인 글 반응 차단 (확정 08)
        if ((int) $post->user_id === $userId) {
            throw ReactionNotAllowedException::selfPost();
        }

        // 요청 유형이 존재하는 활성 유형이면서 게시판이 켠 유형인지 확인 (확정 11)
        $requestedType = $this->reactionTypeRepository->findById($reactionTypeId);
        $activeCodes = $board->active_reaction_types ?? [];

        if ($requestedType === null
            || ! $requestedType->is_active
            || ! in_array($requestedType->code, $activeCodes, true)) {
            throw ReactionNotAllowedException::inactiveType();
        }

        HookManager::doAction('sirsoft-board.reaction.before_react', $userId, $post, $reactionTypeId);

        $result = DB::transaction(function () use ($userId, $board, $post, $reactionTypeId) {
            $existing = $this->reactionRepository->findByUserAndTarget(
                $userId,
                self::TARGET_POST,
                $post->id,
            );

            // 같은 유형 재요청 → 해제
            if ($existing !== null && (int) $existing->reaction_type_id === $reactionTypeId) {
                $this->reactionRepository->delete($existing);
                $counts = $this->reactionRepository->adjustPostReactionCounts(
                    $post->id,
                    [$reactionTypeId => -1],
                );

                return ['action' => 'remove', 'reaction_type_id' => null, 'reaction_counts' => $counts];
            }

            // 다른 유형 → 전환 (이전 -1, 신규 +1)
            if ($existing !== null) {
                $previousTypeId = (int) $existing->reaction_type_id;
                $this->reactionRepository->upsert(
                    $userId,
                    self::TARGET_POST,
                    $post->id,
                    $reactionTypeId,
                    $board->id,
                );
                $counts = $this->reactionRepository->adjustPostReactionCounts(
                    $post->id,
                    [$previousTypeId => -1, $reactionTypeId => 1],
                );

                return ['action' => 'change', 'reaction_type_id' => $reactionTypeId, 'reaction_counts' => $counts];
            }

            // 신규 등록
            $this->reactionRepository->upsert(
                $userId,
                self::TARGET_POST,
                $post->id,
                $reactionTypeId,
                $board->id,
            );
            $counts = $this->reactionRepository->adjustPostReactionCounts(
                $post->id,
                [$reactionTypeId => 1],
            );

            return ['action' => 'add', 'reaction_type_id' => $reactionTypeId, 'reaction_counts' => $counts];
        });

        HookManager::doAction(
            'sirsoft-board.reaction.after_react',
            $userId,
            $post,
            $reactionTypeId,
            $result['action'],
        );

        return $result;
    }
}
