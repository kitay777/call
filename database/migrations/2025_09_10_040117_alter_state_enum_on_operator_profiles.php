<?php

// database/migrations/xxxx_xx_xx_xxxxxx_alter_state_enum_on_operator_profiles.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ENUM を作り直す（MySQL）
        DB::statement("
            ALTER TABLE operator_profiles
            MODIFY COLUMN state ENUM('available','busy','break','off_today')
            NOT NULL DEFAULT 'off_today'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE operator_profiles
            MODIFY COLUMN state ENUM('available','busy','break','offline')
            NOT NULL DEFAULT 'offline'
        ");
    }
};

