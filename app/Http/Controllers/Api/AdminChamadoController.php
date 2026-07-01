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

    // GET /api/admin/chamados/relatorios
    public function relatorios(Request $request)
    {
        $query = FranquiaChamado::with('franquia:id,nome,codigo')
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->status !== 'todos' && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->data_inicio);
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->data_fim);
        }

        if ($request->filled('busca')) {
            $s = $request->busca;
            $query->where(function ($q) use ($s) {
                $q->where('titulo', 'like', "%{$s}%")
                  ->orWhere('categoria', 'like', "%{$s}%")
                  ->orWhereHas('franquia', function ($f) use ($s) {
                      $f->where('nome', 'like', "%{$s}%");
                  });
            });
        }

        $chamados = $query->get();
        $reportType = $request->input('report_type', 'resumo_status');

        $data = $chamados->map(function ($c) use ($reportType) {
            $diffSeconds = strtotime($c->updated_at) - strtotime($c->created_at);
            $days = max(1, (int) ceil($diffSeconds / (60 * 60 * 24)));

            $numero = (string)$c->id;
            $assunto = $c->titulo;
            $franquia = $c->franquia ? $c->franquia->nome : '-';
            $tipo = ucfirst($c->categoria ?? 'geral');
            $status = $c->status;
            $dataAbertura = $c->created_at->format('d/m/Y');
            $ultimaAtualizacao = $c->updated_at->format('d/m/Y');

            if ($reportType === 'tempo_resolucao') {
                return [
                    'Número'             => $numero,
                    'Assunto'            => $assunto,
                    'Franquia'           => $franquia,
                    'Status'             => $status,
                    'Data Abertura'      => $dataAbertura,
                    'Última Atualização' => $ultimaAtualizacao,
                    'Dias'               => (string)$days,
                ];
            } elseif ($reportType === 'por_tipo') {
                return [
                    'Tipo'          => $tipo,
                    'Número'         => $numero,
                    'Assunto'        => $assunto,
                    'Franquia'       => $franquia,
                    'Status'         => $status,
                    'Data Abertura'  => $dataAbertura,
                ];
            } else { // resumo_status
                return [
                    'Número'        => $numero,
                    'Assunto'       => $assunto,
                    'Franquia'      => $franquia,
                    'Tipo'          => $tipo,
                    'Status'        => $status,
                    'Data Abertura' => $dataAbertura,
                ];
            }
        });

        return response()->json(['data' => $data]);
    }

    // GET /api/admin/contatos-site
    public function indexContatos(Request $request)
    {
        $query = \App\Models\ContatoSite::orderByDesc('created_at');

        if ($request->filled('busca')) {
            $s = $request->busca;
            $query->where(function ($q) use ($s) {
                $q->where('nome_completo', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('mensagem', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        $contatos = $query->paginate(20);

        return response()->json([
            'data' => $contatos->items(),
            'meta' => [
                'total'        => $contatos->total(),
                'per_page'     => $contatos->perPage(),
                'current_page' => $contatos->currentPage(),
                'last_page'    => $contatos->lastPage(),
            ],
        ]);
    }

    // PATCH /api/admin/contatos-site/{id}/status
    public function updateContatoStatus(Request $request, int $id)
    {
        $contato = \App\Models\ContatoSite::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:novo,lido,respondido',
        ]);

        $contato->update(['status' => $validated['status']]);

        return response()->json(['message' => 'Status do contato atualizado.', 'data' => $contato]);
    }

    // DELETE /api/admin/contatos-site/{id}
    public function destroyContato(int $id)
    {
        $contato = \App\Models\ContatoSite::findOrFail($id);
        $contato->delete();

        return response()->json(['message' => 'Contato excluído com sucesso.']);
    }
}
