<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * Contadores públicos exibidos na Home.
     * Ajuste os nomes das tabelas conforme forem criadas.
     */
    public function index()
    {
        $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()");
        $tableNames = array_map(fn($t) => $t->table_name ?? $t->TABLE_NAME, $tables);

        $empresas   = in_array('empresas',  $tableNames) ? DB::table('empresas')->count()  : 0;
        $franquias  = in_array('franquias', $tableNames) ? DB::table('franquias')->count() : 0;
        $candidatos = in_array('curriculos',$tableNames) ? DB::table('curriculos')->count(): 0;

        return response()->json(compact('empresas', 'franquias', 'candidatos'));
    }

    /**
     * Recebe mensagens do widget "Fale aqui" da Home.
     */
    public function contato(Request $request)
    {
        $data = $request->validate([
            'nome_completo' => 'required|string|max:100',
            'telefone'      => 'required|string|max:20',
            'email'         => 'required|email|max:255',
            'mensagem'      => 'required|string|max:1000',
        ]);

        // Se a tabela contatos_site existir, persiste. Caso contrário, apenas retorna 200.
        $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()");
        $tableNames = array_map(fn($t) => $t->table_name ?? $t->TABLE_NAME, $tables);

        if (in_array('contatos_site', $tableNames)) {
            DB::table('contatos_site')->insert(array_merge($data, ['created_at' => now()]));
        }

        return response()->json(['message' => 'Mensagem recebida com sucesso.']);
    }
}
