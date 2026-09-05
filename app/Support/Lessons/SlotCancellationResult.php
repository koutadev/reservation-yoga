<?php

namespace App\Support\Lessons;

/**
 * 枠の中止で連鎖してキャンセルした件数。
 */
final class SlotCancellationResult
{
    public function __construct(
        public readonly int $reservations,
        public readonly int $waitlists,
    ) {}

    /**
     * 連鎖してキャンセルしたものがあったか。
     */
    public function isEmpty(): bool
    {
        return $this->reservations === 0 && $this->waitlists === 0;
    }

    /**
     * 画面に出す説明（例: 「予約 3 件・キャンセル待ち 2 件も取り消しました。」）。
     */
    public function message(): string
    {
        return sprintf(
            '予約 %d 件・キャンセル待ち %d 件も取り消しました。',
            $this->reservations,
            $this->waitlists,
        );
    }
}
