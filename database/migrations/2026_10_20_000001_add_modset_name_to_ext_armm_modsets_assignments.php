<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ext_armm_modsets_assignments', function (Blueprint $table) {
            $table->string('modset_name', 255)->nullable()->after('modset_id');
        });
    }

    public function down(): void
    {
        Schema::table('ext_armm_modsets_assignments', function (Blueprint $table) {
            $table->dropColumn('modset_name');
        });
    }
};
