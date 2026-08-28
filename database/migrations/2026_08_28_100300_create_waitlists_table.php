<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * キャンセル待ち。
 *
 * 満席の枠に並んでおき、空きが出たら position の小さい順に 1 件だけ繰り上げる（STEP5）。
 * 予約と同じく、同じ枠 × 同じ会員で待機中が重複しないよう DB 側でも守る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');       // 待ち順（1 が先頭）
            $table->string('status', 16)->index();     // waiting / promoted / canceled
            $table->dateTime('requested_at');

            $table->masterColumns();

            // 「この枠の待機中を待ち順に」引くための索引
            $table->index(['lesson_slot_id', 'status', 'position']);
            $table->index(['user_id', 'status']);
        });

        // 同じ枠で同じ会員が二重に並べないようにする（取消済みと論理削除は除く）
        DB::statement(<<<'SQL'
            create unique index waitlists_active_unique
                on waitlists (lesson_slot_id, user_id)
                where status <> 'canceled' and deleted_at is null
        SQL);

        // 待ち順も枠の中で重複させない（繰上済み・取消済みは対象外）
        DB::statement(<<<'SQL'
            create unique index waitlists_position_unique
                on waitlists (lesson_slot_id, position)
                where status = 'waiting' and deleted_at is null
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlists');
    }
};
