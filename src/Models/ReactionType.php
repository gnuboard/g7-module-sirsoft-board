<?php

namespace Modules\Sirsoft\Board\Models;

use App\Casts\AsUnicodeJson;
use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Model;

/**
 * 게시판 반응 유형 모델.
 *
 * 반응(추천/비추천 등) 유형을 DB로 관리한다. name은 다국어 JSON,
 * 조회 시 getLocalizedName()이 로케일 폴백을 처리한다 (이슈 #525 확정 15).
 *
 * @property int $id
 * @property string $code
 * @property array $name
 * @property string|null $icon
 * @property int $display_order
 * @property bool $is_active
 * @property array|null $user_overrides
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ReactionType extends Model
{
    use HasUserOverrides;

    protected $table = 'board_reaction_types';

    protected $fillable = [
        'code',
        'name',
        'icon',
        'display_order',
        'is_active',
        'user_overrides',
    ];

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'icon'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    protected function casts(): array
    {
        return [
            'name' => AsUnicodeJson::class,
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'user_overrides' => 'array',
        ];
    }

    /**
     * 지정된 로케일의 반응 유형명 반환 (미입력 언어는 폴백).
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 해당 로케일의 유형명
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->name)) {
            return (string) $this->name;
        }

        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (! empty($this->name) ? array_values($this->name)[0] : '')
            ?? '';
    }

    /**
     * 활성 유형만 조회하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
