<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リマインド（送信予定・送信済みの管理）。
 *
 * 実送信のインフラは拡張点。ここでは「いつ・どの予約に・どの手段で送る予定か」と
 * 「送ったか」を持たせ、後から送信処理を足せる形にしておく。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_at');          // 送信予定
            $table->dateTime('sent_at')->nullable();   // 送信済み
            $table->string('channel', 16);             // email など

            $table->masterColumns();

            // 「まだ送っていない・送信予定が来たもの」を引くための索引
            $table->index(['scheduled_at', 'sent_at']);
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
