<?php

namespace App\Models\Concerns;

use App\Support\Code\CodeGenerator;

/**
 * 年ごとに連番をリセットする業務コード（LSN-2026-0001 / RSV-2026-0001 …）。
 *
 * 共通基盤の {@see HasSequentialCode} はテーブル単位の通し番号だが、
 * 予約やレッスン枠は「年で区切って番号を見る」ほうが業務上わかりやすいため、
 * 採番系列のキーに年を混ぜたものを用意する。
 *
 * 採番そのもの（行ロックで重複しない）は共通基盤の CodeGenerator をそのまま使う。
 *
 *   class Reservation extends BaseModel
 *   {
 *       use HasYearlySequentialCode;
 *
 *       public static function codePrefix(): string { return 'RSV'; }
 *   }
 *
 * code に値が入っている状態で保存した場合は採番しない（取り込み対応）。
 *
 * @property string $code
 */
trait HasYearlySequentialCode
{
    /** 連番のゼロ埋め桁数 */
    protected static int $codePadding = 4;

    /**
     * コードのプレフィックス（例: RSV）。
     */
    abstract public static function codePrefix(): string;

    public static function bootHasYearlySequentialCode(): void
    {
        static::creating(function (self $model): void {
            if (filled($model->getAttribute('code'))) {
                return;
            }

            $model->setAttribute('code', static::generateCode($model->codeYear()));
        });
    }

    /**
     * 採番に使う年。
     *
     * 既定は「いまの年」。レッスン枠のように"開催日の年"で採番したい場合は
     * モデル側でこのメソッドを差し替える。
     */
    public function codeYear(): int
    {
        return (int) now()->year;
    }

    /**
     * 次のコードを採番する。
     */
    public static function generateCode(?int $year = null): string
    {
        $year ??= (int) now()->year;

        return app(CodeGenerator::class)->next(
            key: static::codeSequenceKeyFor($year),
            prefix: static::codePrefix().'-'.$year,
            padding: static::$codePadding,
        );
    }

    /**
     * 採番系列のキー（テーブル名 + 年）。
     */
    public static function codeSequenceKeyFor(int $year): string
    {
        return (new static)->getTable().':'.$year;
    }
}
