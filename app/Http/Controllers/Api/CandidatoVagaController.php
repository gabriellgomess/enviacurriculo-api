<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\CreditoMovimentacao;
use App\Models\Envio;
use App\Models\Vaga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CandidatoVagaController extends Controller
{
    private function candidatoDoUsuario(): Candidato
    {
        return Candidato::where('user_id', auth()->id())->firstOrFail();
    }

    // GET /candidato/vagas
    public function index(Request $request)
    {
        $c = $this->candidatoDoUsuario();

        $query = Vaga::with(['empresa:id,razao_social,nome_fantasia,logo_url,cidade,estado', 'nivelVaga:id,nome'])
            ->where('status', 'publicada');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('titulo', 'like', "%{$s}%")
                  ->orWhere('descricao', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%")
                  ->orWhere('codigo', 'like', "%{$s}%");
            });
        }

        if ($request->filled('estado'))          $query->where('estado', $request->estado);
        if ($request->filled('cidade'))          $query->where('cidade', 'like', "%{$request->cidade}%");
        if ($request->filled('modalidade'))      $query->where('regime_trabalho', $request->modalidade);
        if ($request->filled('regime_trabalho')) $query->where('regime_trabalho', $request->regime_trabalho);
        if ($request->filled('tipo_contrato'))   $query->where('tipo_contrato', $request->tipo_contrato);

        $vagas = $query->orderByDesc('data_abertura')->paginate(20);

        // Marca ja_aplicou
        $aplicadas = Envio::where('candidato_id', $c->id)
            ->whereIn('vaga_id', collect($vagas->items())->pluck('id'))
            ->pluck('vaga_id')
            ->toArray();

        $items = collect($vagas->items())->map(function ($v) use ($aplicadas) {
            $v->ja_aplicou = in_array($v->id, $aplicadas);
            return $this->ocultarEmpresaSeAgencia($v);
        });

        return response()->json([
            'data' => $items,
            'meta' => $vagas->toArray(),
        ]);
    }

    // GET /candidato/vagas/{id}
    public function show($id)
    {
        $c = $this->candidatoDoUsuario();
        $vaga = Vaga::with(['empresa:id,razao_social,nome_fantasia,logo_url,cidade,estado,descricao', 'nivelVaga:id,nome'])
            ->where('status', 'publicada')
            ->findOrFail($id);

        $vaga->ja_aplicou = Envio::where('candidato_id', $c->id)
            ->where('vaga_id', $vaga->id)
            ->exists();

        return response()->json(['data' => $this->ocultarEmpresaSeAgencia($vaga)]);
    }

    /**
     * Em vagas de agência (ou com ocultar_empresa) o candidato não pode ver o
     * nome/identificação da empresa contratante.
     */
    private function ocultarEmpresaSeAgencia(Vaga $v): Vaga
    {
        if (($v->canal === 'agencia' || $v->ocultar_empresa) && $v->empresa) {
            $v->empresa->razao_social  = null;
            $v->empresa->nome_fantasia = null;
            $v->empresa->logo_url      = null;
            $v->setAttribute('empresa_oculta', true);
        }
        return $v;
    }

    // POST /candidato/vagas/{id}/aplicar
    public function aplicar(Request $request, $id)
    {
        $request->validate([
            'curriculo_id' => 'required|integer|exists:candidato_documentos,id',
            'mensagem'     => 'nullable|string|max:2000',
        ]);

        $c    = $this->candidatoDoUsuario();
        $vaga = Vaga::where('status', 'publicada')->findOrFail($id);

        if (Envio::where('candidato_id', $c->id)->where('vaga_id', $vaga->id)->exists()) {
            return response()->json(['message' => 'Você já se candidatou a esta vaga.'], 409);
        }

        $doc = CandidatoDocumento::where('candidato_id', $c->id)
            ->where('id', $request->curriculo_id)
            ->first();
        if (!$doc) {
            return response()->json(['message' => 'Currículo não encontrado.'], 404);
        }

        if ($c->creditos < 1) {
            return response()->json(['message' => 'Saldo insuficiente. Compre mais créditos para aplicar.'], 402);
        }

        return DB::transaction(function () use ($c, $vaga, $doc, $request) {
            $envio = Envio::create([
                'candidato_id' => $c->id,
                'vaga_id'      => $vaga->id,
                'curriculo_id' => $doc->id,
                'mensagem'     => $request->mensagem,
            ]);

            $saldoAntes = $c->creditos;
            $c->decrement('creditos');

            CreditoMovimentacao::create([
                'candidato_id'    => $c->id,
                'tipo'            => 'uso',
                'quantidade'      => -1,
                'saldo_antes'     => $saldoAntes,
                'saldo_depois'    => $saldoAntes - 1,
                'descricao'       => "Envio para vaga: {$vaga->titulo}",
                'referencia_tipo' => Envio::class,
                'referencia_id'   => $envio->id,
            ]);

            \App\Models\FranquiaNotificacao::notificar(
                $vaga->franquia_id,
                'Novo candidato na vaga',
                "{$c->user->name} se candidatou à vaga {$vaga->titulo}.",
            );

            return response()->json([
                'data'            => $envio,
                'saldo_restante'  => $saldoAntes - 1,
            ], 201);
        });
    }
}
