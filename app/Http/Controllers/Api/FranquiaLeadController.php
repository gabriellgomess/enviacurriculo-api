<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscConvite;
use App\Models\Franquia;
use App\Models\FranquiaLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
     * POST /leads-externos — público (webhook do formulário externo do cliente,
     * usado em campanhas de tráfego pago). Recebe o payload no formato do
     * formulário externo e normaliza para o padrão da tabela franquia_leads:
     *   nome → nome_completo | prazo_inicio → tempo_inicio | "Sim"/"Não" → boolean
     */
    public function storeExterno(Request $request)
    {
        $data = $request->validate([
            'nome'               => 'required|string|max:255',
            'email'              => 'required|email|max:255',
            'telefone'           => 'required|string|max:20',
            'experiencia_rh'     => 'nullable|string|max:20',
            'bairro'             => 'nullable|string|max:100',
            'cidade'             => 'nullable|string|max:100',
            'estado'             => 'nullable|string|size:2',
            'capital_disponivel' => 'nullable|string|max:50',
            'capital_confirmado' => 'nullable|string|max:20',
            'prazo_inicio'       => 'nullable|string|max:50',
            'motivacao'          => 'nullable|string',
            'indicacao'          => 'nullable|string|max:255',
            'cf_turnstile_token' => 'nullable|string',
        ]);

        // Anti-abuso: aceita EITHER token de webhook (WordPress/Elementor,
        // server-to-server) OR token Turnstile (formulário chamando a API
        // direto do navegador). Sem nenhum dos dois configurados, rota aberta.
        $webhookToken    = config('services.leads_externos.webhook_token');
        $turnstileSecret = config('services.turnstile.secret_key');

        $authorized = !$webhookToken && !$turnstileSecret;

        if (!$authorized && $webhookToken) {
            $provided   = $request->query('token') ?? $request->header('X-Webhook-Token');
            $authorized = $provided && hash_equals($webhookToken, $provided);
        }

        if (!$authorized && $turnstileSecret) {
            $token = $data['cf_turnstile_token'] ?? $request->input('cf-turnstile-response');

            if ($token) {
                $check = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret'   => $turnstileSecret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);

                $authorized = $check->ok() && $check->json('success');
            }
        }

        if (!$authorized) {
            return response()->json(['message' => 'Falha na verificação anti-bot.'], 403);
        }

        $simNao = fn($v) => mb_strtolower(trim((string) $v)) === 'sim';

        FranquiaLead::create([
            'nome_completo'      => $data['nome'],
            'email'              => $data['email'],
            'telefone'           => $data['telefone'],
            'experiencia_rh'     => $simNao($data['experiencia_rh'] ?? null),
            'bairro'             => $data['bairro'] ?? null,
            'cidade'             => $data['cidade'] ?? null,
            'estado'             => isset($data['estado']) ? strtoupper($data['estado']) : null,
            'capital_disponivel' => $data['capital_disponivel'] ?? null,
            'capital_confirmado' => $simNao($data['capital_confirmado'] ?? null),
            'tempo_inicio'       => $data['prazo_inicio'] ?? null,
            'motivacao'          => $data['motivacao'] ?? null,
            'indicacao'          => $data['indicacao'] ?? null,
        ]);

        return response()->json(['message' => 'Lead recebido com sucesso.'], 201);
    }

    /**
     * POST /admin/leads/{lead}/converter — cria uma franquia a partir do lead.
     * A franquia entra com dados basicos (tipo start) e o admin completa depois
     * (incluindo o acesso/login).
     */
    public function converter(FranquiaLead $lead)
    {
        if ($lead->tipo === 'parceiro') {
            return response()->json(['message' => 'Leads de parceiro não são convertidos em franquia.'], 422);
        }

        if ($lead->status === 'convertido') {
            return response()->json(['message' => 'Este lead já foi convertido.'], 422);
        }

        $franquia = DB::transaction(function () use ($lead) {
            $franquia = Franquia::create([
                'tipo'     => 'start',
                'nome'     => $lead->nome_completo,
                'email'    => $lead->email,
                'telefone' => $lead->telefone,
                'bairro'   => $lead->bairro,
                'cidade'   => $lead->cidade,
                'estado'   => $lead->estado,
            ]);
            $franquia->update(['codigo' => 'FR-' . str_pad($franquia->id, 4, '0', STR_PAD_LEFT)]);

            $lead->update(['status' => 'convertido']);

            return $franquia;
        });

        return response()->json([
            'message' => 'Lead convertido em franquia com sucesso.',
            'data'    => ['franquia_id' => $franquia->id, 'codigo' => $franquia->codigo],
        ], 201);
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
            ->when($request->filled('tipo') && $request->tipo !== 'todos',
                fn($q) => $q->where('tipo', $request->tipo))
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

        $oldStatus = $lead->status;

        DB::transaction(function () use ($lead, $data, $oldStatus) {
            $lead->update($data);

            // Leads de parceiro nunca geram/excluem franquia
            if ($lead->tipo === 'parceiro') {
                return;
            }

            if ($oldStatus !== 'convertido' && $lead->status === 'convertido') {
                // Criar franquia se mudou para convertido
                $franquia = Franquia::create([
                    'tipo'     => 'start',
                    'nome'     => $lead->nome_completo,
                    'email'    => $lead->email,
                    'telefone' => $lead->telefone,
                    'bairro'   => $lead->bairro,
                    'cidade'   => $lead->cidade,
                    'estado'   => $lead->estado,
                ]);
                $franquia->update(['codigo' => 'FR-' . str_pad($franquia->id, 4, '0', STR_PAD_LEFT)]);
            } elseif ($oldStatus === 'convertido' && $lead->status !== 'convertido') {
                // Excluir franquia se mudou de convertido para outro status
                $franquia = Franquia::where('email', $lead->email)->first();
                if ($franquia) {
                    $franquia->delete();
                }
            }
        });

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
