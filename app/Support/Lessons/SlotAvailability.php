<?php

namespace App\Support\Lessons;

use App\Enums\AvailabilityState;
use App\Enums\LessonType;
use App\Models\LessonSlot;

/**
 * 枠 1 件の空き状況（会員向けの見せ方をここに集約する）。
 *
 * 残枠は枠に保持していないので「定員 − 席を占める予約数」で都度求める。
 * 一覧では withCount('activeReservations as reserved_count') で先に数えてあるため、
 * ここで枠ごとに問い合わせが増えることはない（N+1 を作らない）。
 *
 * 表示は、色・面積（ゲージの高さ）・数値の 3 つで同じことを伝える（DEC-014）。
 */
class SlotAvailability
{
    private function __construct(
        public readonly int $capacity,
        public readonly int $reserved,
        public readonly int $remaining,
        public readonly AvailabilityState $state,
        private readonly LessonType $lessonType,
    ) {}

    public static function of(LessonSlot $slot): self
    {
        $capacity = $slot->capacity;
        $reserved = $slot->reservedCount();

        return new self(
            capacity: $capacity,
            reserved: $reserved,
            remaining: max(0, $capacity - $reserved),
            state: AvailabilityState::fromCounts($reserved, $capacity),
            lessonType: $slot->lesson_type,
        );
    }

    public function isFull(): bool
    {
        return $this->state === AvailabilityState::Full;
    }

    /**
     * ゲージの高さ（予約数 ÷ 定員、0〜100）。
     */
    public function fillPercent(): int
    {
        if ($this->capacity <= 0) {
            return 100;
        }

        return (int) min(100, round($this->reserved / $this->capacity * 100));
    }

    /**
     * 一覧のカードに出すバッジ。
     *
     * マンツーマンは「残り 1」と出しても意味がないので、空いていれば「個人」と出す
     * （モックアップの会員スマホ画面に合わせる）。
     */
    public function badgeLabel(): string
    {
        if ($this->isFull()) {
            return '満席';
        }

        if ($this->lessonType === LessonType::Personal) {
            return '個人';
        }

        return '残り'.$this->remaining;
    }

    public function badgeTone(): string
    {
        if (! $this->isFull() && $this->lessonType === LessonType::Personal) {
            return 'primary';
        }

        return $this->state->tone();
    }

    /**
     * カレンダーのコマに出す数値（埋まり具合そのもの）。
     */
    public function gaugeLabel(): string
    {
        return $this->isFull() ? '満席' : $this->reserved.'/'.$this->capacity;
    }

    /**
     * 読み上げ・ツールチップ用の説明。
     */
    public function description(): string
    {
        return $this->isFull()
            ? '満席（定員 '.$this->capacity.' 名）'
            : '残り '.$this->remaining.' 名（定員 '.$this->capacity.' 名）';
    }
}
