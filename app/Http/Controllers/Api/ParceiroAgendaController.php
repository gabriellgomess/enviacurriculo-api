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

    // POST /parceiro/agenda
    public function store(Request $request)
    {
        $parceiroId = $this->tokenContextId($request);

        $data = $request->validate([
            'cliente'     => 'required|string|max:255',
            'email'       => 'nullable|email|max:255',
            'telefone'    => 'nullable|string|max:20',
            'servico'     => 'required|string|max:255',
            'data'        => 'required|date',
            'duracao_min' => 'nullable|integer|min:1|max:600',
            'observacao'  => 'nullable|string|max:1000',
        ]);

        $agendamento = ParceiroAgendamento::create([
            ...$data,
            'parceiro_id' => $parceiroId,
            'status'      => 'pendente',
        ]);

        return response()->json(['data' => $agendamento], 201);
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
