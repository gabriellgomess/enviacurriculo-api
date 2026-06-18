<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\EmpresaColaborador;
use Illuminate\Http\Request;

class EmpresaColaboradorController extends Controller
{
    use HasTokenContext;

    private const STATUSES = ['ativo', 'ferias', 'afastado', 'desligado'];

    private function rules(): array
    {
        return [
            'nome_completo'   => 'required|string|max:255',
            'cpf'             => 'nullable|string|max:20',
            'cargo'           => 'nullable|string|max:255',
            'departamento'    => 'nullable|string|max:255',
            'email'           => 'nullable|email|max:255',
            'telefone'        => 'nullable|string|max:20',
            'data_admissao'   => 'nullable|date',
            'data_nascimento' => 'nullable|date',
            'salario'         => 'nullable|numeric|min:0',
            'status'          => 'nullable|in:' . implode(',', self::STATUSES),
            'observacao'      => 'nullable|string',
        ];
    }

    // GET /empresa/colaboradores
    public function index(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $colaboradores = EmpresaColaborador::where('empresa_id', $empresaId)
            ->orderBy('nome_completo')
            ->get();

        return response()->json(['data' => $colaboradores]);
    }

    // POST /empresa/colaboradores
    public function store(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $data = $request->validate($this->rules());

        $colaborador = EmpresaColaborador::create(array_merge($data, [
            'empresa_id' => $empresaId,
            'status'     => $data['status'] ?? 'ativo',
        ]));

        return response()->json($colaborador, 201);
    }

    // PUT /empresa/colaboradores/{id}
    public function update(Request $request, int $id)
    {
        $empresaId   = $this->tokenContextId($request);
        $colaborador = EmpresaColaborador::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate($this->rules());
        $colaborador->update($data);

        return response()->json($colaborador);
    }

    // PATCH /empresa/colaboradores/{id}/status
    public function updateStatus(Request $request, int $id)
    {
        $empresaId   = $this->tokenContextId($request);
        $colaborador = EmpresaColaborador::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:' . implode(',', self::STATUSES),
        ]);
        $colaborador->update(['status' => $data['status']]);

        return response()->json($colaborador);
    }

    // DELETE /empresa/colaboradores/{id}
    public function destroy(Request $request, int $id)
    {
        $empresaId   = $this->tokenContextId($request);
        $colaborador = EmpresaColaborador::where('empresa_id', $empresaId)->findOrFail($id);
        $colaborador->delete();

        return response()->noContent();
    }

    // POST /empresa/colaboradores/importar
    public function importar(Request $request)
    {
        $empresaId = $this->tokenContextId($request);

        $request->validate([
            'colaboradores'                 => 'required|array|min:1',
            'colaboradores.*.nome_completo' => 'required|string|max:255',
        ]);

        $allowed = ['nome_completo', 'cpf', 'cargo', 'departamento', 'email', 'telefone', 'data_admissao', 'data_nascimento', 'salario', 'status', 'observacao'];

        $criados = 0;
        foreach ($request->input('colaboradores') as $row) {
            $payload = collect($row)
                ->only($allowed)
                ->map(fn($v) => $v === '' ? null : $v)
                ->toArray();

            if (empty($payload['nome_completo'])) {
                continue;
            }

            if (!empty($payload['salario'])) {
                $payload['salario'] = (float) str_replace(['.', ','], ['', '.'], (string) $payload['salario']);
            }

            if (!in_array(($payload['status'] ?? null), self::STATUSES, true)) {
                $payload['status'] = 'ativo';
            }

            EmpresaColaborador::create(array_merge($payload, ['empresa_id' => $empresaId]));
            $criados++;
        }

        return response()->json(['message' => "{$criados} colaborador(es) importado(s).", 'criados' => $criados], 201);
    }

    // GET /empresa/colaboradores/aniversariantes?mes={mes}
    public function aniversariantes(Request $request)
    {
        $empresaId = $this->tokenContextId($request);
        $mes = (int) ($request->query('mes') ?: now()->month);

        $colaboradores = EmpresaColaborador::where('empresa_id', $empresaId)
            ->whereNotNull('data_nascimento')
            ->whereMonth('data_nascimento', $mes)
            ->orderByRaw('DAY(data_nascimento)')
            ->get();

        return response()->json(['data' => $colaboradores]);
    }
}
