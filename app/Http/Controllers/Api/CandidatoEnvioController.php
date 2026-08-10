<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Envio;
use Illuminate\Http\Request;

class CandidatoEnvioController extends Controller
{
    private function candidatoDoUsuario(): Candidato
    {
        return Candidato::where('user_id', auth()->id())->firstOrFail();
    }

    /** Status que contam como "o currículo já foi aberto pela empresa". */
    private const VISTOS = ['visualizado', 'em_processo', 'aprovado'];

    // GET /candidato/envios
    public function index(Request $request)
    {
        $c = $this->candidatoDoUsuario();

        $perPage = min((int) $request->input('per_page', 20), 100);

        $envios = Envio::where('candidato_id', $c->id)
            ->with([
                // canal e ocultar_empresa entram para decidir a anonimização
                'vaga:id,titulo,cidade,estado,empresa_id,canal,ocultar_empresa',
                'vaga.empresa:id,razao_social,nome_fantasia',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $envios->getCollection()->each(fn ($e) => $this->anonimizarAgencia($e));

        // Os cartões do topo somam TODO o histórico, não a página aberta.
        // Contar no front sobre `data` mostraria no máximo 20 depois que o
        // histórico migrado tornou a paginação visível.
        $total  = Envio::where('candidato_id', $c->id)->count();
        $vistos = Envio::where('candidato_id', $c->id)
            ->whereIn('status', self::VISTOS)->count();

        return response()->json([
            'data'   => $envios->items(),
            'meta'   => collect($envios->toArray())->except('data')->all(),
            'totais' => [
                'enviados'     => $total,
                'visualizados' => $vistos,
                'aguardando'   => $total - $vistos,
            ],
        ]);
    }

    // GET /candidato/envios/{id}
    public function show($id)
    {
        $c = $this->candidatoDoUsuario();
        $envio = Envio::where('candidato_id', $c->id)
            ->with(['vaga.empresa', 'curriculo'])
            ->findOrFail($id);

        $this->anonimizarAgencia($envio);

        return response()->json(['data' => $envio]);
    }

    /**
     * Em vaga de agência o candidato nunca vê a empresa contratante — a mesma
     * regra que CandidatoVagaController aplica ao feed. O histórico de envios
     * não aplicava: como a migração trouxe todas as vagas antigas com
     * canal = 'agencia', o nome da contratante aparecia aqui para praticamente
     * todo o histórico.
     */
    private function anonimizarAgencia(Envio $envio): void
    {
        $vaga = $envio->vaga;
        if (!$vaga) return;

        if ($vaga->canal !== 'agencia' && !$vaga->ocultar_empresa) return;

        // A relação sai por completo: mesmo anonimizada, o empresa_id
        // permitiria identificar a contratante por outro endpoint.
        $vaga->unsetRelation('empresa');
        $vaga->setAttribute('empresa_id', null);
        $vaga->setAttribute('empresa_oculta', true);
        $vaga->setAttribute('anunciante', 'EnviaCurrículo');
    }
}
