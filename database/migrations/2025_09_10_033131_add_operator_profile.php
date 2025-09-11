<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 休業（本日休業）を追加
        Schema::table('operator_profiles', function (Blueprint $table) {
            $table->enum('state', ['available', 'busy', 'break', 'off_today'])->default('off_today')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operator_profiles', function (Blueprint $table) {
            //
            $table->enum('state', ['available', 'busy', 'break', 'offline'])->default('offline')->change();
        });
    }
};
