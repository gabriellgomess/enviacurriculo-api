<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\FranquiaChamado;
use App\Models\FranquiaChamadoMensagem;
use Illuminate\Http\Request;

class FranquiaChamadoController extends Controller
{
    use HasTokenContext;

    // GET /franquia/chamados
    public function index(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $query = FranquiaChamado::where('franquia_id', $franquiaId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $chamados = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $chamados->items(),
            'meta' => [
                'total'        => $chamados->total(),
                'per_page'     => $chamados->perPage(),
                'current_page' => $chamados->currentPage(),
                'last_page'    => $chamados->lastPage(),
            ],
        ]);
    }

    // POST /franquia/chamados
    public function store(Request $request)
    {
        $franquiaId = $this->tokenContextId($request);

        $validated = $request->validate([
            'titulo'    => 'required|string|max:255',
            'descricao' => 'required|string',
            'categoria' => 'nullable|in:sistema,financeiro,comercial,operacional,outro',
            'prioridade'=> 'nullable|in:baixa,media,alta,urgente',
        ]);

        $chamado = FranquiaChamado::create(array_merge($validated, [
            'franquia_id' => $franquiaId,
            'status'      => 'aberto',
        ]));

        FranquiaChamadoMensagem::create([
            'chamado_id' => $chamado->id,
            'mensagem'   => $validated['descricao'],
            'autor'      => 'franquia',
        ]);

        return response()->json([
            'message' => 'Chamado aberto com sucesso.',
            'data'    => ['id' => $chamado->id, 'status' => $chamado->status, 'created_at' => $chamado->created_at],
        ], 201);
    }

    // GET /franquia/chamados/{id}
    public function show(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $chamado = FranquiaChamado::where('franquia_id', $franquiaId)->findOrFail($id);

        return response()->json(['data' => $chamado]);
    }

    // PATCH /franquia/chamados/{id}/fechar
    public function fechar(Request $request, int $id)
    {
        $franquiaId = $this->tokenContextId($request);

        $chamado = FranquiaChamado::where('franquia_id', $franquiaId)
            ->where('status', '!=', 'fechado')
            ->findOrFail($id);

        $chamado->update(['status' => 'fechado']);

        return response()->json(['message' => 'Chamado encerrado.', 'data' => ['id' => $chamado->id, 'status' => 'fechado']]);
    }

    // GET /franquia/chamados/{chamadoId}/mensagens
    public function mensagens(Request $request, int $chamadoId)
    {
        $franquiaId = $this->tokenContextId($request);

        $chamado = FranquiaChamado::where('franquia_id', $franquiaId)->findOrFail($chamadoId);

        $mensagens = FranquiaChamadoMensagem::where('chamado_id', $chamado->id)
            ->orderBy('created_at')
            ->get(['id', 'mensagem', 'autor', 'created_at']);

        return response()->json(['data' => $mensagens]);
    }

    // POST /franquia/chamados/{chamadoId}/mensagens
    public function storeMensagem(Request $request, int $chamadoId)
    {
        $franquiaId = $this->tokenContextId($request);

        $chamado = FranquiaChamado::where('franquia_id', $franquiaId)
            ->where('status', '!=', 'fechado')
            ->findOrFail($chamadoId);

        $validated = $request->validate(['mensagem' => 'required|string|max:5000']);

        $msg = FranquiaChamadoMensagem::create([
            'chamado_id' => $chamado->id,
            'mensagem'   => $validated['mensagem'],
            'autor'      => 'franquia',
        ]);

        return response()->json(['message' => 'Mensagem enviada.', 'data' => ['id' => $msg->id]], 201);
    }
}
