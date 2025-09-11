<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
public function up(): void {
Schema::create('receptions', function (Blueprint $table) {
$table->id();
$table->string('token')->unique(); // 受付用URLに使う
$table->foreignId('operator_id')->nullable()->constrained('users');
$table->enum('status', [
'started','waiting','in_progress','verify','apply','important','sign','done','canceled'
])->default('started');
$table->unsignedInteger('queue_position')->nullable();
$table->json('meta')->nullable(); // 任意データ
$table->timestamps();
});
}

public function down(): void {
Schema::dropIfExists('receptions');
}
};
