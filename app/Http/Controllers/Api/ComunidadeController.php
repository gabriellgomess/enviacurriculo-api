<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComunidadeComentario;
use App\Models\ComunidadePost;
use App\Models\ComunidadeReacao;
use App\Models\Franquia;
use App\Models\UserContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComunidadeController extends Controller
{
    // GET /comunidade/posts
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $posts = ComunidadePost::with(['user:id,name'])
            ->withCount('reacoes')
            ->orderByDesc('created_at')
            ->paginate(20);

        // Coleta os user_ids dos autores para buscar dados de franquia em uma query
        $authorIds = $posts->pluck('user_id')->unique()->values();

        $franquiaPorUser = UserContext::where('role', 'franquia')
            ->whereIn('user_id', $authorIds)
            ->pluck('context_id', 'user_id');

        $franquias = Franquia::whereIn('id', $franquiaPorUser->values())
            ->get(['id', 'nome', 'cidade', 'estado'])
            ->keyBy('id');

        // IDs de posts que o usuário atual curtiu
        $reagiuEm = ComunidadeReacao::whereIn('post_id', $posts->pluck('id'))
            ->where('user_id', $userId)
            ->pluck('post_id')
            ->flip();

        // Comentários dos posts (uma query só)
        $comentarios = ComunidadeComentario::with('user:id,name')
            ->whereIn('post_id', $posts->pluck('id'))
            ->orderBy('created_at')
            ->get()
            ->groupBy('post_id');

        $items = $posts->getCollection()->map(function ($post) use ($franquiaPorUser, $franquias, $reagiuEm, $comentarios) {
            $franquiaId = $franquiaPorUser[$post->user_id] ?? null;
            $franquia   = $franquiaId ? $franquias[$franquiaId] ?? null : null;

            return [
                'id'          => $post->id,
                'user_id'     => $post->user_id,
                'conteudo'    => $post->conteudo,
                'imagem_url'  => $post->imagem_url,
                'created_at'  => $post->created_at,
                'autor'       => $post->user?->name,
                'franquia'    => $franquia ? [
                    'nome'   => $franquia->nome,
                    'cidade' => $franquia->cidade,
                    'estado' => $franquia->estado,
                ] : null,
                'reacoes_count' => $post->reacoes_count,
                'user_reacted'  => $reagiuEm->has($post->id),
                'comentarios'   => collect($comentarios[$post->id] ?? [])->map(fn($c) => [
                    'id'         => $c->id,
                    'user_id'    => $c->user_id,
                    'autor'      => $c->user?->name,
                    'conteudo'   => $c->conteudo,
                    'created_at' => $c->created_at,
                ])->values(),
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page'    => $posts->lastPage(),
                'total'        => $posts->total(),
            ],
        ]);
    }

    // POST /comunidade/posts
    public function store(Request $request)
    {
        $validated = $request->validate([
            'conteudo'   => 'required|string|max:5000',
            'imagem_url' => 'nullable|url|max:500',
        ]);

        $post = ComunidadePost::create([
            'user_id'    => $request->user()->id,
            'conteudo'   => $validated['conteudo'],
            'imagem_url' => $validated['imagem_url'] ?? null,
        ]);

        return response()->json(['data' => $post], 201);
    }

    // DELETE /comunidade/posts/{id}
    public function destroy(Request $request, int $id)
    {
        $post = ComunidadePost::findOrFail($id);
        $user = $request->user();

        if ($post->user_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        $post->delete();
        return response()->json(['message' => 'Post excluído.']);
    }

    // POST /comunidade/posts/{id}/reagir
    public function toggleReacao(Request $request, int $id)
    {
        $post = ComunidadePost::findOrFail($id);
        $userId = $request->user()->id;

        return DB::transaction(function () use ($post, $userId) {
            $existing = ComunidadeReacao::where('post_id', $post->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing) {
                $existing->delete();
                $reacted = false;
            } else {
                ComunidadeReacao::create([
                    'post_id' => $post->id,
                    'user_id' => $userId,
                    'tipo'    => 'like',
                ]);
                $reacted = true;
            }

            $count = ComunidadeReacao::where('post_id', $post->id)->count();

            return response()->json([
                'reacted' => $reacted,
                'count'   => $count,
            ]);
        });
    }

    // POST /comunidade/posts/{id}/comentarios
    public function comentar(Request $request, int $id)
    {
        $post = ComunidadePost::findOrFail($id);

        $validated = $request->validate([
            'conteudo' => 'required|string|max:2000',
        ]);

        $comentario = ComunidadeComentario::create([
            'post_id'  => $post->id,
            'user_id'  => $request->user()->id,
            'conteudo' => $validated['conteudo'],
        ]);

        $comentario->load('user:id,name');

        return response()->json([
            'data' => [
                'id'         => $comentario->id,
                'user_id'    => $comentario->user_id,
                'autor'      => $comentario->user?->name,
                'conteudo'   => $comentario->conteudo,
                'created_at' => $comentario->created_at,
            ],
        ], 201);
    }

    // DELETE /comunidade/comentarios/{id}
    public function destroyComentario(Request $request, int $id)
    {
        $comentario = ComunidadeComentario::findOrFail($id);
        $user = $request->user();

        if ($comentario->user_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        $comentario->delete();
        return response()->json(['message' => 'Comentário excluído.']);
    }
}
