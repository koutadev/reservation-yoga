<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * インストラクター（マスタ）。
 *
 * 会員は users（role=member）を使うが、講師は担当・プロフィールを持つ
 * 独立したマスタとして扱う（設計書 5. データ設計）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructors', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();      // INS-0001
            $table->string('name', 100);
            $table->text('profile')->nullable();

            $table->masterColumns();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructors');
    }
};
