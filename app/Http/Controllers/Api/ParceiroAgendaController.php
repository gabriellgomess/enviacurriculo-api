<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HasTokenContext;
use App\Models\ParceiroAgendamento;
use Illuminate\Http\Request;

class ParceiroAgendaController extends Controller
{
    use HasTokenContext;

    // GET /parceiro/agenda
    public function index(Request $request)
    {
        $parceiroId = $this->tokenContextId($request);

        $query = ParceiroAgendamento::where('parceiro_id', $parceiroId)
            ->orderBy('data');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('data')) {
            $query->whereDate('data', $request->data);
        }

        return response()->json(['data' => $query->get()]);
    }

    // PATCH /parceiro/agenda/{id}/confirmar
    public function confirmar(Request $request, $id)
    {
        $parceiroId    = $this->tokenContextId($request);
        $agendamento   = ParceiroAgendamento::where('parceiro_id', $parceiroId)->findOrFail($id);
        $agendamento->update(['status' => 'confirmado']);

        return response()->json(['data' => $agendamento]);
    }

    // PATCH /parceiro/agenda/{id}/concluir
    public function concluir(Request $request, $id)
    {
        $parceiroId  = $this->tokenContextId($request);
        $agendamento = ParceiroAgendamento::where('parceiro_id', $parceiroId)->findOrFail($id);
        $agendamento->update(['status' => 'concluido']);

        return response()->json(['data' => $agendamento]);
    }

    // PATCH /parceiro/agenda/{id}/cancelar
    public function cancelar(Request $request, $id)
    {
        $parceiroId  = $this->tokenContextId($request);
        $agendamento = ParceiroAgendamento::where('parceiro_id', $parceiroId)->findOrFail($id);

        $request->validate(['motivo' => 'nullable|string|max:500']);

        $agendamento->update([
            'status'               => 'cancelado',
            'motivo_cancelamento'  => $request->motivo,
        ]);

        return response()->json(['data' => $agendamento]);
    }
}
