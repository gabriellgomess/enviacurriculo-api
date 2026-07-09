<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgendaTarefa;
use App\Models\EmpresaColaborador;
use App\Models\Empresa;
use App\Models\Franquia;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AgendaController extends Controller
{
    // GET /api/agenda/tarefas
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $tarefas = AgendaTarefa::where('user_id', $userId)
            ->orderBy('data_tarefa')
            ->orderBy('hora')
            ->get();

        return response()->json(['data' => $tarefas]);
    }

    // POST /api/agenda/tarefas
    public function store(Request $request)
    {
        $validated = $request->validate([
            'titulo'      => 'required|string|max:255',
            'descricao'   => 'nullable|string',
            'data_tarefa' => 'required|date_format:Y-m-d',
            'hora'        => 'nullable|string|max:5',
        ]);

        $tarefa = AgendaTarefa::create(array_merge($validated, [
            'user_id'   => $request->user()->id,
            'concluida' => false,
        ]));

        return response()->json(['message' => 'Tarefa criada com sucesso.', 'data' => $tarefa], 201);
    }

    // PATCH /api/agenda/tarefas/{id}/toggle
    public function toggle(Request $request, int $id)
    {
        $userId = $request->user()->id;
        $tarefa = AgendaTarefa::where('user_id', $userId)->findOrFail($id);

        $tarefa->update([
            'concluida' => !$tarefa->concluida,
        ]);

        return response()->json(['message' => 'Tarefa atualizada.', 'data' => $tarefa]);
    }

    // DELETE /api/agenda/tarefas/{id}
    public function destroy(Request $request, int $id)
    {
        $userId = $request->user()->id;
        $tarefa = AgendaTarefa::where('user_id', $userId)->findOrFail($id);
        $tarefa->delete();

        return response()->json(['message' => 'Tarefa excluída com sucesso.']);
    }

    // GET /api/agenda/aniversarios
    public function aniversarios(Request $request)
    {
        $user = $request->user();
        $items = [];

        // Resolve active role from Sanctum token abilities
        $role = null;
        $token = $user->currentAccessToken();
        if ($token) {
            $roleAbility = collect($token->abilities ?? [])
                ->first(fn($a) => str_starts_with($a, 'role:'));
            if ($roleAbility) {
                $role = substr($roleAbility, 5);
            }
        }

        // 1. If admin, load franchise birthdays and partnership anniversaries
        if ($role === 'admin') {
            $franquias = Franquia::where('active', true)->get();
            foreach ($franquias as $f) {
                // Calculate partnership duration
                $tempo = '';
                if ($f->data_inicio_parceria) {
                    $inicio = Carbon::parse($f->data_inicio_parceria);
                    $diffYears = (int) abs(Carbon::now()->diffInYears($inicio));
                    if ($diffYears > 0) {
                        $tempo = $diffYears == 1 ? "1 ano" : "{$diffYears} anos";
                    } else {
                        $diffMonths = (int) abs(Carbon::now()->diffInMonths($inicio));
                        if ($diffMonths === 0) {
                            $tempo = "menos de 1 mês";
                        } else {
                            $tempo = $diffMonths == 1 ? "1 mês" : "{$diffMonths} meses";
                        }
                    }
                }

                if ($f->data_nascimento) {
                    $nasc = Carbon::parse($f->data_nascimento);
                    $anos = (int) abs(round(Carbon::now()->diffInYears($nasc)));
                    $items[] = [
                        'nome' => $f->responsavel ?? $f->nome,
                        'empresa' => $f->nome,
                        'data' => $f->data_nascimento->format('Y-m-d'),
                        'tipo' => 'nascimento_franqueado',
                        'anos' => $anos,
                        'tempo_parceria' => $tempo,
                    ];
                }
                if ($f->data_inicio_parceria) {
                    $adm  = Carbon::parse($f->data_inicio_parceria);
                    $anos = (int) abs(round(Carbon::now()->diffInYears($adm)));
                    if ($anos > 0) {
                        $items[] = [
                            'nome' => $f->responsavel ?? $f->nome,
                            'empresa' => $f->nome,
                            'data' => $f->data_inicio_parceria->format('Y-m-d'),
                            'tipo' => 'parceria_franqueado',
                            'anos' => $anos,
                            'tempo_parceria' => $tempo,
                        ];
                    }
                }
            }
        }

        // 2. Determine active collaborators query based on user profile
        $query = EmpresaColaborador::with('empresa:id,nome_fantasia')
            ->where('status', 'ativo');

        if ($role === 'empresa') {
            // Find company id
            $empresa = Empresa::where('user_id', $user->id)->first();
            if (!$empresa) {
                return response()->json(['data' => $items]);
            }
            $query->where('empresa_id', $empresa->id);

        } elseif ($role === 'franqueado' || $role === 'franquia') {
            // Find franchise id
            $franquia = Franquia::where('user_id', $user->id)->first();
            if (!$franquia) {
                return response()->json(['data' => $items]);
            }
            // Filter by companies belonging to this franchise
            $empresaIds = Empresa::where('franquia_id', $franquia->id)->pluck('id');
            $query->whereIn('empresa_id', $empresaIds);
        }

        $colaboradores = $query->get();

        foreach ($colaboradores as $c) {
            if ($c->data_nascimento) {
                $nasc = Carbon::parse($c->data_nascimento);
                $anos = (int) abs(round(Carbon::now()->diffInYears($nasc)));
                $items[] = [
                    'nome' => $c->nome_completo,
                    'empresa' => $c->empresa?->nome_fantasia ?? 'Empresa',
                    'data' => $c->data_nascimento->format('Y-m-d'),
                    'tipo' => 'nascimento',
                    'anos' => $anos,
                ];
            }
            if ($c->data_admissao) {
                $adm  = Carbon::parse($c->data_admissao);
                $anos = (int) abs(round(Carbon::now()->diffInYears($adm)));
                if ($anos > 0) {
                    $items[] = [
                        'nome' => $c->nome_completo,
                        'empresa' => $c->empresa?->nome_fantasia ?? 'Empresa',
                        'data' => $c->data_admissao->format('Y-m-d'),
                        'tipo' => 'empresa',
                        'anos' => $anos,
                    ];
                }
            }
        }

        return response()->json(['data' => $items]);
    }
}
