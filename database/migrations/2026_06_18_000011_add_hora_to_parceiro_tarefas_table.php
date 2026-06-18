<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parceiro_tarefas', function (Blueprint $table) {
            $table->string('hora', 5)->nullable()->after('data_tarefa');
        });
    }

    public function down(): void
    {
        Schema::table('parceiro_tarefas', function (Blueprint $table) {
            $table->dropColumn('hora');
        });
    }
};
