<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Support\Planos;
use Illuminate\Http\Request;

class EmpresaPlanoController extends Controller
{
    use HasTokenContext;

    // GET /empresa/plano
    public function show(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        $chave = $empresa->plano;
        $plano = Planos::find($chave);

        return response()->json(['data' => [
            'chave'                  => $chave,
            'plano_chave'            => $chave,
            'ativo'                  => (bool) $empresa->active,
            'vence_em'               => null,
            'tipo_acesso'            => $empresa->tipo_acesso,
            'status'                 => $empresa->status,
            'permite_publicar_vagas' => Planos::permitePublicarVagas($chave),
            'permite_receber_feed'   => Planos::permiteReceberFeed($chave),
            'plano'                  => $plano ? [
                'nome'    => $plano['nome'],
                'preco'   => $plano['preco'],
                'recursos'=> $plano['recursos'],
            ] : null,
        ]]);
    }

    // GET /empresa/plano/catalogo
    public function catalogo()
    {
        return response()->json(['data' => Planos::all()]);
    }

    // POST /empresa/plano/upgrade
    public function upgrade(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $empresa   = Empresa::findOrFail($empresaId);

        $data = $request->validate([
            'plano' => 'required|in:' . implode(',', Planos::chaves()),
        ]);

        $empresa->update(['plano' => $data['plano']]);

        return response()->json([
            'message' => 'Plano atualizado.',
            'data'    => ['chave' => $empresa->plano],
        ]);
    }

    // GET /empresa/faturamentos
    // Stub: nao ha sistema de cobranca/mensalidades implementado ainda
    // (a referencia usa Asaas; o frontend novo faz upgrade direto, sem pagamento).
    public function faturamentos()
    {
        return response()->json(['data' => []]);
    }
}
