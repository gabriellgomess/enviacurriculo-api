<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EmpresaEntrevista;
use Illuminate\Http\Request;

class EmpresaEntrevistaController extends Controller
{
    use HasTokenContext;

    private const STATUSES    = ['agendada', 'realizada', 'cancelada', 'nao_compareceu'];
    private const MODALIDADES = ['presencial', 'video', 'telefone'];

    /** Molda a entrevista no formato esperado pelo frontend. */
    private function present(EmpresaEntrevista $e): array
    {
        return [
            'id'             => $e->id,
            'data'           => $e->data,
            'local'          => $e->local,
            'modalidade'     => $e->modalidade,
            'link_video'     => $e->link_video,
            'consultor_nome' => $e->consultor_nome,
            'observacao'     => $e->observacao,
            'status'         => $e->status,
            'candidato'      => $e->candidato ? [
                'id'   => $e->candidato->id,
                'nome' => $e->candidato->user?->name,
            ] : null,
            'vaga'           => $e->vaga ? [
                'id'     => $e->vaga->id,
                'titulo' => $e->vaga->titulo,
            ] : null,
            'created_at'     => $e->created_at,
        ];
    }

    private function rules(): array
    {
        return [
            'candidato_id'   => 'nullable|exists:candidatos,id',
            'vaga_id'        => 'nullable|exists:vagas,id',
            'data'           => 'required|date',
            'local'          => 'nullable|string|max:255',
            'modalidade'     => 'nullable|in:' . implode(',', self::MODALIDADES),
            'link_video'     => 'nullable|string|max:255',
            'consultor_nome' => 'nullable|string|max:255',
            'observacao'     => 'nullable|string',
        ];
    }

    // GET /empresa/entrevistas
    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $query = EmpresaEntrevista::where('empresa_id', $empresaId)
            ->with(['candidato.user:id,name', 'vaga:id,titulo'])
            ->orderByDesc('data');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $entrevistas = $query->get();

        return response()->json([
            'data' => $entrevistas->map(fn($e) => $this->present($e)),
            'meta' => ['total' => $entrevistas->count()],
        ]);
    }

    // POST /empresa/entrevistas
    public function store(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $data = $request->validate($this->rules());

        $entrevista = EmpresaEntrevista::create(array_merge($data, [
            'empresa_id' => $empresaId,
            'modalidade' => $data['modalidade'] ?? 'presencial',
            'status'     => 'agendada',
        ]));

        $entrevista->load(['candidato.user:id,name', 'vaga:id,titulo']);

        return response()->json($this->present($entrevista), 201);
    }

    // PUT /empresa/entrevistas/{id}
    public function update(Request $request, int $id)
    {
        $empresaId  = $this->tokenContextId($request);
        $entrevista = EmpresaEntrevista::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate($this->rules());
        $entrevista->update($data);

        $entrevista->load(['candidato.user:id,name', 'vaga:id,titulo']);

        return response()->json($this->present($entrevista));
    }

    // PATCH /empresa/entrevistas/{id}/status
    public function updateStatus(Request $request, int $id)
    {
        $empresaId  = $this->tokenContextId($request);
        $entrevista = EmpresaEntrevista::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate([
            'status'     => 'required|in:' . implode(',', self::STATUSES),
            'observacao' => 'nullable|string',
        ]);

        $entrevista->update([
            'status'     => $data['status'],
            'observacao' => $data['observacao'] ?? $entrevista->observacao,
        ]);

        return response()->json($this->present($entrevista->fresh(['candidato.user:id,name', 'vaga:id,titulo'])));
    }

    // DELETE /empresa/entrevistas/{id}
    public function destroy(Request $request, int $id)
    {
        $empresaId  = $this->tokenContextId($request);
        $entrevista = EmpresaEntrevista::where('empresa_id', $empresaId)->findOrFail($id);
        $entrevista->delete();

        return response()->noContent();
    }
}
