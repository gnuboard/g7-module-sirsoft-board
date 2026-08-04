<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Modules\Sirsoft\Board\Models\Reaction;

/**
 * 반응 이력 Repository 인터페이스
 */
interface ReactionRepositoryInterface
{
    /**
     * 사용자+대상 기준 기존 반응을 조회합니다 (유일 제약과 동일 키).
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $targetType  대상 타입 (예: post)
     * @param  int  $targetId  대상 ID
     * @return Reaction|null 기존 반응 또는 null
     */
    public function findByUserAndTarget(int $userId, string $targetType, int $targetId): ?Reaction;

    /**
     * 반응을 등록하거나 전환합니다 (없으면 INSERT, 있으면 유형 UPDATE).
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $targetType  대상 타입
     * @param  int  $targetId  대상 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @param  int|null  $boardId  게시판 ID
     * @return Reaction 등록/전환된 반응 모델
     */
    public function upsert(int $userId, string $targetType, int $targetId, int $reactionTypeId, ?int $boardId): Reaction;

    /**
     * 반응을 해제합니다 (이력 행 삭제).
     *
     * @param  Reaction  $reaction  삭제할 반응 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Reaction $reaction): bool;

    /**
     * 게시글의 반응 카운트(JSON)를 유형별 증감으로 원자 갱신합니다.
     *
     * 반드시 트랜잭션 안에서 호출되어야 하며, 대상 행을 `lockForUpdate` 로 잠가
     * 동시 반응에도 카운트 누락이 없도록 read-modify-write 를 원자 처리합니다.
     * 결과 카운트는 0 미만으로 내려가지 않도록 클램프합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array<int, int>  $deltas  유형 ID => 증감값 (예: [1 => -1, 2 => 1])
     * @return array<string, int> 갱신 후 reaction_counts (키는 유형 ID 문자열)
     */
    public function adjustPostReactionCounts(int $postId, array $deltas): array;
}
