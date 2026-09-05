<?php

namespace App\Enums;

/**
 * 空き状況（会員に見せる 3 段階）。
 *
 * 予約数 ÷ 定員 から決まる。色だけに頼らず、ゲージの面積・数値と合わせた
 * 三重の符号化で伝える（DEC-014。色覚多様性への配慮）。
 *
 *   空きあり   = ミント（emerald）
 *   残りわずか = アンバー（amber）
 *   満席       = コーラル（rose）
 */
enum AvailabilityState: string
{
    /** 空きあり */
    case Open = 'open';

    /** 残りわずか（config: reservation.few_seats_ratio 以上まで埋まっている） */
    case Few = 'few';

    /** 満席 */
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Open => '空きあり',
            self::Few => '残りわずか',
            self::Full => '満席',
        };
    }

    /**
     * バッジの意味づけ（共通の Tone に寄せる）。
     */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Few => 'warning',
            self::Full => 'danger',
        };
    }

    /**
     * カレンダーのコマ：下から塗るゲージの色。
     */
    public function fillClass(): string
    {
        return match ($this) {
            self::Open => 'bg-emerald-400/30',
            self::Few => 'bg-amber-400/35',
            self::Full => 'bg-rose-400/30',
        };
    }

    /**
     * カレンダーのコマ：枠線の色。
     */
    public function borderClass(): string
    {
        return match ($this) {
            self::Open => 'border-emerald-200 dark:border-emerald-800',
            self::Few => 'border-amber-200 dark:border-amber-800',
            self::Full => 'border-rose-200 dark:border-rose-800',
        };
    }

    /**
     * カレンダーのコマ：残数などの補足文字の色。
     */
    public function metaClass(): string
    {
        return match ($this) {
            self::Open => 'text-emerald-800 dark:text-emerald-200',
            self::Few => 'text-amber-800 dark:text-amber-200',
            self::Full => 'text-rose-800 dark:text-rose-200',
        };
    }

    /**
     * 予約数と定員から状態を決める。
     */
    public static function fromCounts(int $reserved, int $capacity): self
    {
        if ($capacity <= 0 || $reserved >= $capacity) {
            return self::Full;
        }

        $threshold = (float) config('reservation.few_seats_ratio', 0.75);

        return $reserved / $capacity >= $threshold ? self::Few : self::Open;
    }
}
