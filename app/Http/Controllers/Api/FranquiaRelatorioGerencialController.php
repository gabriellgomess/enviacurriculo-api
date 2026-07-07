<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HasTokenContext;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDisc;
use App\Models\CandidatoParecer;
use App\Models\EadCurso;
use App\Models\EadProgresso;
use App\Models\Empresa;
use App\Models\EmpresaArquivo;
use App\Models\Envio;
use App\Models\Franquia;
use App\Models\FranquiaChamado;
use App\Models\FranquiaContaPagar;
use App\Models\FranquiaContaReceber;
use App\Models\FranquiaFornecedor;
use App\Models\FranquiaOnboardingItem;
use App\Models\FranquiaOnboardingProgresso;
use App\Models\Parceiro;
use App\Models\Vaga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FranquiaRelatorioGerencialController extends Controller
{
    use HasTokenContext;

    private const MOEDA = ['minimumFractionDigits' => 2];

    private function fmt(?float $v): string
    {
        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    private function fmtDate($d): string
    {
        return $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—';
    }

    // GET /franquia/relatorios-gerenciais/{tipo}
    public function show(Request $request, string $tipo)
    {
        $franquiaId = $this->tokenContextId($request);

        $rows = match ($tipo) {
            'vagas_abertas'               => $this->vagas($franquiaId, 'publicada'),
            'vagas_fechadas'              => $this->vagas($franquiaId, 'fechada'),
            'vagas_todas'                 => $this->vagas($franquiaId, null),
            'vagas_por_empresa'           => $this->vagasPorEmpresa($franquiaId),
            'vagas_por_cargo'             => $this->vagasPorCargo($franquiaId),
            'candidatos_vinculados'       => $this->candidatosVinculados($franquiaId),
            'curriculos_recebidos'        => $this->curriculosRecebidos($franquiaId, null),
            'curriculos_nao_visualizados' => $this->curriculosRecebidos($franquiaId, false),
            'curriculos_por_origem'       => $this->curriculosPorOrigem($franquiaId),
            'pareceres'                   => $this->pareceres($franquiaId, null),
            'pareceres_aprovados'         => $this->pareceres($franquiaId, 'aprovado'),
            'pareceres_reprovados'        => $this->pareceres($franquiaId, 'reprovado'),
            'empresas_ativas'             => $this->empresas($franquiaId, true),
            'empresas_inativas'           => $this->empresas($franquiaId, false),
            'empresas_por_plano'          => $this->empresasPorPlano($franquiaId),
            'faturamento_mensal'          => $this->faturamento($franquiaId, null),
            'faturamento_por_empresa'     => $this->faturamentoPorEmpresa($franquiaId),
            'faturamento_pagos'           => $this->faturamento($franquiaId, 'pago'),
            'faturamento_pendentes'       => $this->faturamentoPendentes($franquiaId),
            'contas_pagar'                => $this->contasPagar($franquiaId, false),
            'contas_receber'              => $this->contasReceber($franquiaId),
            'contas_pagar_vencidas'       => $this->contasPagar($franquiaId, true),
            'resumo_financeiro'           => $this->resumoFinanceiro($franquiaId),
            'chamados'                    => $this->chamados($franquiaId, null),
            'chamados_abertos'            => $this->chamados($franquiaId, 'aberto'),
            'chamados_finalizados'        => $this->chamados($franquiaId, 'fechado'),
            'parceiros'                   => $this->parceiros(),
            'fornecedores'                => $this->fornecedores($franquiaId),
            'ead_cursos'                  => $this->eadCursos(),
            'ead_progresso'               => $this->eadProgresso($franquiaId),
            'disc_resultados'             => $this->discResultados($franquiaId),
            'onboarding_status'           => $this->onboardingStatus($franquiaId),
            'desempenho'                  => $this->desempenho($franquiaId),
            'ranking_empresas'            => $this->rankingEmpresas($franquiaId),
            'documentos_empresas'         => $this->documentosEmpresas($franquiaId),
            default                       => null,
        };

        if ($rows === null) {
            return response()->json(['message' => 'Relatório não disponível.'], 404);
        }

        return response()->json(['data' => $rows]);
    }

    private function empresaIds(int $franquiaId)
    {
        return Empresa::where('franquia_id', $franquiaId)->pluck('id');
    }

    private function vagaIds(int $franquiaId)
    {
        return Vaga::whereIn('empresa_id', $this->empresaIds($franquiaId))->pluck('id');
    }

    /* ─── Vagas ─── */

    private function vagas(int $franquiaId, ?string $status): array
    {
        $q = Vaga::with('empresa:id,razao_social')->whereIn('empresa_id', $this->empresaIds($franquiaId));
        if ($status) $q->where('status', $status);

        return $q->orderByDesc('created_at')->get()->map(fn($v) => [
            'Cargo'          => $v->titulo,
            'Empresa'        => $v->empresa?->razao_social ?? '—',
            'Salário'        => $v->salario_min ? $this->fmt($v->salario_min) : '—',
            'Status'         => ucfirst($v->status),
            'Data Criação'   => $this->fmtDate($v->created_at),
        ])->toArray();
    }

    private function vagasPorEmpresa(int $franquiaId): array
    {
        return Empresa::where('franquia_id', $franquiaId)->withCount([
            'vagas as total', 'vagas as abertas' => fn($q) => $q->where('status', 'publicada'),
            'vagas as fechadas' => fn($q) => $q->where('status', 'fechada'),
        ])->get()->map(fn($e) => [
            'Empresa'      => $e->razao_social,
            'Total Vagas'  => $e->total,
            'Abertas'      => $e->abertas,
            'Fechadas'     => $e->fechadas,
        ])->toArray();
    }

    private function vagasPorCargo(int $franquiaId): array
    {
        return Vaga::whereIn('empresa_id', $this->empresaIds($franquiaId))
            ->selectRaw('titulo, count(*) as total, sum(status = "publicada") as abertas, sum(status = "fechada") as fechadas')
            ->groupBy('titulo')
            ->orderByDesc('total')
            ->get()->map(fn($v) => [
                'Cargo'        => $v->titulo,
                'Total Vagas'  => (int) $v->total,
                'Abertas'      => (int) $v->abertas,
                'Fechadas'     => (int) $v->fechadas,
            ])->toArray();
    }

    /* ─── Candidatos ─── */

    private function candidatosVinculados(int $franquiaId): array
    {
        return Envio::with(['candidato.user:id,name', 'vaga:id,titulo,empresa_id', 'vaga.empresa:id,razao_social'])
            ->whereIn('vaga_id', $this->vagaIds($franquiaId))
            ->orderByDesc('created_at')
            ->get()->map(fn($e) => [
                'Candidato' => $e->candidato?->user?->name ?? '—',
                'Vaga'      => $e->vaga?->titulo ?? '—',
                'Empresa'   => $e->vaga?->empresa?->razao_social ?? '—',
                'Status'    => ucfirst(str_replace('_', ' ', $e->status)),
                'Data'      => $this->fmtDate($e->created_at),
            ])->toArray();
    }

    private function curriculosRecebidos(int $franquiaId, ?bool $visualizado): array
    {
        $q = Envio::with(['candidato.user:id,name', 'vaga:id,titulo,empresa_id', 'vaga.empresa:id,razao_social'])
            ->whereIn('vaga_id', $this->vagaIds($franquiaId));
        if ($visualizado === false) $q->whereNull('visualizado_em');

        return $q->orderByDesc('created_at')->get()->map(function ($e) use ($visualizado) {
            $row = [
                'Candidato' => $e->candidato?->user?->name ?? '—',
                'Empresa'   => $e->vaga?->empresa?->razao_social ?? '—',
                'Origem'    => ucfirst($e->origem ?? 'plataforma'),
            ];
            if ($visualizado === null) $row['Visualizado'] = $e->visualizado_em ? 'Sim' : 'Não';
            $row['Data'] = $this->fmtDate($e->created_at);
            return $row;
        })->toArray();
    }

    private function curriculosPorOrigem(int $franquiaId): array
    {
        return Envio::whereIn('vaga_id', $this->vagaIds($franquiaId))
            ->selectRaw('COALESCE(origem, "plataforma") as origem, count(*) as total')
            ->groupBy('origem')
            ->orderByDesc('total')
            ->get()->map(fn($r) => ['Origem' => ucfirst($r->origem), 'Quantidade' => (int) $r->total])->toArray();
    }

    private function pareceres(int $franquiaId, ?string $status): array
    {
        $q = CandidatoParecer::with(['candidato.user:id,name', 'criador:id,name', 'empresa:id,razao_social', 'vaga.empresa:id,razao_social'])
            ->where('franquia_id', $franquiaId);
        if ($status) $q->where('status_aprovacao', $status);

        return $q->orderByDesc('created_at')->get()->map(function ($p) use ($status) {
            $row = [
                'Candidato'  => $p->candidato?->user?->name ?? '—',
                'Empresa'    => $p->empresa?->razao_social ?? $p->vaga?->empresa?->razao_social ?? '—',
                'Consultor'  => $p->criador?->name ?? '—',
            ];
            if (!$status) $row['Status'] = ucfirst($p->status_aprovacao ?? 'pendente');
            $row['Data'] = $this->fmtDate($p->created_at);
            return $row;
        })->toArray();
    }

    /* ─── Empresas ─── */

    private function empresas(int $franquiaId, bool $ativa): array
    {
        return Empresa::where('franquia_id', $franquiaId)->where('active', $ativa)
            ->orderBy('razao_social')
            ->get()->map(fn($e) => [
                'Empresa' => $e->razao_social,
                'CNPJ'    => $e->cnpj ?? '—',
                'Cidade'  => $e->cidade ?? '—',
                'Status'  => $ativa ? 'Ativo' : 'Inativo',
                'Plano'   => $e->plano ? ucfirst($e->plano) : '—',
            ])->toArray();
    }

    private function empresasPorPlano(int $franquiaId): array
    {
        return Empresa::where('franquia_id', $franquiaId)
            ->selectRaw('COALESCE(plano, "não definido") as plano, count(*) as total')
            ->groupBy('plano')
            ->get()->map(fn($r) => ['Plano' => ucfirst($r->plano), 'Quantidade' => (int) $r->total])->toArray();
    }

    /* ─── Financeiro ─── */

    private function faturamento(int $franquiaId, ?string $status): array
    {
        $q = FranquiaContaReceber::where('franquia_id', $franquiaId);
        if ($status) $q->where('status', $status);

        return $q->orderByDesc('created_at')->get()->map(function ($c) use ($status) {
            $row = [
                'Empresa'    => $c->empresa_nome ?? '—',
                'Candidato'  => $c->candidato_nome ?? '—',
                'Valor Bruto'=> $this->fmt($c->valor_bruto),
                'Comissão'   => $this->fmt($c->comissao_valor),
                'Líquido'    => $this->fmt($c->valor_liquido),
            ];
            if (!$status) $row['Status'] = ucfirst($c->status);
            $row['Data'] = $this->fmtDate($c->data_faturamento ?? $c->created_at);
            return $row;
        })->toArray();
    }

    private function faturamentoPendentes(int $franquiaId): array
    {
        return FranquiaContaReceber::where('franquia_id', $franquiaId)->where('status', 'pendente')
            ->orderBy('data_vencimento')
            ->get()->map(fn($c) => [
                'Empresa'    => $c->empresa_nome ?? '—',
                'Candidato'  => $c->candidato_nome ?? '—',
                'Valor Bruto'=> $this->fmt($c->valor_bruto),
                'Comissão'   => $this->fmt($c->comissao_valor),
                'Vencimento' => $this->fmtDate($c->data_vencimento),
            ])->toArray();
    }

    private function faturamentoPorEmpresa(int $franquiaId): array
    {
        return FranquiaContaReceber::where('franquia_id', $franquiaId)
            ->selectRaw('empresa_nome, count(*) as qtd, sum(valor_bruto) as bruto, sum(comissao_valor) as comissao, sum(valor_liquido) as liquido')
            ->groupBy('empresa_nome')
            ->orderByDesc('bruto')
            ->get()->map(fn($r) => [
                'Empresa'          => $r->empresa_nome ?? '—',
                'Qtd Faturamentos' => (int) $r->qtd,
                'Total Bruto'      => $this->fmt($r->bruto),
                'Total Comissão'   => $this->fmt($r->comissao),
                'Total Líquido'    => $this->fmt($r->liquido),
            ])->toArray();
    }

    private function contasPagar(int $franquiaId, bool $somenteVencidas): array
    {
        $q = FranquiaContaPagar::where('franquia_id', $franquiaId);
        if ($somenteVencidas) {
            $q->where('status', '!=', 'pago')->where('data_vencimento', '<', now());
        }

        return $q->orderBy('data_vencimento')->get()->map(function ($c) use ($somenteVencidas) {
            $row = [
                'Descrição'  => $c->descricao,
                'Categoria'  => $c->categoria ? ucfirst($c->categoria) : '—',
                'Valor'      => $this->fmt($c->valor),
                'Vencimento' => $this->fmtDate($c->data_vencimento),
            ];
            if ($somenteVencidas) {
                $row['Dias Atraso'] = (int) now()->diffInDays($c->data_vencimento);
            } else {
                $row['Status'] = ucfirst($c->status);
            }
            return $row;
        })->toArray();
    }

    private function contasReceber(int $franquiaId): array
    {
        return FranquiaContaReceber::where('franquia_id', $franquiaId)
            ->orderBy('data_vencimento')
            ->get()->map(fn($c) => [
                'Empresa'    => $c->empresa_nome ?? '—',
                'Candidato'  => $c->candidato_nome ?? '—',
                'Valor'      => $this->fmt($c->valor_liquido),
                'Vencimento' => $this->fmtDate($c->data_vencimento),
                'Status'     => ucfirst($c->status),
            ])->toArray();
    }

    private function resumoFinanceiro(int $franquiaId): array
    {
        $receber = FranquiaContaReceber::where('franquia_id', $franquiaId);
        $pagar   = FranquiaContaPagar::where('franquia_id', $franquiaId);

        $totalFat  = (clone $receber)->count();
        $brutoFat  = (clone $receber)->sum('valor_bruto');
        $liquidoFat= (clone $receber)->sum('valor_liquido');
        $comissoes = (clone $receber)->sum('comissao_valor');
        $pagos     = (clone $receber)->where('status', 'pago')->count();
        $pendentes = (clone $receber)->where('status', 'pendente')->count();

        $totalPagar    = (clone $pagar)->sum('valor');
        $pagarPago     = (clone $pagar)->where('status', 'pago')->sum('valor');
        $pagarPendente = (clone $pagar)->where('status', '!=', 'pago')->sum('valor');

        return [
            ['Indicador' => 'Total Faturamentos',      'Valor' => (string) $totalFat],
            ['Indicador' => 'Faturamento Bruto',        'Valor' => $this->fmt($brutoFat)],
            ['Indicador' => 'Faturamento Líquido',      'Valor' => $this->fmt($liquidoFat)],
            ['Indicador' => 'Total Comissões',          'Valor' => $this->fmt($comissoes)],
            ['Indicador' => 'Faturamentos Pagos',       'Valor' => (string) $pagos],
            ['Indicador' => 'Faturamentos Pendentes',   'Valor' => (string) $pendentes],
            ['Indicador' => 'Total Contas a Pagar',     'Valor' => $this->fmt($totalPagar)],
            ['Indicador' => 'Contas Pagas',              'Valor' => $this->fmt($pagarPago)],
            ['Indicador' => 'Contas Pendentes',          'Valor' => $this->fmt($pagarPendente)],
            ['Indicador' => 'Saldo (Receber − Pagar)',   'Valor' => $this->fmt($liquidoFat - $totalPagar)],
        ];
    }

    /* ─── Chamados ─── */

    private function chamados(int $franquiaId, ?string $status): array
    {
        $q = FranquiaChamado::where('franquia_id', $franquiaId);
        if ($status === 'aberto') $q->where('status', '!=', 'fechado');
        if ($status === 'fechado') $q->where('status', 'fechado');

        return $q->orderByDesc('created_at')->get()->map(function ($c) use ($status) {
            $row = [
                'Número'  => 'CH-' . str_pad($c->id, 3, '0', STR_PAD_LEFT),
                'Assunto' => $c->titulo,
                'Tipo'    => $c->categoria ? ucfirst($c->categoria) : '—',
            ];
            if ($status === 'aberto') {
                $row['Data'] = $this->fmtDate($c->created_at);
                $row['Dias Aberto'] = (int) $c->created_at->diffInDays(now());
            } elseif ($status === 'fechado') {
                $row['Data'] = $this->fmtDate($c->created_at);
            } else {
                $row['Status'] = ucfirst($c->status);
                $row['Data'] = $this->fmtDate($c->created_at);
            }
            return $row;
        })->toArray();
    }

    /* ─── Outros ─── */

    private function parceiros(): array
    {
        return Parceiro::where('active', true)->orderBy('razao_social')
            ->get()->map(fn($p) => [
                'Nome'           => $p->razao_social,
                'Categoria'      => $p->categoria ?? '—',
                'Cidade'         => $p->cidade ?? '—',
                'Status'         => $p->active ? 'Ativo' : 'Inativo',
                'Data Cadastro'  => $this->fmtDate($p->created_at),
            ])->toArray();
    }

    private function fornecedores(int $franquiaId): array
    {
        return FranquiaFornecedor::where('franquia_id', $franquiaId)->orderBy('nome')
            ->get()->map(fn($f) => [
                'Nome'      => $f->nome,
                'CNPJ/CPF'  => $f->cnpj ?? '—',
                'Telefone'  => $f->telefone ?? '—',
                'Cidade'    => '—',
                'Status'    => $f->ativo ? 'Ativo' : 'Inativo',
            ])->toArray();
    }

    private function eadCursos(): array
    {
        return EadCurso::withCount('aulas')->get()->map(fn($c) => [
            'Curso'    => $c->titulo,
            'Módulos'  => $c->aulas()->distinct('modulo')->count('modulo'),
            'Aulas'    => $c->aulas_count,
            'Status'   => $c->active ? 'Ativo' : 'Inativo',
        ])->toArray();
    }

    private function eadProgresso(int $franquiaId): array
    {
        $cursos  = EadCurso::where('active', true)->with('aulas:id,curso_id')->get();
        $aulaIds = $cursos->flatMap(fn($c) => $c->aulas->pluck('id'));
        $feitas  = EadProgresso::where('franquia_id', $franquiaId)->whereIn('aula_id', $aulaIds)
            ->where('concluida', true)->pluck('aula_id');

        return $cursos->map(function ($c) use ($feitas) {
            $total = $c->aulas->count();
            $concluidas = $c->aulas->filter(fn($a) => $feitas->contains($a->id))->count();
            return [
                'Curso'            => $c->titulo,
                'Aulas Totais'     => $total,
                'Aulas Concluídas' => $concluidas,
                '% Concluído'      => $total > 0 ? round($concluidas / $total * 100) . '%' : '0%',
            ];
        })->toArray();
    }

    private function discResultados(int $franquiaId): array
    {
        $candidatoIds = Candidato::where(function ($q) use ($franquiaId) {
            $q->whereHas('envios', fn($s) => $s->whereIn('vaga_id', $this->vagaIds($franquiaId)))
              ->orWhere('franquia_id', $franquiaId)
              ->orWhereNull('franquia_id');
        })->pluck('id');

        return CandidatoDisc::whereIn('candidato_id', $candidatoIds)
            ->with('candidato.user:id,name')
            ->orderByDesc('created_at')
            ->get()->map(fn($d) => [
                'Candidato'         => $d->candidato?->user?->name ?? '—',
                'Perfil Dominante'  => $d->perfil_dominante,
                'D' => $d->score_d, 'I' => $d->score_i, 'S' => $d->score_s, 'C' => $d->score_c,
                'Data' => $this->fmtDate($d->created_at),
            ])->toArray();
    }

    private function onboardingStatus(int $franquiaId): array
    {
        $itens     = FranquiaOnboardingItem::where('active', true)->orderBy('ordem')->get();
        $progresso = FranquiaOnboardingProgresso::where('franquia_id', $franquiaId)->pluck('concluido_em', 'item_id');

        return $itens->map(fn($i) => [
            'Etapa'      => $i->titulo,
            'Status'     => $progresso->has($i->id) ? 'Concluído' : 'Pendente',
            'Conclusão'  => $progresso->has($i->id) ? $this->fmtDate($progresso[$i->id]) : '—',
        ])->toArray();
    }

    private function desempenho(int $franquiaId): array
    {
        $empresaIds = $this->empresaIds($franquiaId);
        $vagaIds    = $this->vagaIds($franquiaId);

        return [
            ['Indicador' => 'Empresas Ativas',            'Valor' => (string) Empresa::where('franquia_id', $franquiaId)->where('active', true)->count()],
            ['Indicador' => 'Empresas Inativas',          'Valor' => (string) Empresa::where('franquia_id', $franquiaId)->where('active', false)->count()],
            ['Indicador' => 'Total de Vagas',             'Valor' => (string) $vagaIds->count()],
            ['Indicador' => 'Vagas Abertas',               'Valor' => (string) Vaga::whereIn('empresa_id', $empresaIds)->where('status', 'publicada')->count()],
            ['Indicador' => 'Vagas Fechadas',              'Valor' => (string) Vaga::whereIn('empresa_id', $empresaIds)->where('status', 'fechada')->count()],
            ['Indicador' => 'Currículos Recebidos',        'Valor' => (string) Envio::whereIn('vaga_id', $vagaIds)->count()],
            ['Indicador' => 'Faturamentos Realizados',     'Valor' => (string) FranquiaContaReceber::where('franquia_id', $franquiaId)->count()],
            ['Indicador' => 'Faturamento Bruto Total',     'Valor' => $this->fmt(FranquiaContaReceber::where('franquia_id', $franquiaId)->sum('valor_bruto'))],
            ['Indicador' => 'Faturamento Líquido Total',   'Valor' => $this->fmt(FranquiaContaReceber::where('franquia_id', $franquiaId)->sum('valor_liquido'))],
            ['Indicador' => 'Total Chamados',              'Valor' => (string) FranquiaChamado::where('franquia_id', $franquiaId)->count()],
            ['Indicador' => 'Chamados Abertos',            'Valor' => (string) FranquiaChamado::where('franquia_id', $franquiaId)->where('status', '!=', 'fechado')->count()],
        ];
    }

    private function rankingEmpresas(int $franquiaId): array
    {
        return Empresa::where('franquia_id', $franquiaId)
            ->withCount([
                'vagas as abertas'  => fn($q) => $q->where('status', 'publicada'),
                'vagas as fechadas' => fn($q) => $q->where('status', 'fechada'),
            ])
            ->get()
            ->map(function ($e) {
                $vagaIds = Vaga::where('empresa_id', $e->id)->pluck('id');
                return [
                    'Empresa'               => $e->razao_social,
                    'Vagas Abertas'         => $e->abertas,
                    'Vagas Fechadas'        => $e->fechadas,
                    'Currículos Recebidos'  => Envio::whereIn('vaga_id', $vagaIds)->count(),
                ];
            })
            ->sortByDesc('Currículos Recebidos')
            ->values()->toArray();
    }

    private function documentosEmpresas(int $franquiaId): array
    {
        $empresaIds = $this->empresaIds($franquiaId);

        return EmpresaArquivo::whereIn('empresa_id', $empresaIds)
            ->with('empresa:id,razao_social')
            ->orderByDesc('created_at')
            ->get()->map(fn($d) => [
                'Empresa'   => $d->empresa?->razao_social ?? '—',
                'Documento' => $d->titulo ?? $d->arquivo_nome,
                'Arquivo'   => $d->arquivo_nome,
                'Data'      => $this->fmtDate($d->created_at),
            ])->toArray();
    }
}
