<?php

namespace App\Support\Lessons;

use App\Models\LessonSlot;

/**
 * 繰り返し開講の結果（作成できた枠と、すでにあって飛ばした件数）。
 */
final class RecurringSlotsResult
{
    /**
     * @param  list<LessonSlot>  $created
     */
    public function __construct(
        public readonly array $created,
        public readonly int $skipped,
    ) {}

    public function createdCount(): int
    {
        return count($this->created);
    }

    /**
     * 画面に出す結果（例: 「26 件の枠を開講しました（既存と重複した 2 件はスキップ）。」）。
     */
    public function message(): string
    {
        $message = sprintf('%d 件の枠を開講しました。', $this->createdCount());

        if ($this->skipped > 0) {
            $message .= sprintf('（同じ講師・同じ日時の枠がすでにあった %d 件はスキップしました）', $this->skipped);
        }

        return $message;
    }
}
