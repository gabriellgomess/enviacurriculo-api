<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            $table->boolean('modulo_multiusuario')->default(false)->after('menus_permitidos');
            $table->foreignId('titular_user_id')->nullable()->after('modulo_multiusuario')
                ->constrained('users')->nullOnDelete();

            $table->index('modulo_multiusuario');
        });
    }

    public function down(): void
    {
        Schema::table('franquias', function (Blueprint $table) {
            $table->dropForeign(['titular_user_id']);
            $table->dropIndex(['modulo_multiusuario']);
            $table->dropColumn(['modulo_multiusuario', 'titular_user_id']);
        });
    }
};
