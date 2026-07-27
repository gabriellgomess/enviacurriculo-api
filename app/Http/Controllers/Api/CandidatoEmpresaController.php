<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\Request;

class CandidatoEmpresaController extends Controller
{
    /**
     * Produtos que dão visibilidade da empresa no feed do candidato:
     * "plataforma" (contratou plano) e "ambos". Empresas somente "agencia"
     * não aparecem para os candidatos.
     */
    private const PRODUTOS_VISIVEIS = ['plataforma', 'ambos'];

    // GET /candidato/empresas
    public function index(Request $request)
    {
        $query = Empresa::where('status', 'aprovado')
            ->where('active', true)
            ->whereIn('tipo_acesso', self::PRODUTOS_VISIVEIS)
            ->withCount(['vagas as vagas_ativas_count' => function ($q) {
                $q->where('status', 'publicada');
            }])
            ->with(['vagas' => function ($q) {
                $q->where('status', 'publicada')->select('id', 'empresa_id', 'titulo');
            }]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('razao_social', 'like', "%{$s}%")
                  ->orWhere('nome_fantasia', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%");
            });
        }

        if ($request->filled('estado')) $query->where('estado', $request->estado);
        if ($request->filled('cidade')) $query->where('cidade', 'like', "%{$request->cidade}%");
        if ($request->filled('bairro')) $query->where('bairro', 'like', "%{$request->bairro}%");

        // Nome da empresa (razão social ou nome fantasia)
        if ($request->filled('empresa')) {
            $e = $request->empresa;
            $query->where(function ($q) use ($e) {
                $q->where('razao_social', 'like', "%{$e}%")
                  ->orWhere('nome_fantasia', 'like', "%{$e}%");
            });
        }

        // Cargo: empresas com ao menos uma vaga publicada com esse título
        if ($request->filled('cargo')) {
            $cargo = $request->cargo;
            $query->whereHas('vagas', function ($q) use ($cargo) {
                $q->where('status', 'publicada')
                  ->where('titulo', 'like', "%{$cargo}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 200);
        $empresas = $query->orderBy('razao_social')->paginate($perPage);

        return response()->json([
            'data' => $empresas->items(),
            'meta' => $empresas->toArray(),
        ]);
    }

    // GET /candidato/empresas/{id}
    public function show($id)
    {
        $empresa = Empresa::where('status', 'aprovado')
            ->where('active', true)
            ->whereIn('tipo_acesso', self::PRODUTOS_VISIVEIS)
            ->with(['vagas' => function ($q) {
                $q->where('status', 'publicada');
            }, 'beneficios'])
            ->findOrFail($id);

        return response()->json(['data' => $empresa]);
    }
}
