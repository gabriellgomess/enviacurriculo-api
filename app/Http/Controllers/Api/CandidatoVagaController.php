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
                // Campos que o candidato enxerga em qualquer canal
                $q->where('titulo', 'like', "%{$s}%")
                  ->orWhere('descricao', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%")
                  // O código não é exibido em vagas de agência; deixá-lo
                  // pesquisável permitiria confirmá-lo por tentativa.
                  ->orWhere(function ($sub) use ($s) {
                      $sub->where('codigo', 'like', "%{$s}%")
                          ->where('canal', '!=', 'agencia');
                  });
            });
        }

        if ($request->filled('estado'))          $query->where('estado', $request->estado);
        if ($request->filled('cidade'))          $query->where('cidade', 'like', "%{$request->cidade}%");
        if ($request->filled('modalidade'))      $query->where('regime_trabalho', $request->modalidade);
        if ($request->filled('regime_trabalho')) $query->where('regime_trabalho', $request->regime_trabalho);
        if ($request->filled('tipo_contrato'))   $query->where('tipo_contrato', $request->tipo_contrato);

        $perPage = min((int) $request->input('per_page', 20), 200);
        $vagas = $query->orderByDesc('data_abertura')->paginate($perPage);

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
     * Campos que o candidato pode ver quando a vaga é divulgada pela AGÊNCIA
     * (canal = 'agencia'). Além dos dados do anúncio, mantém os campos
     * funcionais que a interface precisa (id, status, canal, flags).
     *
     * Vagas publicadas pela empresa via plataforma (canal 'plataforma' ou
     * 'ambos') continuam exibindo todas as informações.
     */
    private const CAMPOS_VISIVEIS_AGENCIA = [
        // informações permitidas do anúncio
        'titulo', 'turno', 'tipo_contrato', 'descricao', 'beneficios',
        'bairro', 'cidade', 'estado',
        // funcionais (necessários para a tela e para se candidatar)
        'id', 'status', 'canal', 'ja_aplicou', 'empresa_oculta', 'anunciante',
    ];

    /**
     * Em vagas de agência (ou com ocultar_empresa) o candidato não pode ver o
     * nome/identificação da empresa contratante. Em vagas de agência também
     * são omitidos os demais campos do anúncio (salário, requisitos, nível,
     * código, endereço detalhado etc.).
     */
    private function ocultarEmpresaSeAgencia(Vaga $v): Vaga
    {
        $ehAgencia = $v->canal === 'agencia';

        if (($ehAgencia || $v->ocultar_empresa) && $v->empresa) {
            $v->empresa->razao_social  = null;
            $v->empresa->nome_fantasia = null;
            $v->empresa->logo_url      = null;
            if (array_key_exists('descricao', $v->empresa->getAttributes())) {
                $v->empresa->descricao = null;
            }
            $v->setAttribute('empresa_oculta', true);
        }

        if ($ehAgencia) {
            // A relação empresa sai por completo: mesmo anonimizada, o
            // empresa_id permitiria identificar a contratante cruzando com
            // GET /candidato/empresas/{id}.
            $v->unsetRelation('empresa');

            // Nome exibido ao candidato no lugar da empresa contratante.
            $v->setAttribute('anunciante', 'Agência');

            // Ocultações que a empresa configurou para o anúncio de agência.
            // (empresa e salário já não aparecem na lista restrita acima.)
            if ($v->ocultar_endereco_agencia) {
                $v->setAttribute('bairro', null);
                $v->setAttribute('cidade', null);
                $v->setAttribute('estado', null);
            }

            // setVisible = whitelist real: cobre colunas, atributos anexados
            // ($appends, ex.: 'modalidade'/'salario_oculto') e relações.
            // Assim, campos novos no model não vazam por padrão.
            $v->setVisible(self::CAMPOS_VISIVEIS_AGENCIA);

            return $v;
        }

        // Vaga da plataforma: respeita o que a empresa escolheu ocultar
        if ($v->ocultar_endereco) {
            $v->setAttribute('cep', null);
            $v->setAttribute('logradouro', null);
            $v->setAttribute('numero', null);
        }

        if (!$v->exibir_salario) {
            $v->setAttribute('salario_min', null);
            $v->setAttribute('salario_max', null);
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
                // Candidatura espontânea pelo feed = canal plataforma
                'origem'       => 'plataforma',
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
