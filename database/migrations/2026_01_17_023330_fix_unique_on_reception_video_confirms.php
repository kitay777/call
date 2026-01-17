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
        //
Schema::table('reception_video_confirms', function (Blueprint $table) {
    $table->unique(
        ['reception_id', 'video_id', 'video_type'],
        'reception_video_confirms_reception_id_video_id_video_type_unique'
    );
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //

    }
};
