<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\DiscConvite;
use App\Models\TesteAgendado;
use App\Notifications\DiscConviteNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmpresaTesteController extends Controller
{
    use HasTokenContext;

    /* ─── Testes DISC ────────────────────────────────────────────────── */

    public function discIndex(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $convites = DiscConvite::with(['candidato.user:id,name,email', 'resultadoCandidato'])
            ->where('empresa_id', $empresaId)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($convites->items())->map(fn($c) => $this->discPayload($c)),
            'meta' => ['current_page' => $convites->currentPage(), 'last_page' => $convites->lastPage(),
                       'per_page' => $convites->perPage(), 'total' => $convites->total()],
        ]);
    }

    public function discStore(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate([
            'candidato_id'   => 'required|integer|exists:candidatos,id',
            'vaga_envio_id'  => ['nullable', 'integer', $this->envioDaEmpresa($empresaId)],
            'expira_em_dias' => 'nullable|integer|min:1|max:60',
        ], [
            'vaga_envio_id.exists' => 'A candidatura informada não existe ou não pertence à sua empresa.',
        ]);

        $convite = DiscConvite::create([
            'candidato_id'  => $data['candidato_id'],
            'vaga_envio_id' => $data['vaga_envio_id'] ?? null,
            'empresa_id'    => $empresaId,
            'criado_por'    => $request->user()->id,
            'token'         => Str::random(64),
            'status'        => 'pendente',
            'expires_at'    => now()->addDays($data['expira_em_dias'] ?? 7),
        ]);

        $this->enviarEmail($convite, $request->user()->name);

        return response()->json(['data' => [
            'id'           => $convite->id,
            'status'       => 'enviado',
            'link_publico' => $this->linkPublico($convite),
            'expira_em'    => $convite->expires_at,
        ]], 201);
    }

    public function discShow(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $convite = DiscConvite::with(['candidato.user:id,name,email', 'resultadoCandidato'])
            ->where('empresa_id', $empresaId)
            ->findOrFail($id);

        return response()->json(['data' => $this->discPayload($convite)]);
    }

    public function discReenviar(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $convite = DiscConvite::where('empresa_id', $empresaId)->findOrFail($id);

        if ($convite->status === 'respondido') {
            return response()->json(['message' => 'Este teste já foi respondido.'], 422);
        }

        if ($convite->isExpired()) {
            return response()->json(['message' => 'Convite expirado. Envie um novo teste.'], 422);
        }

        $this->enviarEmail($convite, $request->user()->name);

        return response()->json(['message' => 'Link reenviado.']);
    }

    private function discPayload(DiscConvite $c): array
    {
        $resultado = $c->resultadoCandidato;
        $status = $c->status === 'respondido'
            ? 'concluido'
            : ($c->isExpired() ? 'expirado' : 'enviado');

        return [
            'id'            => $c->id,
            'candidato'     => $c->candidato ? [
                'id'    => $c->candidato->id,
                'nome'  => $c->candidato->user?->name,
                'email' => $c->candidato->user?->email,
            ] : null,
            'vaga_envio_id' => $c->vaga_envio_id,
            'status'        => $status,
            'resultado'     => $resultado ? [
                'D'      => $resultado->score_d,
                'I'      => $resultado->score_i,
                'S'      => $resultado->score_s,
                'C'      => $resultado->score_c,
                'perfil' => $resultado->perfil_dominante,
            ] : null,
            'perfil_dominante' => $resultado?->perfil_dominante,
            'link_publico'  => $status === 'enviado' ? $this->linkPublico($c) : null,
            'expira_em'     => $c->expires_at,
            'created_at'    => $c->created_at,
        ];
    }

    private function linkPublico(DiscConvite $convite): string
    {
        return rtrim(config('frontends.candidato'), '/') . '/disc-teste/' . $convite->token;
    }

    private function enviarEmail(DiscConvite $convite, string $remetenteNome): void
    {
        try {
            $candidato = Candidato::with('user')->find($convite->candidato_id);
            $empresaNome = $convite->empresa_id
                ? (\App\Models\Empresa::find($convite->empresa_id)?->razao_social ?? $remetenteNome)
                : $remetenteNome;

            $candidato?->user?->notify(new DiscConviteNotification(
                $this->linkPublico($convite),
                $empresaNome,
                $convite->expires_at,
            ));
        } catch (\Throwable) {
            // falha no e-mail não bloqueia a operação; o link fica disponível na tela
        }
    }

    /* ─── Testes agendados (práticos/técnicos) ───────────────────────── */

    public function agendadosIndex(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $testes = TesteAgendado::with(['candidato.user:id,name', 'vaga:id,titulo'])
            ->where('empresa_id', $empresaId)
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('de'),  fn($q) => $q->whereDate('data', '>=', $request->de))
            ->when($request->filled('ate'), fn($q) => $q->whereDate('data', '<=', $request->ate))
            ->orderBy('data')
            ->paginate(20);

        return response()->json([
            'data' => collect($testes->items())->map(fn($t) => $this->agendadoPayload($t)),
            'meta' => ['current_page' => $testes->currentPage(), 'last_page' => $testes->lastPage(),
                       'per_page' => $testes->perPage(), 'total' => $testes->total()],
        ]);
    }

    public function agendadosStore(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $this->validateAgendado($request);

        $teste = TesteAgendado::create([...$data, 'empresa_id' => $empresaId, 'status' => 'agendado']);

        return response()->json(['data' => $this->agendadoPayload($teste->load(['candidato.user:id,name', 'vaga:id,titulo']))], 201);
    }

    public function agendadosUpdate(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $teste = TesteAgendado::where('empresa_id', $empresaId)->findOrFail($id);
        $teste->update($this->validateAgendado($request));

        return response()->json(['message' => 'Teste atualizado.']);
    }

    public function agendadosStatus(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        $data = $request->validate([
            'status'     => 'required|in:agendado,realizado,cancelado,nao_compareceu',
            'observacao' => 'nullable|string',
        ]);

        $teste = TesteAgendado::where('empresa_id', $empresaId)->findOrFail($id);
        $teste->update($data);

        return response()->json(['message' => 'Status atualizado.']);
    }

    public function agendadosDestroy(Request $request, int $id)
    {
        $empresaId = $this->tokenContextId($request);

        TesteAgendado::where('empresa_id', $empresaId)->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function validateAgendado(Request $request): array
    {
        $empresaId = $this->tokenContextId($request);

        return $request->validate([
            'candidato_id'  => 'required|integer|exists:candidatos,id',
            'vaga_envio_id' => ['nullable', 'integer', $this->envioDaEmpresa($empresaId)],
            'vaga_id'       => 'nullable|integer|exists:vagas,id',
            'tipo_teste'    => 'required|string|max:30',
            'data'          => 'required|date',
            'local'         => 'nullable|string|max:255',
            'observacao'    => 'nullable|string',
        ], [
            'vaga_envio_id.exists' => 'A candidatura informada não existe ou não pertence à sua empresa.',
        ]);
    }

    /**
     * Regra: o envio informado deve pertencer a uma vaga da própria empresa.
     */
    private function envioDaEmpresa(int $empresaId): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('envios', 'id')->where(
            fn($q) => $q->whereIn('vaga_id', fn($sub) => $sub->select('id')->from('vagas')->where('empresa_id', $empresaId))
        );
    }

    private function agendadoPayload(TesteAgendado $t): array
    {
        return [
            'id'         => $t->id,
            'candidato'  => $t->candidato ? ['id' => $t->candidato->id, 'nome' => $t->candidato->user?->name] : null,
            'vaga'       => $t->vaga ? ['id' => $t->vaga->id, 'titulo' => $t->vaga->titulo] : null,
            'tipo_teste' => $t->tipo_teste,
            'data'       => $t->data,
            'local'      => $t->local,
            'status'     => $t->status,
            'observacao' => $t->observacao,
        ];
    }
}
