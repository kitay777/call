<?php

// database/migrations/xxxx_xx_xx_create_video_view_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_view_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reception_id');
            $table->unsignedBigInteger('video_id');
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index(['reception_id']);
            $table->index(['video_id']);

            // 重複防止（同じ受付で同じ動画は1回だけ）
            $table->unique(['reception_id', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_view_logs');
    }
};

