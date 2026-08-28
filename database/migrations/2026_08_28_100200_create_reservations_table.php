<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint as Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 予約。
 *
 * 二重予約の防止は「アプリ側のチェック」だけに頼らず、DB の部分ユニークインデックスでも守る。
 *   - 同じ枠 × 同じ会員で、席を占めている予約（予約中 / 繰上確定）は 1 件だけ
 *   - キャンセル済みは対象外なので、キャンセル後に取り直せる
 * （定員超過の防止は STEP4 で、枠の行ロック + 件数確認 + INSERT で行う）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Table $table): void {
            $table->id();
            $table->string('code', 32)->unique();      // RSV-2026-0001
            $table->foreignId('lesson_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->index();     // reserved / canceled / promoted
            $table->dateTime('reserved_at');
            $table->dateTime('canceled_at')->nullable();

            $table->masterColumns();

            $table->index(['lesson_slot_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // 同じ枠を同じ会員が二重に取れないようにする（キャンセル済みと論理削除は除く）
        DB::statement(<<<'SQL'
            create unique index reservations_active_unique
                on reservations (lesson_slot_id, user_id)
                where status <> 'canceled' and deleted_at is null
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
