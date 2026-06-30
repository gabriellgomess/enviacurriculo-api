<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FranquiaChamado;
use App\Models\FranquiaChamadoMensagem;
use Illuminate\Http\Request;



class AdminChamadoController extends Controller
{
    // GET /api/admin/chamados
    public function index(Request $request)
    {
        $query = FranquiaChamado::with('franquia:id,nome,codigo')
            ->orderByDesc('updated_at');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        if ($request->filled('franquia_id')) {
            $query->where('franquia_id', $request->franquia_id);
        }

        if ($request->filled('prioridade') && $request->prioridade !== 'todos') {
            $query->where('prioridade', $request->prioridade);
        }

        if ($request->filled('busca')) {
            $query->where('titulo', 'like', '%' . $request->busca . '%');
        }

        $chamados = $query->paginate(20);

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

    // GET /api/admin/chamados/{id}
    public function show(int $id)
    {
        $chamado = FranquiaChamado::with('franquia:id,nome,codigo')->findOrFail($id);

        $mensagens = FranquiaChamadoMensagem::where('chamado_id', $chamado->id)
            ->orderBy('created_at')
            ->get(['id', 'mensagem', 'autor', 'created_at']);

        return response()->json([
            'data'      => $chamado,
            'mensagens' => $mensagens,
        ]);
    }

    // POST /api/admin/chamados/{id}/mensagens
    public function storeMensagem(Request $request, int $id)
    {
        $chamado = FranquiaChamado::findOrFail($id);

        if ($chamado->status === 'fechado') {
            return response()->json(['message' => 'Chamado já está fechado.'], 422);
        }

        $validated = $request->validate(['mensagem' => 'required|string|max:5000']);

        $msg = FranquiaChamadoMensagem::create([
            'chamado_id' => $chamado->id,
            'mensagem'   => $validated['mensagem'],
            'autor'      => 'suporte',
        ]);

        // Mover para em_atendimento se ainda estava aberto
        if ($chamado->status === 'aberto') {
            $chamado->update(['status' => 'em_atendimento']);
        }

        return response()->json(['message' => 'Mensagem enviada.', 'data' => $msg], 201);
    }

    // PATCH /api/admin/chamados/{id}/fechar
    public function fechar(int $id)
    {
        $chamado = FranquiaChamado::findOrFail($id);

        if ($chamado->status === 'fechado') {
            return response()->json(['message' => 'Chamado já está fechado.'], 422);
        }

        $chamado->update(['status' => 'fechado']);

        return response()->json(['message' => 'Chamado encerrado.', 'data' => ['id' => $chamado->id, 'status' => 'fechado']]);
    }

    // PATCH /api/admin/chamados/{id}/reabrir
    public function reabrir(int $id)
    {
        $chamado = FranquiaChamado::findOrFail($id);

        $chamado->update(['status' => 'em_atendimento']);

        return response()->json(['message' => 'Chamado reaberto.', 'data' => ['id' => $chamado->id, 'status' => 'em_atendimento']]);
    }
}
