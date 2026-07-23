<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

/**
 * 게시판 제한값(config('sirsoft-board.limits')) 조회 트레이트
 *
 * 게시판 관련 FormRequest 들이 각자 `$limits['x'] ?? 기본값` 블록을 복제하면서
 * 파일마다 폴백 값이 어긋나는 드리프트가 발생했습니다. 폴백 기본값을 이 트레이트
 * 한 곳에 모아 config 가 제한값의 단일 출처(SSoT)가 되도록 합니다.
 */
trait ReadsBoardLimits
{
    /**
     * config 미정의 시 사용할 제한값 기본치
     *
     * config/board.php 의 limits 섹션과 동일한 값을 유지해야 합니다.
     */
    private const BOARD_LIMIT_DEFAULTS = [
        'per_page_min' => 5,
        'per_page_max' => 100,

        'min_title_length_min' => 0,
        'min_title_length_max' => 200,
        'max_title_length_min' => 1,
        'max_title_length_max' => 1000,

        'min_content_length_min' => 0,
        'min_content_length_max' => 10000,
        'max_content_length_min' => 1,
        'max_content_length_max' => 100000,

        'min_comment_length_min' => 0,
        'min_comment_length_max' => 1000,
        'max_comment_length_min' => 1,
        'max_comment_length_max' => 10000,

        'max_file_size_min' => 1,
        'max_file_size_max' => 200,
        'max_file_count_min' => 1,
        'max_file_count_max' => 20,

        'category_max' => 50,

        'max_reply_depth_min' => 1,
        'max_reply_depth_max' => 10,
        'max_comment_depth_min' => 0,
        'max_comment_depth_max' => 10,

        'new_display_hours_min' => 0,
        'new_display_hours_max' => 720,
    ];

    /**
     * 게시판 제한값 전체를 반환합니다.
     *
     * config 값이 기본치를 덮어씁니다.
     *
     * @return array<string, int> 제한값 맵
     */
    protected function boardLimits(): array
    {
        $configured = config('sirsoft-board.limits', []);

        return array_merge(
            self::BOARD_LIMIT_DEFAULTS,
            is_array($configured) ? $configured : []
        );
    }

    /**
     * 특정 제한값을 반환합니다.
     *
     * @param  string  $key  제한값 키 (예: per_page_min)
     * @return int 제한값
     */
    protected function boardLimit(string $key): int
    {
        return (int) ($this->boardLimits()[$key] ?? 0);
    }
}
