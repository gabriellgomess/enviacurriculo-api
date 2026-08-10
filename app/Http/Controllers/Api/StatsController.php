<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Franquia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * Contadores públicos exibidos na Home.
     *
     * Usa os models: assim os registros excluídos (soft delete) ficam de fora
     * automaticamente. A versão anterior contava a tabela `curriculos`, que
     * nunca existiu — o nome correto é `candidatos` —, e por isso o indicador
     * de candidatos aparecia zerado.
     */
    public function index()
    {
        return response()->json([
            'empresas'   => Empresa::where('active', true)->count(),
            'franquias'  => Franquia::where('active', true)->count(),
            'candidatos' => Candidato::where('active', true)->count(),
        ]);
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
