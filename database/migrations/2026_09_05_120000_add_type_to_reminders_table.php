<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リマインドの種別。
 *
 * 前日のリマインドと、キャンセル待ちの繰り上げの連絡は、送る理由も送る時期も違う。
 * 同じ reminders で扱いつつ、種別で見分けられるようにする（STEP6）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->string('type', 24)
                ->default('lesson')      // 既存行は前日リマインドとして扱う
                ->after('reservation_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
