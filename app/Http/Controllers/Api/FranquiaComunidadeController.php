<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComunidadeComentario;
use App\Models\ComunidadePost;
use App\Models\ComunidadeReacao;
use App\Models\UserContext;
use App\Models\Franquia;
use Illuminate\Http\Request;

class FranquiaComunidadeController extends Controller
{
    // GET /franquia/comunidade/posts
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $query = ComunidadePost::with('user:id,name')
            ->withCount('comentarios as total_comentarios')
            ->orderByDesc('created_at');

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        $posts = $query->paginate(20);

        $authorIds = $posts->pluck('user_id')->unique()->values();
        $franquiaPorUser = UserContext::where('role', 'franquia')
            ->whereIn('user_id', $authorIds)
            ->pluck('context_id', 'user_id');

        $franquias = Franquia::whereIn('id', $franquiaPorUser->values())
            ->get(['id', 'nome'])
            ->keyBy('id');

        $reagiuEm = ComunidadeReacao::whereIn('post_id', $posts->pluck('id'))
            ->where('user_id', $userId)
            ->pluck('post_id')
            ->flip();

        $comentarios = ComunidadeComentario::with('user:id,name')
            ->whereIn('post_id', $posts->pluck('id'))
            ->orderBy('created_at')
            ->get()
            ->groupBy('post_id');

        $items = $posts->getCollection()->map(function ($post) use ($franquiaPorUser, $franquias, $reagiuEm, $comentarios) {
            $franquiaId = $franquiaPorUser[$post->user_id] ?? null;
            $franquia   = $franquiaId ? $franquias[$franquiaId] ?? null : null;

            return [
                'id'               => $post->id,
                'user_id'          => $post->user_id,
                'titulo'           => $post->titulo,
                'tipo'             => $post->tipo,
                'conteudo'         => $post->conteudo,
                'imagem_url'       => $post->imagem_url,
                'created_at'       => $post->created_at,
                'autor'            => $franquia
                    ? ['id' => $franquiaId, 'nome' => $franquia->nome]
                    : ['id' => $post->user_id, 'nome' => $post->user?->name],
                'total_comentarios'=> $post->total_comentarios,
                'reacoes_count'    => 0,
                'user_reacted'     => $reagiuEm->has($post->id),
                'comentarios'      => collect($comentarios[$post->id] ?? [])->map(fn($c) => [
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

    // POST /franquia/comunidade/posts
    public function store(Request $request)
    {
        $validated = $request->validate([
            'titulo'    => 'nullable|string|max:255',
            'conteudo'  => 'required|string|max:5000',
            'tipo'      => 'nullable|in:duvida,compartilhamento,aviso',
            'imagem_url'=> 'nullable|string', // aceita base64 (data URI) ou URL de CDN
        ]);

        $post = ComunidadePost::create([
            'user_id'   => $request->user()->id,
            'titulo'    => $validated['titulo'] ?? null,
            'tipo'      => $validated['tipo'] ?? null,
            'conteudo'  => $validated['conteudo'],
            'imagem_url'=> $validated['imagem_url'] ?? null,
        ]);

        return response()->json(['message' => 'Post publicado.', 'data' => ['id' => $post->id]], 201);
    }

    // GET /franquia/comunidade/posts/{id}
    public function show(Request $request, int $id)
    {
        $userId  = $request->user()->id;
        $post    = ComunidadePost::with(['user:id,name', 'comentarios.user:id,name'])->findOrFail($id);

        $franquiaCtx = UserContext::where('role', 'franquia')
            ->where('user_id', $post->user_id)
            ->with('franquia:id,nome')
            ->first();

        return response()->json(['data' => [
            'id'               => $post->id,
            'titulo'           => $post->titulo,
            'tipo'             => $post->tipo,
            'conteudo'         => $post->conteudo,
            'imagem_url'       => $post->imagem_url,
            'created_at'       => $post->created_at,
            'autor'            => $franquiaCtx
                ? ['id' => $franquiaCtx->context_id, 'nome' => $franquiaCtx->franquia?->nome]
                : ['id' => $post->user_id, 'nome' => $post->user?->name],
            'comentarios'      => $post->comentarios->map(fn($c) => [
                'id'         => $c->id,
                'texto'      => $c->conteudo,
                'autor'      => ['id' => $c->user_id, 'nome' => $c->user?->name],
                'created_at' => $c->created_at,
            ]),
        ]]);
    }

    // POST /franquia/comunidade/posts/{id}/comentarios
    public function comentar(Request $request, int $id)
    {
        $post = ComunidadePost::findOrFail($id);

        // o frontend envia "conteudo"; aceita "texto" como fallback (compatibilidade)
        if (!$request->filled('conteudo') && $request->filled('texto')) {
            $request->merge(['conteudo' => $request->input('texto')]);
        }

        $validated = $request->validate([
            'conteudo' => 'required|string|max:2000',
        ]);

        $comentario = ComunidadeComentario::create([
            'post_id'  => $post->id,
            'user_id'  => $request->user()->id,
            'conteudo' => $validated['conteudo'],
        ]);

        $comentario->load('user:id,name');

        return response()->json(['message' => 'Comentário adicionado.', 'data' => [
            'id'         => $comentario->id,
            'autor'      => ['id' => $comentario->user_id, 'nome' => $comentario->user?->name],
            'texto'      => $comentario->conteudo,
            'created_at' => $comentario->created_at,
        ]], 201);
    }

    // DELETE /franquia/comunidade/posts/{id}
    public function destroy(Request $request, int $id)
    {
        $post = ComunidadePost::findOrFail($id);
        $user = $request->user();

        if ($post->user_id !== $user->id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        $post->delete();
        return response()->json(['message' => 'Post removido.']);
    }
}
