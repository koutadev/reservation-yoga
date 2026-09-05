<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * インストラクターとログインユーザーの紐付け。
 *
 * 講師（staff ロール）が「自分の枠だけ」を編集できるようにするために、
 * どのユーザーがどの講師なのかを 1 対 1 で持たせる（設計書 8. 権限設計）。
 *
 * 講師本人がログインしない運用もあるため null 許容。
 * 1 人のユーザーが複数の講師を兼ねることはないので一意にしておく。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructors', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('profile')
                ->unique()
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('instructors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
