<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
public function up(): void {
Schema::create('operator_profiles', function (Blueprint $table) {
$table->id();
$table->foreignId('user_id')->unique()->constrained();
$table->enum('state', ['available','busy','break','offline'])->default('offline');
$table->timestamps();
});
}

public function down(): void {
Schema::dropIfExists('operator_profiles');
}
};
