<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franquia_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franquia_id')->constrained('franquias')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('tipo', ['titular', 'assistente'])->default('assistente');
            $table->string('cargo')->nullable();
            $table->boolean('ativo')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['franquia_id', 'user_id']);
            $table->index(['franquia_id', 'tipo']);
            $table->index(['franquia_id', 'ativo']);
        });

        // Backfill: vincular os usuários de franquia existentes como titulares
        try {
            $contexts = DB::table('user_contexts')
                ->where('role', 'franquia')
                ->get();

            foreach ($contexts as $ctx) {
                // Atualiza franquias.titular_user_id se ainda não estiver definido
                DB::table('franquias')
                    ->where('id', $ctx->context_id)
                    ->whereNull('titular_user_id')
                    ->update(['titular_user_id' => $ctx->user_id]);

                // Insere em franquia_usuarios como titular
                $exists = DB::table('franquia_usuarios')
                    ->where('franquia_id', $ctx->context_id)
                    ->where('user_id', $ctx->user_id)
                    ->exists();

                if (!$exists) {
                    $user = DB::table('users')->where('id', $ctx->user_id)->first();
                    DB::table('franquia_usuarios')->insert([
                        'franquia_id' => $ctx->context_id,
                        'user_id'     => $ctx->user_id,
                        'tipo'        => 'titular',
                        'cargo'       => 'Franqueado Titular',
                        'ativo'       => $user ? (bool) $user->active : true,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Se falhar o backfill, a migration da tabela já foi concluída
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('franquia_usuarios');
    }
};
