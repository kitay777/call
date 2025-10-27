<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_code_to_receptions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('receptions', function (Blueprint $table) {
            $table->string('code', 6)->nullable()->unique()->after('token');
        });
    }
    public function down(): void {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};

