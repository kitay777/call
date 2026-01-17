<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_video_confirms', function (Blueprint $table) {
            $table->id();

            // 紐づく受付
            $table->foreignId('reception_id')
                ->constrained()
                ->cascadeOnDelete();

            // 動画
            $table->foreignId('video_id')
                ->constrained()
                ->cascadeOnDelete();

            // required / optional
            $table->string('video_type', 20);

            // 確認時刻
            $table->timestamp('confirmed_at');

            $table->timestamps();

            // 同じ受付で同じ動画は1回だけ
            $table->unique(['reception_id', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_video_confirms');
    }
};
