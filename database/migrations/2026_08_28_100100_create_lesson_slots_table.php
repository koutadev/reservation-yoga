<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * レッスン枠。
 *
 * 「講師 × 日時 × レッスン名 × 定員 × 形態」を 1 枠として開講する（個別枠方式）。
 * 残枠は「定員 − 席を占める予約数」で常にサーバ側が判定するため、
 * 枠側に予約数を持たせない（二重管理にしない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_slots', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();      // LSN-2026-0001
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->string('title', 191);
            $table->string('lesson_type', 16)->index();   // group / personal
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity');     // 定員（personal は 1）
            $table->string('online_url', 255)->nullable();
            $table->string('status', 16)->index();        // open / closed / canceled

            $table->masterColumns();

            // 一覧は「これからの枠を日時順」で引くことが多い
            $table->index(['starts_at', 'status']);
            $table->index(['instructor_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_slots');
    }
};
