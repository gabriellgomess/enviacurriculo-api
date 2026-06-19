<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscConvite;
use App\Models\Franquia;
use App\Models\FranquiaLead;
use Illuminate\Http\Request;

class FranquiaLeadController extends Controller
{
    /**
     * GET /franquias-publicas — público (lista de franqueados para o campo
     * "Veio por indicação?" do formulário Seja Franqueado).
     */
    public function publicas()
    {
        $franquias = Franquia::where('active', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'cidade', 'estado']);

        return response()->json(['data' => $franquias]);
    }

    /**
     * POST /franquia-leads — público (formulário "Seja Franqueado" no home).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nome_completo'      => 'required|string|max:255',
            'email'              => 'required|email|max:255',
            'telefone'           => 'required|string|max:20',
            'experiencia_rh'     => 'nullable|boolean',
            'bairro'             => 'nullable|string|max:100',
            'cidade'             => 'nullable|string|max:100',
            'estado'             => 'nullable|string|size:2',
            'capital_disponivel' => 'nullable|string|max:50',
            'capital_confirmado' => 'nullable|boolean',
            'tempo_inicio'       => 'nullable|string|max:50',
            'motivacao'          => 'nullable|string',
            'indicacao'          => 'nullable|string|max:255',
        ]);

        FranquiaLead::create($data);

        return response()->json(['message' => 'Recebemos seu interesse! Em breve entraremos em contato.'], 201);
    }

    /**
     * GET /admin/leads — listagem com filtros.
     */
    public function index(Request $request)
    {
        $leads = FranquiaLead::query()
            ->with(['discConvites' => fn($q) => $q->latest()->with('resultado')])
            ->when($request->filled('status') && $request->status !== 'todos',
                fn($q) => $q->where('status', $request->status))
            ->when($request->filled('busca'), fn($q) => $q->where(function ($sub) use ($request) {
                $sub->where('nome_completo', 'like', "%{$request->busca}%")
                    ->orWhere('email', 'like', "%{$request->busca}%")
                    ->orWhere('cidade', 'like', "%{$request->busca}%");
            }))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $leads->items(),
            'meta' => ['total' => $leads->total(), 'per_page' => $leads->perPage(),
                       'current_page' => $leads->currentPage(), 'last_page' => $leads->lastPage()],
        ]);
    }

    /**
     * PATCH /admin/leads/{lead} — atualizar status/observações.
     */
    public function update(Request $request, FranquiaLead $lead)
    {
        $data = $request->validate([
            'status'      => 'required|in:novo,em_contato,qualificado,convertido,descartado',
            'observacoes' => 'nullable|string',
        ]);

        $lead->update($data);

        return response()->json($lead);
    }

    /**
     * DELETE /admin/leads/{lead}
     */
    public function destroy(FranquiaLead $lead)
    {
        $lead->delete();

        return response()->json(['message' => 'Lead excluído.']);
    }

    /**
     * POST /admin/leads/{lead}/disc-convite
     * Gera (ou reaproveita) o link público do teste DISC para o lead.
     */
    public function gerarDiscConvite(FranquiaLead $lead)
    {
        $convite = DiscConvite::where('lead_id', $lead->id)
            ->where('status', 'pendente')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$convite) {
            $convite = DiscConvite::gerarParaLead($lead->id);
        }

        $link = rtrim(config('frontends.candidato'), '/') . '/disc-teste/' . $convite->token;

        return response()->json([
            'token'      => $convite->token,
            'link'       => $link,
            'expires_at' => $convite->expires_at,
        ]);
    }
}
