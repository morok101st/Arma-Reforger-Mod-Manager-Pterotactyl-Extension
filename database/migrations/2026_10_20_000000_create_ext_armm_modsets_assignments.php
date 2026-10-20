<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ext_armm_modsets_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('modset_id');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('last_count')->nullable();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('ext_armm_modsets_assignments'); }
};
