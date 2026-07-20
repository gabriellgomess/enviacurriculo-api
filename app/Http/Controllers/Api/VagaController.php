<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vaga;
use Illuminate\Http\Request;

class VagaController extends Controller
{
    public function index(Request $request)
    {
        $query = Vaga::with([
            'empresa:id,codigo,razao_social,nome_fantasia',
            'franquia:id,codigo,nome',
            'nivelVaga:id,nome',
        ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('titulo', 'like', "%{$s}%")
                  ->orWhere('codigo', 'like', "%{$s}%")
                  ->orWhere('cidade', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('empresa_id')) {
            $query->where('empresa_id', $request->empresa_id);
        }

        if ($request->filled('franquia_id')) {
            $query->where('franquia_id', $request->franquia_id);
        }

        if ($request->filled('nivel_vaga_id')) {
            $query->where('nivel_vaga_id', $request->nivel_vaga_id);
        }

        if ($request->filled('tipo_contrato')) {
            $query->where('tipo_contrato', $request->tipo_contrato);
        }

        if ($request->filled('regime_trabalho')) {
            $query->where('regime_trabalho', $request->regime_trabalho);
        }

        if ($request->filled('cidade')) {
            $query->where('cidade', 'like', '%' . $request->cidade . '%');
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('genero')) {
            $query->where('genero', $request->genero);
        }

        if ($request->filled('turno')) {
            $query->where('turno', $request->turno);
        }

        // Ordenação: padrão pela última atualização (mais recente primeiro)
        $sort = in_array($request->get('sort'), ['created_at', 'updated_at', 'titulo']) ? $request->get('sort') : 'updated_at';
        $dir  = $request->get('dir') === 'asc' ? 'asc' : 'desc';

        $vagas = $query->orderBy($sort, $dir)->paginate(20);

        $meta = [
            'total'     => Vaga::count(),
            'publicadas'=> Vaga::where('status', 'publicada')->count(),
            'rascunhos' => Vaga::where('status', 'rascunho')->count(),
            'pausadas'  => Vaga::where('status', 'pausada')->count(),
            'fechadas'  => Vaga::where('status', 'fechada')->count(),
        ];

        return response()->json([
            'data' => $vagas->items(),
            'meta' => array_merge($vagas->toArray(), $meta),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'titulo'          => 'required|string|max:255',
            'descricao'       => 'nullable|string',
            'requisitos'      => 'nullable|string',
            'beneficios'      => 'nullable|string',
            'empresa_id'      => 'required|exists:empresas,id',
            'franquia_id'     => 'nullable|exists:franquias,id',
            'nivel_vaga_id'   => 'nullable|exists:niveis_vagas,id',
            'taxa_servico'    => 'nullable|numeric|min:0|max:100',
            'tipo_contrato'   => 'required|string|max:50',
            'regime_trabalho' => 'required|in:presencial,remoto,hibrido',
            'carga_horaria'   => 'nullable|string|max:50',
            'salario_min'     => 'nullable|numeric|min:0',
            'salario_max'     => 'nullable|numeric|min:0',
            'exibir_salario'  => 'boolean',
            'cep'             => 'nullable|string|max:9',
            'cidade'          => 'nullable|string|max:100',
            'estado'          => 'nullable|string|size:2',
            'bairro'          => 'nullable|string|max:100',
            'quantidade_vagas'=> 'nullable|integer|min:1',
            'status'          => 'nullable|in:rascunho,publicada,pausada,fechada',
            'requer_validacao_premium' => 'nullable|boolean',
            'data_abertura'   => 'nullable|date',
            'data_fechamento' => 'nullable|date|after_or_equal:data_abertura',
            'observacoes'     => 'nullable|string',
            'genero'          => 'nullable|string|max:20',
            'turno'           => 'nullable|string|max:20',
            'horario_trabalho'=> 'nullable|string|max:50',
            'logradouro'      => 'nullable|string|max:255',
            'numero'          => 'nullable|string|max:20',
            'requisitantes'          => 'nullable|array',
            'requisitantes.*.nome'   => 'required_with:requisitantes|string|max:255',
            'requisitantes.*.email'  => 'nullable|email|max:255',
        ]);

        // Compatibilidade: primeiro requisitante espelhado nos campos antigos
        if (!empty($validated['requisitantes'])) {
            $validated['nome_requisitante']  = $validated['requisitantes'][0]['nome'] ?? null;
            $validated['email_requisitante'] = $validated['requisitantes'][0]['email'] ?? null;
        }

        $validated['codigo']  = $this->gerarCodigo();
        $validated['status']  = $validated['status'] ?? 'rascunho';

        $vaga = Vaga::create($validated);

        return response()->json(
            $vaga->load(['empresa:id,codigo,razao_social,nome_fantasia', 'franquia:id,codigo,nome', 'nivelVaga:id,nome']),
            201
        );
    }

    public function show(Vaga $vaga)
    {
        return response()->json(
            $vaga->load([
                'empresa:id,codigo,razao_social,nome_fantasia',
                'franquia:id,codigo,nome',
                'nivelVaga:id,nome',
                'franquiasCompartilhadas:id,nome',
            ])
        );
    }

    public function update(Request $request, Vaga $vaga)
    {
        $validated = $request->validate([
            'titulo'          => 'required|string|max:255',
            'descricao'       => 'nullable|string',
            'requisitos'      => 'nullable|string',
            'beneficios'      => 'nullable|string',
            'empresa_id'      => 'required|exists:empresas,id',
            'franquia_id'     => 'nullable|exists:franquias,id',
            'nivel_vaga_id'   => 'nullable|exists:niveis_vagas,id',
            'taxa_servico'    => 'nullable|numeric|min:0|max:100',
            'tipo_contrato'   => 'required|string|max:50',
            'regime_trabalho' => 'required|in:presencial,remoto,hibrido',
            'carga_horaria'   => 'nullable|string|max:50',
            'salario_min'     => 'nullable|numeric|min:0',
            'salario_max'     => 'nullable|numeric|min:0',
            'exibir_salario'  => 'boolean',
            'cep'             => 'nullable|string|max:9',
            'cidade'          => 'nullable|string|max:100',
            'estado'          => 'nullable|string|size:2',
            'bairro'          => 'nullable|string|max:100',
            'quantidade_vagas'=> 'nullable|integer|min:1',
            'status'          => 'nullable|in:rascunho,publicada,pausada,fechada',
            'requer_validacao_premium' => 'nullable|boolean',
            'data_abertura'   => 'nullable|date',
            'data_fechamento' => 'nullable|date|after_or_equal:data_abertura',
            'observacoes'     => 'nullable|string',
            'genero'          => 'nullable|string|max:20',
            'turno'           => 'nullable|string|max:20',
            'horario_trabalho'=> 'nullable|string|max:50',
            'logradouro'      => 'nullable|string|max:255',
            'numero'          => 'nullable|string|max:20',
            'requisitantes'          => 'nullable|array',
            'requisitantes.*.nome'   => 'required_with:requisitantes|string|max:255',
            'requisitantes.*.email'  => 'nullable|email|max:255',
        ]);

        // Compatibilidade: primeiro requisitante espelhado nos campos antigos
        if (!empty($validated['requisitantes'])) {
            $validated['nome_requisitante']  = $validated['requisitantes'][0]['nome'] ?? null;
            $validated['email_requisitante'] = $validated['requisitantes'][0]['email'] ?? null;
        }

        $vaga->update($validated);

        return response()->json(
            $vaga->fresh()->load(['empresa:id,codigo,razao_social,nome_fantasia', 'franquia:id,codigo,nome', 'nivelVaga:id,nome'])
        );
    }

    public function destroy(Vaga $vaga)
    {
        $vaga->delete();
        return response()->json(['message' => 'Vaga removida com sucesso.']);
    }

    public function changeStatus(Request $request, Vaga $vaga)
    {
        $request->validate(['status' => 'required|in:rascunho,publicada,pausada,fechada']);
        $vaga->update(['status' => $request->status]);
        return response()->json($vaga->fresh());
    }

    // POST /admin/vagas/{vaga}/convidar
    public function convidarFranquias(Request $request, Vaga $vaga)
    {
        $data = $request->validate([
            'franquia_ids'   => 'required|array',
            'franquia_ids.*' => 'integer|exists:franquias,id',
        ]);

        // sync substitui a lista completa de convites da vaga
        $vaga->franquiasCompartilhadas()->sync($data['franquia_ids']);

        return response()->json(['message' => 'Franquias convidadas com sucesso.']);
    }

    public function candidatos(int $id)
    {
        $candidatos = \App\Models\Envio::with(['candidato.user:id,name,email'])
            ->where('vaga_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($envio) {
                return [
                    'id' => $envio->id,
                    'candidato_id' => $envio->candidato_id,
                    'candidato_nome' => $envio->candidato?->user?->name ?? 'Candidato Desconhecido',
                    'candidato_email' => $envio->candidato?->user?->email ?? '—',
                    'status' => $envio->status,
                    'created_at' => $envio->created_at,
                ];
            });

        return response()->json(['data' => $candidatos]);
    }

    private function gerarCodigo(): string
    {
        $ultimo = Vaga::withTrashed()
            ->where('codigo', 'like', 'VG-%')
            ->orderByDesc('id')
            ->value('codigo');

        $numero = $ultimo ? (int) substr($ultimo, 3) + 1 : 1;
        return 'VG-' . str_pad($numero, 4, '0', STR_PAD_LEFT);
    }
}
