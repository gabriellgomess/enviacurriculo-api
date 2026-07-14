<?php

use App\Http\Controllers\Api\AdminChamadoController;
use App\Http\Controllers\Api\AdminFinanceiroController;
use App\Http\Controllers\Api\CnpjController;
use App\Http\Controllers\Api\AdminGestaoFranquiasController;
use App\Http\Controllers\Api\AdminNotaFiscalController;
use App\Http\Controllers\Api\AdminPermissionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\CandidatoController;
use App\Http\Controllers\Api\CandidatoCreditoController;
use App\Http\Controllers\Api\CandidatoCurriculoController;
use App\Http\Controllers\Api\CandidatoEmpresaController;
use App\Http\Controllers\Api\CandidatoEnvioController;
use App\Http\Controllers\Api\CandidatoParceiroController;
use App\Http\Controllers\Api\CandidatoPerfilController;
use App\Http\Controllers\Api\CandidatoVagaController;
use App\Http\Controllers\Api\ComunidadeController;
use App\Http\Controllers\Api\EmpresaDashboardController;
use App\Http\Controllers\Api\EmpresaParceiroController;
use App\Http\Controllers\Api\EmpresaPerfilController;
use App\Http\Controllers\Api\FranquiaAgendaController;
use App\Http\Controllers\Api\FranquiaBibliotecaController;
use App\Http\Controllers\Api\FranquiaCadastroController;
use App\Http\Controllers\Api\FranquiaCandidatoController;
use App\Http\Controllers\Api\FranquiaChamadoController;
use App\Http\Controllers\Api\FranquiaComunidadeController;
use App\Http\Controllers\Api\FranquiaDashboardController;
use App\Http\Controllers\Api\FranquiaEmpresaGestaoController;
use App\Http\Controllers\Api\FranquiaFinanceiroController;
use App\Http\Controllers\Api\FranquiaMapaController;
use App\Http\Controllers\Api\FranquiaParceiroGestaoController;
use App\Http\Controllers\Api\FranquiaPerfilController;
use App\Http\Controllers\Api\FranquiaRelatorioController;
use App\Http\Controllers\Api\FranquiaTDController;
use App\Http\Controllers\Api\FranquiaVagaController;
use App\Http\Controllers\Api\EmpresaController;
use App\Http\Controllers\Api\FranquiaController;
use App\Http\Controllers\Api\FranquiaDocumentoController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\AdminParceiroController;
use App\Http\Controllers\Api\MapaController;
use App\Http\Controllers\Api\ParceiroAgendaController;
use App\Http\Controllers\Api\ParceiroDashboardController;
use App\Http\Controllers\Api\ParceiroPerfilController;
use App\Http\Controllers\Api\ParceiroServicoController;
use App\Http\Controllers\Api\ParceiroTarefaController;
use App\Http\Controllers\Api\RegisterCandidatoController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VagaController;
use App\Http\Controllers\Api\AdminEadController;
use App\Http\Controllers\Api\AdminParecerController;
use App\Http\Controllers\Api\AdminDiscController;
use App\Http\Controllers\Api\AdminChamadosTiposController;
use App\Http\Controllers\Api\AdminParceiroCategoriaController;
use App\Http\Controllers\Api\AdminManualController;
use App\Http\Controllers\Api\AdminCadastroController;
use App\Http\Controllers\Api\AdminBeneficiosController;
use App\Http\Controllers\Api\AdminTipoKanbanController;
use App\Http\Controllers\Api\AdminEtapasKanbanController;
use App\Http\Controllers\Api\AdminNivelVagaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas públicas — site / hub
|--------------------------------------------------------------------------
*/
Route::get('stats', [StatsController::class, 'index']);
Route::post('contatos', [StatsController::class, 'contato']);

// Geocoding — público, usado por todos os painéis
Route::prefix('geocode')->group(function () {
    Route::get('cep',     [GeocodeController::class, 'cep']);
    Route::get('address', [GeocodeController::class, 'address']);
    Route::get('users', [UserController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Rotas públicas — autenticação
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('login/context', [AuthController::class, 'selectContext']);
    Route::post('register', RegisterCandidatoController::class);

    // Recuperação de senha
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:6,1');
    Route::post('reset-password',  [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');
});

// Cadastro público de empresas
Route::post('empresas/cadastrar', \App\Http\Controllers\Api\RegisterEmpresaController::class)
    ->middleware('throttle:10,1');

// Consulta de CNPJ — pública, com cache de 24h no backend
Route::get('cnpj/{cnpj}', [CnpjController::class, 'show'])->middleware('throttle:30,1');

// Lead público "Seja Franqueado" (home)
Route::get('franquias-publicas', [\App\Http\Controllers\Api\FranquiaLeadController::class, 'publicas']);
Route::post('franquia-leads', [\App\Http\Controllers\Api\FranquiaLeadController::class, 'store'])
    ->middleware('throttle:10,1');

// Lead externo — webhook do formulário do cliente (campanhas de tráfego pago)
Route::post('leads-externos', [\App\Http\Controllers\Api\FranquiaLeadController::class, 'storeExterno'])
    ->middleware('throttle:30,1');

// Unsubscribe de e-mails por token
Route::get('unsubscribe',  [\App\Http\Controllers\Api\UnsubscribeController::class, 'show'])
    ->middleware('throttle:30,1');
Route::post('unsubscribe', [\App\Http\Controllers\Api\UnsubscribeController::class, 'store'])
    ->middleware('throttle:10,1');

// Teste DISC público por token
Route::get('disc-teste/{token}',            [\App\Http\Controllers\Api\DiscPublicoController::class, 'show'])
    ->middleware('throttle:30,1');
Route::post('disc-teste/{token}/responder', [\App\Http\Controllers\Api\DiscPublicoController::class, 'responder'])
    ->middleware('throttle:10,1');

// Cadastro público de parceiros
Route::prefix('parceiro/cadastro')->group(function () {
    Route::post('pagamento', [\App\Http\Controllers\Api\ParceiroCadastroController::class, 'gerarPagamento']);
    Route::get('pagamento/{payment_id}/status', [\App\Http\Controllers\Api\ParceiroCadastroController::class, 'statusPagamento']);
    Route::post('/', [\App\Http\Controllers\Api\ParceiroCadastroController::class, 'store']);
});

// Webhook do gateway de pagamento Asaas (créditos do candidato) — ver ASAAS_SETUP.md
Route::post('webhooks/asaas', [\App\Http\Controllers\Api\AsaasWebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Rotas protegidas — requerem token Sanctum
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('auth/contextos/{role}', [AuthController::class, 'listContextos']);
    Route::post('auth/impersonate', [AuthController::class, 'impersonate']);

    // Agenda (pessoal e compartilhada)
    Route::get('agenda/tarefas',                [App\Http\Controllers\Api\AgendaController::class, 'index']);
    Route::post('agenda/tarefas',               [App\Http\Controllers\Api\AgendaController::class, 'store']);
    Route::patch('agenda/tarefas/{id}/toggle',  [App\Http\Controllers\Api\AgendaController::class, 'toggle']);
    Route::delete('agenda/tarefas/{id}',        [App\Http\Controllers\Api\AgendaController::class, 'destroy']);
    Route::get('agenda/aniversarios',           [App\Http\Controllers\Api\AgendaController::class, 'aniversarios']);
    Route::get('tipos-contrato',                [\App\Http\Controllers\Api\AdminCadastroController::class, 'indexTiposContrato']);

    /*
    |--------------------------------------------------------------------------
    | Comunidade (compartilhada — qualquer autenticado: admin, franquia, etc)
    |--------------------------------------------------------------------------
    */
    Route::prefix('comunidade')->group(function () {
        Route::get('posts',                          [ComunidadeController::class, 'index']);
        Route::post('posts',                         [ComunidadeController::class, 'store']);
        Route::delete('posts/{id}',                  [ComunidadeController::class, 'destroy']);
        Route::post('posts/{id}/reagir',             [ComunidadeController::class, 'toggleReacao']);
        Route::post('posts/{id}/comentarios',        [ComunidadeController::class, 'comentar']);
        Route::delete('comentarios/{id}',            [ComunidadeController::class, 'destroyComentario']);
    });

    /*
    |--------------------------------------------------------------------------
    | Painel Admin
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('mapa', [MapaController::class, 'index']);

        Route::apiResource('users', UserController::class);
        Route::patch('users/{user}/toggle-active', [UserController::class, 'toggleActive']);

        // Gerenciamento de administradores (usuários + permissões)
        Route::get('admins', [AdminPermissionController::class, 'index']);
        Route::post('admins', [AdminPermissionController::class, 'store']);
        Route::put('admins/{user}', [AdminPermissionController::class, 'update']);
        Route::delete('admins/{user}', [AdminPermissionController::class, 'destroy']);

        // Franquias
        Route::get('franquias/mapa',      [FranquiaController::class, 'mapa']);
        Route::get('franquias/relatorio', [FranquiaController::class, 'relatorio']);
        Route::apiResource('franquias', FranquiaController::class);
        Route::patch('franquias/{franquia}/toggle-active', [FranquiaController::class, 'toggleActive']);

        // Documentos das franquias
        Route::get('franquias/{franquia}/documentos', [FranquiaDocumentoController::class, 'index']);
        Route::post('franquias/{franquia}/documentos', [FranquiaDocumentoController::class, 'store']);
        Route::delete('franquias/{franquia}/documentos/{documento}', [FranquiaDocumentoController::class, 'destroy']);
        Route::get('franquias/{franquia}/documentos/{documento}/download', [FranquiaDocumentoController::class, 'download']);

        // Empresas
        Route::get('empresas/relatorios',          [EmpresaController::class, 'relatorios']);
        Route::get('empresas/niveis-vagas',        [EmpresaController::class, 'niveisVagas']);
        Route::get('empresas/beneficios-catalogo', [EmpresaController::class, 'beneficiosCatalogo']);
        Route::apiResource('empresas',             EmpresaController::class);
        Route::patch('empresas/{empresa}/status', [EmpresaController::class, 'changeStatus']);
        // Follow-ups
        Route::post('empresas/{empresa}/followups', [EmpresaController::class, 'storeFollowup']);
        Route::put('empresas/{empresa}/followups/{followup}', [EmpresaController::class, 'updateFollowup']);
        Route::delete('empresas/{empresa}/followups/{followup}', [EmpresaController::class, 'destroyFollowup']);
        // Taxas de serviço
        Route::get('empresas/{empresa}/taxas', [EmpresaController::class, 'indexTaxas']);
        Route::post('empresas/{empresa}/taxas', [EmpresaController::class, 'upsertTaxa']);
        Route::delete('empresas/{empresa}/taxas/{taxa}', [EmpresaController::class, 'destroyTaxa']);
        // Benefícios da empresa
        Route::get('empresas/{empresa}/beneficios', [EmpresaController::class, 'indexBeneficios']);
        Route::post('empresas/{empresa}/beneficios', [EmpresaController::class, 'syncBeneficios']);

        // Vagas
        Route::apiResource('vagas', VagaController::class);
        Route::patch('vagas/{vaga}/status', [VagaController::class, 'changeStatus']);
        Route::post('vagas/{vaga}/convidar', [VagaController::class, 'convidarFranquias']);
        Route::get('vagas/{id}/candidatos', [VagaController::class, 'candidatos']);

        // Parceiros - Categorias e Relatórios
        Route::get('parceiros/categorias',         [AdminParceiroCategoriaController::class, 'index']);
        Route::post('parceiros/categorias',        [AdminParceiroCategoriaController::class, 'store']);
        Route::delete('parceiros/categorias/{id}', [AdminParceiroCategoriaController::class, 'destroy']);
        Route::get('parceiros/relatorios',         [AdminParceiroController::class, 'relatorios']);

        // Parceiros Resource
        Route::apiResource('parceiros', AdminParceiroController::class);
        Route::patch('parceiros/{parceiro}/toggle-active', [AdminParceiroController::class, 'toggleActive']);

        // Chamados - Tipos e Relatórios
        Route::get('chamados/tipos',         [AdminChamadosTiposController::class, 'index']);
        Route::post('chamados/tipos',        [AdminChamadosTiposController::class, 'store']);
        Route::delete('chamados/tipos/{id}', [AdminChamadosTiposController::class, 'destroy']);
        Route::get('chamados/relatorios',    [AdminChamadoController::class, 'relatorios']);

        // Fale Conosco (Mensagens de contato do site)
        Route::get('contatos-site',                  [AdminChamadoController::class, 'indexContatos']);
        Route::patch('contatos-site/{id}/status',    [AdminChamadoController::class, 'updateContatoStatus']);
        Route::delete('contatos-site/{id}',          [AdminChamadoController::class, 'destroyContato']);

        // Chamados Resource (suporte das franquias)
        Route::get('chamados',                         [AdminChamadoController::class, 'index']);
        Route::get('chamados/{id}',                    [AdminChamadoController::class, 'show']);
        Route::post('chamados/{id}/mensagens',         [AdminChamadoController::class, 'storeMensagem']);
        Route::patch('chamados/{id}/fechar',           [AdminChamadoController::class, 'fechar']);
        Route::patch('chamados/{id}/reabrir',          [AdminChamadoController::class, 'reabrir']);

        // Currículos (Candidatos)
        Route::apiResource('candidatos', CandidatoController::class);
        Route::patch('candidatos/{candidato}/toggle-active', [CandidatoController::class, 'toggleActive']);
        Route::get('candidatos/{candidato}/documentos/{documento}/download', [CandidatoController::class, 'downloadDocumento']);
        Route::get('candidatos/{id}/pareceres', [CandidatoController::class, 'pareceres']);
        Route::post('candidatos/{id}/pareceres', [CandidatoController::class, 'storeParecer']);
        Route::put('candidatos/pareceres/{id}', [CandidatoController::class, 'updateParecer']);
        Route::delete('candidatos/pareceres/{id}', [CandidatoController::class, 'destroyParecer']);
        Route::post('candidatos/{candidato}/vincular', [CandidatoController::class, 'vincular']);
        Route::get('candidatos/{candidato}/vinculacoes', [CandidatoController::class, 'vinculacoes']);
        Route::delete('candidatos/vinculacoes/{id}', [CandidatoController::class, 'destroyVinculo']);
        Route::get('candidatos/{id}/disc', [CandidatoController::class, 'disc']);
        Route::post('candidatos/{id}/disc', [CandidatoController::class, 'storeDisc']);

        // Financeiro — configurações por tipo de franquia
        // (mensalidade, tx_royalties, tx_marketing, percentual_comissao, percentual_imposto)
        Route::prefix('financeiro')->group(function () {
            Route::get('configs/{categoria}',              [AdminFinanceiroController::class, 'indexConfigs']);
            Route::post('configs/{categoria}',             [AdminFinanceiroController::class, 'storeConfig']);
            Route::put('configs/{categoria}/{config}',     [AdminFinanceiroController::class, 'updateConfig']);
            Route::delete('configs/{categoria}/{config}',  [AdminFinanceiroController::class, 'destroyConfig']);

            // Tipos de comissão (recrutamento, parceiro, candidatos)
            Route::get('comissao-tipos',                  [AdminFinanceiroController::class, 'indexComissaoTipos']);
            Route::put('comissao-tipos/{comissaoTipo}',   [AdminFinanceiroController::class, 'updateComissaoTipo']);

            // Relatório de faturamento (consolidado de todas as franquias)
            Route::get('faturamentos', [AdminFinanceiroController::class, 'faturamentos']);

            // Contas a receber / pagar (consolidado de todas as franquias)
            Route::get('contas-receber', [AdminFinanceiroController::class, 'contasReceber']);
            Route::get('contas-pagar',   [AdminFinanceiroController::class, 'contasPagar']);
            Route::post('contas-pagar',  [AdminFinanceiroController::class, 'storeContaPagar']);
            Route::patch('contas-pagar/{id}/pagar', [AdminFinanceiroController::class, 'pagarConta']);

            // Faturamento — cobranças da franqueadora às franquias
            Route::get('franquia-faturamentos',                          [AdminFinanceiroController::class, 'indexFranquiaFaturamentos']);
            Route::post('franquia-faturamentos',                         [AdminFinanceiroController::class, 'storeFranquiaFaturamento']);
            Route::patch('franquia-faturamentos/{faturamento}/status',   [AdminFinanceiroController::class, 'updateFranquiaFaturamentoStatus']);

            // Fiscal — notas fiscais da franqueadora
            Route::get('notas',                  [AdminNotaFiscalController::class, 'index']);
            Route::post('notas',                 [AdminNotaFiscalController::class, 'store']);
            Route::post('notas/{nota}',          [AdminNotaFiscalController::class, 'update']); // POST p/ multipart
            Route::delete('notas/{nota}',        [AdminNotaFiscalController::class, 'destroy']);
            Route::get('notas/{nota}/download',  [AdminNotaFiscalController::class, 'download']);
        });

        // EAD
        Route::get('ead/cursos',             [AdminEadController::class, 'indexCursos']);
        Route::post('ead/cursos',            [AdminEadController::class, 'storeCurso']);
        Route::put('ead/cursos/{id}',        [AdminEadController::class, 'updateCurso']);
        Route::delete('ead/cursos/{id}',     [AdminEadController::class, 'destroyCurso']);
        Route::get('ead/cursos/{id}/aulas',  [AdminEadController::class, 'indexAulas']);
        Route::post('ead/cursos/{id}/aulas', [AdminEadController::class, 'storeAula']);
        Route::put('ead/aulas/{id}',         [AdminEadController::class, 'updateAula']);
        Route::delete('ead/aulas/{id}',      [AdminEadController::class, 'destroyAula']);
        Route::post('ead/cursos/{cursoId}/provas', [AdminEadController::class, 'storeProva']);
        Route::delete('ead/provas/{id}',           [AdminEadController::class, 'destroyProva']);

        // Pareceres
        Route::get('pareceres',              [AdminParecerController::class, 'index']);
        Route::delete('pareceres/{id}',      [AdminParecerController::class, 'destroy']);

        // Teste DISC
        Route::get('disc',                   [AdminDiscController::class, 'index']);
        Route::post('disc',                  [AdminDiscController::class, 'store']);

        // Biblioteca (Manuais)
        Route::get('manuais',                      [AdminManualController::class, 'index']);
        Route::post('manuais',                     [AdminManualController::class, 'store']);
        Route::put('manuais/{id}',                 [AdminManualController::class, 'update']);
        Route::delete('manuais/{id}',              [AdminManualController::class, 'destroy']);
        Route::patch('manuais/{id}/toggle',        [AdminManualController::class, 'toggleActive']);

        // Cadastro (Serviços e Fornecedores)
        Route::get('cadastro/servicos',             [AdminCadastroController::class, 'indexServicos']);
        Route::post('cadastro/servicos',            [AdminCadastroController::class, 'storeServico']);
        Route::put('cadastro/servicos/{id}',        [AdminCadastroController::class, 'updateServico']);
        Route::delete('cadastro/servicos/{id}',     [AdminCadastroController::class, 'destroyServico']);
        Route::get('cadastro/fornecedores',         [AdminCadastroController::class, 'indexFornecedores']);
        Route::post('cadastro/fornecedores',        [AdminCadastroController::class, 'storeFornecedor']);
        Route::put('cadastro/fornecedores/{id}',    [AdminCadastroController::class, 'updateFornecedor']);
        Route::delete('cadastro/fornecedores/{id}', [AdminCadastroController::class, 'destroyFornecedor']);
        Route::get('cadastro/tipos-contrato',        [AdminCadastroController::class, 'indexTiposContrato']);
        Route::post('cadastro/tipos-contrato',       [AdminCadastroController::class, 'storeTipoContrato']);
        Route::put('cadastro/tipos-contrato/{id}',   [AdminCadastroController::class, 'updateTipoContrato']);
        Route::delete('cadastro/tipos-contrato/{id}',[AdminCadastroController::class, 'destroyTipoContrato']);

        // Configurações - Benefícios, Tipos/Etapas Kanban e Níveis de Vagas
        Route::get('configuracoes/beneficios',               [AdminBeneficiosController::class, 'index']);
        Route::post('configuracoes/beneficios',              [AdminBeneficiosController::class, 'store']);
        Route::delete('configuracoes/beneficios/{id}',       [AdminBeneficiosController::class, 'destroy']);
        Route::get('configuracoes/tipo-kanban',              [AdminTipoKanbanController::class, 'index']);
        Route::post('configuracoes/tipo-kanban',             [AdminTipoKanbanController::class, 'store']);
        Route::put('configuracoes/tipo-kanban/{id}',         [AdminTipoKanbanController::class, 'update']);
        Route::delete('configuracoes/tipo-kanban/{id}',      [AdminTipoKanbanController::class, 'destroy']);
        Route::get('configuracoes/etapas-kanban',            [AdminEtapasKanbanController::class, 'index']);
        Route::post('configuracoes/etapas-kanban',           [AdminEtapasKanbanController::class, 'store']);
        Route::put('configuracoes/etapas-kanban/{id}',       [AdminEtapasKanbanController::class, 'update']);
        Route::delete('configuracoes/etapas-kanban/{id}',    [AdminEtapasKanbanController::class, 'destroy']);
        Route::put('configuracoes/etapas-kanban/{id}/reorder', [AdminEtapasKanbanController::class, 'reorder']);
        Route::get('configuracoes/tipo-niveis-vagas',        [AdminNivelVagaController::class, 'index']);
        Route::post('configuracoes/tipo-niveis-vagas',       [AdminNivelVagaController::class, 'store']);
        Route::delete('configuracoes/tipo-niveis-vagas/{id}', [AdminNivelVagaController::class, 'destroy']);
        Route::get('configuracoes/backup',                   [App\Http\Controllers\Api\AdminBackupController::class, 'index']);
        Route::post('configuracoes/backup',                  [App\Http\Controllers\Api\AdminBackupController::class, 'store']);
        Route::post('configuracoes/backup/{id}/restore',     [App\Http\Controllers\Api\AdminBackupController::class, 'restore']);

        // Leads "Seja Franqueado"
        Route::get('leads',           [\App\Http\Controllers\Api\FranquiaLeadController::class, 'index']);
        Route::patch('leads/{lead}',  [\App\Http\Controllers\Api\FranquiaLeadController::class, 'update']);
        Route::delete('leads/{lead}', [\App\Http\Controllers\Api\FranquiaLeadController::class, 'destroy']);
        Route::post('leads/{lead}/disc-convite', [\App\Http\Controllers\Api\FranquiaLeadController::class, 'gerarDiscConvite']);
        Route::post('leads/{lead}/converter',    [\App\Http\Controllers\Api\FranquiaLeadController::class, 'converter']);

        // Gestão de franquias — metas, onboarding, vínculos e acessos
        Route::prefix('gestao')->group(function () {
            // Tipos de metas
            Route::get('tipos-metas',                [AdminGestaoFranquiasController::class, 'indexTiposMetas']);
            Route::post('tipos-metas',               [AdminGestaoFranquiasController::class, 'storeTipoMeta']);
            Route::put('tipos-metas/{tipoMeta}',     [AdminGestaoFranquiasController::class, 'updateTipoMeta']);
            Route::delete('tipos-metas/{tipoMeta}',  [AdminGestaoFranquiasController::class, 'destroyTipoMeta']);

            // Metas por franquia
            Route::get('metas',            [AdminGestaoFranquiasController::class, 'indexMetas']);
            Route::post('metas',           [AdminGestaoFranquiasController::class, 'storeMeta']);
            Route::put('metas/{meta}',     [AdminGestaoFranquiasController::class, 'updateMeta']);
            Route::delete('metas/{meta}',  [AdminGestaoFranquiasController::class, 'destroyMeta']);

            // Onboarding — itens (criação de etapas) e acompanhamento
            Route::get('onboarding/itens',           [AdminGestaoFranquiasController::class, 'indexOnboardingItens']);
            Route::post('onboarding/itens',          [AdminGestaoFranquiasController::class, 'storeOnboardingItem']);
            Route::put('onboarding/itens/{item}',    [AdminGestaoFranquiasController::class, 'updateOnboardingItem']);
            Route::delete('onboarding/itens/{item}', [AdminGestaoFranquiasController::class, 'destroyOnboardingItem']);
            Route::get('onboarding/progresso',                [AdminGestaoFranquiasController::class, 'onboardingProgresso']);
            Route::get('onboarding/progresso/{franquiaId}',   [AdminGestaoFranquiasController::class, 'onboardingProgressoFranquia']);

            // Vínculos (envios candidato→vaga por franquia)
            Route::get('vinculos', [AdminGestaoFranquiasController::class, 'vinculos']);

            // Registro de acessos
            Route::get('acessos', [AdminGestaoFranquiasController::class, 'acessos']);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Painel Empresa
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:empresa')->prefix('empresa')->group(function () {
        // Perfil
        Route::get('perfil',       [EmpresaPerfilController::class, 'show']);
        Route::put('perfil',       [EmpresaPerfilController::class, 'update']);
        Route::post('perfil/logo', [EmpresaPerfilController::class, 'uploadLogo']);
        Route::get('taxas',        [EmpresaPerfilController::class, 'taxas']);
        Route::get('documentos-agencia', [EmpresaPerfilController::class, 'documentosAgencia']);

        // Notificações
        Route::get('notificacoes',            [\App\Http\Controllers\Api\EmpresaNotificacaoController::class, 'index']);
        Route::patch('notificacoes/{id}/lida', [\App\Http\Controllers\Api\EmpresaNotificacaoController::class, 'marcarLida']);
        Route::post('notificacoes/lidas',     [\App\Http\Controllers\Api\EmpresaNotificacaoController::class, 'marcarLidas']);

        // Dashboard
        Route::get('dashboard',           [EmpresaDashboardController::class, 'index']);
        Route::get('dashboard/conversao', [EmpresaDashboardController::class, 'conversao']);

        // Vagas
        Route::get('vagas',                      [\App\Http\Controllers\Api\EmpresaVagaController::class, 'index']);
        Route::post('vagas',                     [\App\Http\Controllers\Api\EmpresaVagaController::class, 'store']);
        Route::get('vagas/niveis',               [\App\Http\Controllers\Api\EmpresaVagaController::class, 'niveis']);
        Route::get('vagas/{id}',                 [\App\Http\Controllers\Api\EmpresaVagaController::class, 'show']);
        Route::put('vagas/{id}',                 [\App\Http\Controllers\Api\EmpresaVagaController::class, 'update']);
        Route::delete('vagas/{id}',              [\App\Http\Controllers\Api\EmpresaVagaController::class, 'destroy']);

        // Candidatos recebidos (Kanban)
        Route::get('candidatos-recebidos',                          [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'index']);
        Route::get('candidatos-recebidos/{id}',                     [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'show']);
        Route::patch('candidatos-recebidos/{id}/etapa',             [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'updateEtapa']);
        Route::patch('candidatos-recebidos/{id}/status',            [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'updateStatus']);
        Route::post('candidatos-recebidos/{id}/parecer',            [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'storeParecer']);
        Route::get('candidatos-recebidos/{id}/curriculo/download',  [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'downloadCurriculo']);
        Route::get('vagas/{id}/candidatos', fn(\Illuminate\Http\Request $r, int $id) =>
            app(\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class)->index($r->merge(['vaga_id' => $id])));
        Route::get('kanban/etapas',  [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'kanbanEtapas']);
        Route::get('mapa/candidatos', [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'mapaCandidatos']);

        // Banco de currículos
        Route::get('banco-curriculos',                  [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'index']);
        Route::post('banco-curriculos',                 [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'store']);
        Route::post('banco-curriculos/copia-base',      [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'copiaBase']);
        Route::get('banco-curriculos/duplicata',        [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'duplicata']);
        Route::put('banco-curriculos/{id}',             [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'update']);
        Route::delete('banco-curriculos/{id}',          [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'destroy']);
        Route::patch('banco-curriculos/{id}/etapa',     [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'updateEtapa']);
        Route::get('banco-curriculos/{id}/download',    [\App\Http\Controllers\Api\EmpresaBancoCurriculoController::class, 'download']);

        // Testes DISC (envio a candidatos via link público)
        Route::get('testes/disc',                  [\App\Http\Controllers\Api\EmpresaTesteController::class, 'discIndex']);
        Route::post('testes/disc',                 [\App\Http\Controllers\Api\EmpresaTesteController::class, 'discStore']);
        Route::get('testes/disc/{id}',             [\App\Http\Controllers\Api\EmpresaTesteController::class, 'discShow']);
        Route::post('testes/disc/{id}/reenviar',   [\App\Http\Controllers\Api\EmpresaTesteController::class, 'discReenviar']);

        // Testes agendados (práticos/técnicos)
        Route::get('testes/agendados',                 [\App\Http\Controllers\Api\EmpresaTesteController::class, 'agendadosIndex']);
        Route::post('testes/agendados',                [\App\Http\Controllers\Api\EmpresaTesteController::class, 'agendadosStore']);
        Route::put('testes/agendados/{id}',            [\App\Http\Controllers\Api\EmpresaTesteController::class, 'agendadosUpdate']);
        Route::patch('testes/agendados/{id}/status',   [\App\Http\Controllers\Api\EmpresaTesteController::class, 'agendadosStatus']);
        Route::delete('testes/agendados/{id}',         [\App\Http\Controllers\Api\EmpresaTesteController::class, 'agendadosDestroy']);

        // Parceiros (catálogo público para empresa)
        Route::get('parceiros',                       [EmpresaParceiroController::class, 'index']);
        Route::get('parceiros/{id}',                  [EmpresaParceiroController::class, 'show']);
        Route::post('parceiros/{id}/visualizacao',    [EmpresaParceiroController::class, 'visualizar']);

        // Colaboradores
        Route::get('colaboradores/aniversariantes',   [\App\Http\Controllers\Api\EmpresaColaboradorController::class, 'aniversariantes']);
        Route::post('colaboradores/importar',         [\App\Http\Controllers\Api\EmpresaColaboradorController::class, 'importar']);
        Route::get('colaboradores',                   [\App\Http\Controllers\Api\EmpresaColaboradorController::class, 'index']);
        Route::post('colaboradores',                  [\App\Http\Controllers\Api\EmpresaColaboradorController::class, 'store']);
        Route::put('colaboradores/{id}',              [\App\Http\Controllers\Api\EmpresaColaboradorController::class, 'update']);
        Route::patch('colaboradores/{id}/status',     [\App\Http\Controllers\Api\EmpresaColaboradorController::class, 'updateStatus']);
        Route::delete('colaboradores/{id}',           [\App\Http\Controllers\Api\EmpresaColaboradorController::class, 'destroy']);

        // Agenda — tarefas
        Route::get('agenda/tarefas',           [\App\Http\Controllers\Api\EmpresaAgendaController::class, 'tarefasIndex']);
        Route::post('agenda/tarefas',          [\App\Http\Controllers\Api\EmpresaAgendaController::class, 'tarefasStore']);
        Route::patch('agenda/tarefas/{id}',    [\App\Http\Controllers\Api\EmpresaAgendaController::class, 'tarefasUpdate']);
        Route::delete('agenda/tarefas/{id}',   [\App\Http\Controllers\Api\EmpresaAgendaController::class, 'tarefasDestroy']);

        // Entrevistas
        Route::get('entrevistas',              [\App\Http\Controllers\Api\EmpresaEntrevistaController::class, 'index']);
        Route::post('entrevistas',             [\App\Http\Controllers\Api\EmpresaEntrevistaController::class, 'store']);
        Route::put('entrevistas/{id}',         [\App\Http\Controllers\Api\EmpresaEntrevistaController::class, 'update']);
        Route::patch('entrevistas/{id}/status',[\App\Http\Controllers\Api\EmpresaEntrevistaController::class, 'updateStatus']);
        Route::delete('entrevistas/{id}',      [\App\Http\Controllers\Api\EmpresaEntrevistaController::class, 'destroy']);

        // Plano
        Route::get('plano',           [\App\Http\Controllers\Api\EmpresaPlanoController::class, 'show']);
        Route::get('plano/catalogo',  [\App\Http\Controllers\Api\EmpresaPlanoController::class, 'catalogo']);
        Route::get('plano/utilizacao',[\App\Http\Controllers\Api\EmpresaRelatorioController::class, 'planoUtilizacao']);
        Route::post('plano/upgrade',  [\App\Http\Controllers\Api\EmpresaPlanoController::class, 'upgrade']);
        Route::get('faturamentos',    [\App\Http\Controllers\Api\EmpresaPlanoController::class, 'faturamentos']);
        Route::get('mensalidades',    [\App\Http\Controllers\Api\EmpresaRelatorioController::class, 'mensalidades']);

        // Relatorios
        Route::get('relatorios/recrutamento',   [\App\Http\Controllers\Api\EmpresaRelatorioController::class, 'recrutamento']);
        Route::get('relatorios/taxa-conversao', [\App\Http\Controllers\Api\EmpresaRelatorioController::class, 'taxaConversao']);

        // Aliases consumidos pelos relatorios (Relatorios.jsx)
        Route::get('candidatos', [\App\Http\Controllers\Api\EmpresaCandidatoRecebidoController::class, 'index']); // alias de candidatos-recebidos
        Route::get('biblioteca', [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'documentosIndex']); // alias da lista de documentos

        // Sub-usuários
        Route::get('sub-usuarios',                  [\App\Http\Controllers\Api\EmpresaSubUsuarioController::class, 'index']);
        Route::post('sub-usuarios',                 [\App\Http\Controllers\Api\EmpresaSubUsuarioController::class, 'store']);
        Route::put('sub-usuarios/{id}',             [\App\Http\Controllers\Api\EmpresaSubUsuarioController::class, 'update']);
        Route::patch('sub-usuarios/{id}/toggle-active', [\App\Http\Controllers\Api\EmpresaSubUsuarioController::class, 'toggleActive']);
        Route::delete('sub-usuarios/{id}',          [\App\Http\Controllers\Api\EmpresaSubUsuarioController::class, 'destroy']);

        // Beneficios (oferecidos aos colaboradores)
        Route::get('beneficios',         [\App\Http\Controllers\Api\EmpresaBeneficioController::class, 'index']);
        Route::post('beneficios',        [\App\Http\Controllers\Api\EmpresaBeneficioController::class, 'store']);
        Route::put('beneficios/{id}',    [\App\Http\Controllers\Api\EmpresaBeneficioController::class, 'update']);
        Route::delete('beneficios/{id}', [\App\Http\Controllers\Api\EmpresaBeneficioController::class, 'destroy']);

        // Biblioteca — tipos e documentos
        Route::get('biblioteca/tipos',                    [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'tiposIndex']);
        Route::post('biblioteca/tipos',                   [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'tiposStore']);
        Route::delete('biblioteca/tipos/{id}',            [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'tiposDestroy']);
        Route::get('biblioteca/documentos',               [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'documentosIndex']);
        Route::post('biblioteca/documentos',              [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'documentosStore']);
        Route::get('biblioteca/documentos/{id}/download', [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'documentosDownload']);
        Route::delete('biblioteca/documentos/{id}',       [\App\Http\Controllers\Api\EmpresaBibliotecaController::class, 'documentosDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Painel Franquia
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:franquia')->prefix('franquia')->group(function () {
        // Perfil
        Route::get('perfil',       [FranquiaPerfilController::class, 'show']);
        Route::put('perfil',       [FranquiaPerfilController::class, 'update']);
        Route::post('perfil/logo', [FranquiaPerfilController::class, 'uploadLogo']);

        // Dashboard
        Route::get('dashboard', [FranquiaDashboardController::class, 'index']);

        // Mapa interativo
        Route::get('mapa', [FranquiaMapaController::class, 'index']);

        // Relatórios
        Route::get('relatorios', [FranquiaRelatorioController::class, 'index']);
        Route::get('relatorios-gerenciais/{tipo}', [\App\Http\Controllers\Api\FranquiaRelatorioGerencialController::class, 'show']);

        // Vagas
        Route::get('vagas',                              [FranquiaVagaController::class, 'index']);
        Route::post('vagas',                             [FranquiaVagaController::class, 'store']);
        Route::get('vagas/niveis',                       [FranquiaVagaController::class, 'niveis']);
        Route::get('vagas/franquias',                    [FranquiaVagaController::class, 'franquiasDisponiveis']);
        Route::get('vagas/{id}',                         [FranquiaVagaController::class, 'show']);
        Route::put('vagas/{id}',                         [FranquiaVagaController::class, 'update']);
        Route::delete('vagas/{id}',                      [FranquiaVagaController::class, 'destroy']);
        Route::patch('vagas/{id}/toggle-ativa',          [FranquiaVagaController::class, 'toggleAtiva']);
        Route::post('vagas/{vagaId}/vincular',           [FranquiaVagaController::class, 'vincular']);
        Route::get('vagas/{vagaId}/candidatos',          [FranquiaVagaController::class, 'candidatos']);
        Route::post('vagas/{id}/documentos',             [FranquiaVagaController::class, 'storeDocumento']);
        Route::delete('vagas/{vagaId}/documentos/{docId}', [FranquiaVagaController::class, 'destroyDocumento']);
        Route::get('vagas/{id}/compartilhar',            [FranquiaVagaController::class, 'listCompartilhadas']);
        Route::post('vagas/{id}/compartilhar',           [FranquiaVagaController::class, 'compartilhar']);

        // Candidatos — rotas estáticas ANTES das dinâmicas com {id}
        Route::get('candidatos/status',                  [FranquiaCandidatoController::class, 'status']);
        Route::get('candidatos/discs',                   [FranquiaCandidatoController::class, 'discs']);
        Route::get('candidatos/pareceres',               [FranquiaCandidatoController::class, 'pareceres']);
        Route::patch('candidatos/pareceres/{id}/status', [FranquiaCandidatoController::class, 'atualizarStatusParecer']);
        Route::put('candidatos/parecer/{id}',            [FranquiaCandidatoController::class, 'updateParecer']);
        Route::delete('candidatos/parecer/{id}',         [FranquiaCandidatoController::class, 'destroyParecer']);
        // Pareceres das empresas da franquia (fluxo de validacao premium)
        Route::get('pareceres',                          [FranquiaCandidatoController::class, 'pareceresEmpresas']);
        Route::patch('pareceres/{id}/validar',           [FranquiaCandidatoController::class, 'validarParecer']);
        Route::get('candidatos',                         [FranquiaCandidatoController::class, 'index']);
        Route::post('candidatos',                        [FranquiaCandidatoController::class, 'store']);
        Route::get('candidatos/{id}',                    [FranquiaCandidatoController::class, 'show']);
        Route::put('candidatos/{id}',                    [FranquiaCandidatoController::class, 'update']);
        Route::post('candidatos/{candidatoId}/vincular', [FranquiaCandidatoController::class, 'vincular']);
        Route::get('candidatos/{id}/historico',          [FranquiaCandidatoController::class, 'historico']);
        Route::get('candidatos/{id}/pareceres',          [FranquiaCandidatoController::class, 'pareceresCandidato']);
        Route::post('candidatos/{id}/parecer',           [FranquiaCandidatoController::class, 'storeParecer']);
        Route::get('candidatos/{id}/disc',               [FranquiaCandidatoController::class, 'disc']);
        Route::post('candidatos/{id}/disc',              [FranquiaCandidatoController::class, 'discStore']);
        Route::patch('candidatos/{candidatoId}/vagas/{vagaId}/status', [FranquiaCandidatoController::class, 'updateStatus']);

        // Empresas (módulo premium)
        Route::get('empresas',                           [FranquiaEmpresaGestaoController::class, 'index']);
        Route::post('empresas',                          [FranquiaEmpresaGestaoController::class, 'store']);
        Route::get('empresas/relatorios',                [FranquiaEmpresaGestaoController::class, 'relatorios']);
        Route::get('empresas/{id}',                      [FranquiaEmpresaGestaoController::class, 'show']);
        Route::put('empresas/{id}',                      [FranquiaEmpresaGestaoController::class, 'update']);
        Route::patch('empresas/{id}/toggle-active',      [FranquiaEmpresaGestaoController::class, 'toggleActive']);
        Route::post('empresas/{id}/reset-password',      [FranquiaEmpresaGestaoController::class, 'resetPassword']);
        Route::get('empresas/{id}/followups',                  [FranquiaEmpresaGestaoController::class, 'indexFollowups']);
        Route::post('empresas/{id}/followups',                 [FranquiaEmpresaGestaoController::class, 'storeFollowup']);
        Route::put('empresas/{id}/followups/{followupId}',     [FranquiaEmpresaGestaoController::class, 'updateFollowup']);
        Route::get('empresas/{id}/beneficios',                 [FranquiaEmpresaGestaoController::class, 'indexBeneficios']);
        Route::get('empresas/{id}/documentos',                 [FranquiaEmpresaGestaoController::class, 'indexDocumentos']);
        Route::post('empresas/{id}/documentos',                [FranquiaEmpresaGestaoController::class, 'storeDocumento']);
        Route::delete('empresas/{id}/documentos/{docId}',      [FranquiaEmpresaGestaoController::class, 'destroyDocumento']);

        // Parceiros
        Route::get('parceiros',                          [FranquiaParceiroGestaoController::class, 'index']);
        Route::get('parceiros/{id}',                     [FranquiaParceiroGestaoController::class, 'show']);

        // Comunidade
        Route::prefix('comunidade')->group(function () {
            Route::get('posts',                          [FranquiaComunidadeController::class, 'index']);
            Route::post('posts',                         [FranquiaComunidadeController::class, 'store']);
            Route::get('posts/{id}',                     [FranquiaComunidadeController::class, 'show']);
            Route::delete('posts/{id}',                  [FranquiaComunidadeController::class, 'destroy']);
            Route::post('posts/{id}/comentarios',        [FranquiaComunidadeController::class, 'comentar']);
            Route::post('posts/{id}/reagir',             [FranquiaComunidadeController::class, 'reagir']);
        });

        // Chamados
        Route::get('chamados',                           [FranquiaChamadoController::class, 'index']);
        Route::post('chamados',                          [FranquiaChamadoController::class, 'store']);
        Route::get('chamados/{id}',                      [FranquiaChamadoController::class, 'show']);
        Route::patch('chamados/{id}/fechar',             [FranquiaChamadoController::class, 'fechar']);
        Route::get('chamados/{chamadoId}/mensagens',     [FranquiaChamadoController::class, 'mensagens']);
        Route::post('chamados/{chamadoId}/mensagens',    [FranquiaChamadoController::class, 'storeMensagem']);

        // Financeiro
        Route::prefix('financeiro')->group(function () {
            Route::get('caixa',                          [FranquiaFinanceiroController::class, 'caixa']);
            Route::get('contas-receber',                 [FranquiaFinanceiroController::class, 'contasReceber']);
            Route::get('contas-receber/sstart',          [FranquiaFinanceiroController::class, 'contasReceberSStart']);
            Route::get('contas-pagar',                   [FranquiaFinanceiroController::class, 'contasPagar']);
            Route::post('contas-pagar',                  [FranquiaFinanceiroController::class, 'storeContaPagar']);
            Route::patch('contas-pagar/{id}/pagar',      [FranquiaFinanceiroController::class, 'pagarConta']);
            Route::get('taxas',                          [FranquiaFinanceiroController::class, 'taxas']);
            Route::get('faturaveis',                     [FranquiaFinanceiroController::class, 'faturaveis']);
            Route::post('faturar',                       [FranquiaFinanceiroController::class, 'faturar']);
            Route::get('faturamento',                    [FranquiaFinanceiroController::class, 'faturamento']);
            Route::get('fiscal',                         [FranquiaFinanceiroController::class, 'fiscal']);
            Route::get('relatorios',                     [FranquiaFinanceiroController::class, 'relatorios']);
        });

        // Cadastro (serviços e fornecedores)
        Route::prefix('cadastro')->group(function () {
            Route::get('servicos',                       [FranquiaCadastroController::class, 'indexServicos']);
            Route::post('servicos',                      [FranquiaCadastroController::class, 'storeServico']);
            Route::put('servicos/{id}',                  [FranquiaCadastroController::class, 'updateServico']);
            Route::delete('servicos/{id}',               [FranquiaCadastroController::class, 'destroyServico']);
            Route::get('fornecedores',                   [FranquiaCadastroController::class, 'indexFornecedores']);
            Route::post('fornecedores',                  [FranquiaCadastroController::class, 'storeFornecedor']);
            Route::put('fornecedores/{id}',              [FranquiaCadastroController::class, 'updateFornecedor']);
            Route::delete('fornecedores/{id}',           [FranquiaCadastroController::class, 'destroyFornecedor']);
        });

        // T&D (Treinamento e Desenvolvimento)
        Route::prefix('td')->group(function () {
            Route::get('onboarding',                     [FranquiaTDController::class, 'onboarding']);
            Route::patch('onboarding/{id}/concluir',     [FranquiaTDController::class, 'concluirOnboarding']);
            Route::get('ead',                            [FranquiaTDController::class, 'ead']);
            Route::get('ead/{id}',                       [FranquiaTDController::class, 'eadShow']);
            Route::post('ead/{id}/progresso',            [FranquiaTDController::class, 'eadProgresso']);
            Route::post('ead/{cursoId}/provas/{provaId}/responder', [FranquiaTDController::class, 'responderProva']);
        });

        // Biblioteca
        Route::prefix('biblioteca')->group(function () {
            Route::get('arquivos',                       [FranquiaBibliotecaController::class, 'indexArquivos']);
            Route::post('arquivos',                      [FranquiaBibliotecaController::class, 'storeArquivo']);
            Route::delete('arquivos/{id}',               [FranquiaBibliotecaController::class, 'destroyArquivo']);
            Route::get('arquivos/{id}/download',         [FranquiaBibliotecaController::class, 'downloadArquivo']);
            Route::get('manuais',                        [FranquiaBibliotecaController::class, 'indexManuais']);
            Route::get('manuais/{id}/download',          [FranquiaBibliotecaController::class, 'downloadManual']);
        });

        // Agenda
        Route::get('agenda',                             [FranquiaAgendaController::class, 'index']);
        Route::get('agenda/aniversariantes',             [FranquiaAgendaController::class, 'aniversariantes']);
        Route::post('agenda',                            [FranquiaAgendaController::class, 'store']);
        Route::put('agenda/{id}',                        [FranquiaAgendaController::class, 'update']);
        Route::delete('agenda/{id}',                     [FranquiaAgendaController::class, 'destroy']);

        // Notificações
        Route::get('notificacoes',        [\App\Http\Controllers\Api\FranquiaNotificacaoController::class, 'index']);
        Route::post('notificacoes/lidas', [\App\Http\Controllers\Api\FranquiaNotificacaoController::class, 'marcarLidas']);
    });

    /*
    |--------------------------------------------------------------------------
    | Painel Candidato
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:candidato')->prefix('candidato')->group(function () {
        // Perfil do próprio candidato
        Route::get('perfil',            [CandidatoPerfilController::class, 'show']);
        Route::put('perfil',            [CandidatoPerfilController::class, 'update']);
        Route::post('perfil/foto',      [CandidatoPerfilController::class, 'uploadFoto']);
        Route::post('perfil/curriculo', [CandidatoPerfilController::class, 'uploadCurriculo']);

        // Currículos do candidato (CRUD + ativar)
        Route::get('curriculos',             [CandidatoCurriculoController::class, 'index']);
        Route::post('curriculos',            [CandidatoCurriculoController::class, 'store']);
        Route::put('curriculos/{id}/ativar', [CandidatoCurriculoController::class, 'ativar']);
        Route::delete('curriculos/{id}',     [CandidatoCurriculoController::class, 'destroy']);

        // Vagas (listagem pública de vagas publicadas + aplicação)
        Route::get('vagas',               [CandidatoVagaController::class, 'index']);
        Route::get('vagas/{id}',          [CandidatoVagaController::class, 'show']);
        Route::post('vagas/{id}/aplicar', [CandidatoVagaController::class, 'aplicar']);

        // Empresas (listagem pública de empresas aprovadas)
        Route::get('empresas',      [CandidatoEmpresaController::class, 'index']);
        Route::get('empresas/{id}', [CandidatoEmpresaController::class, 'show']);

        // Envios (histórico de candidaturas)
        Route::get('envios',      [CandidatoEnvioController::class, 'index']);
        Route::get('envios/{id}', [CandidatoEnvioController::class, 'show']);

        // Créditos
        Route::get('creditos/saldo',    [CandidatoCreditoController::class, 'saldo']);
        Route::get('creditos/extrato',  [CandidatoCreditoController::class, 'extrato']);
        Route::get('creditos/pacotes',  [CandidatoCreditoController::class, 'pacotes']);
        Route::post('creditos/comprar', [CandidatoCreditoController::class, 'comprar']);
        Route::get('creditos/compras/{id}/status', [CandidatoCreditoController::class, 'statusCompra']);

        // Parceiros
        Route::get('parceiros',      [CandidatoParceiroController::class, 'index']);
        Route::get('parceiros/{id}', [CandidatoParceiroController::class, 'show']);
        Route::post('parceiros/{id}/visualizar', [CandidatoParceiroController::class, 'visualizar']);
    });

    /*
    |--------------------------------------------------------------------------
    | Painel Parceiro
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:parceiro')->prefix('parceiro')->group(function () {
        // Dashboard
        Route::get('dashboard', [ParceiroDashboardController::class, 'index']);

        // Perfil
        Route::get('perfil',       [ParceiroPerfilController::class, 'show']);
        Route::put('perfil',       [ParceiroPerfilController::class, 'update']);
        Route::post('perfil/logo', [ParceiroPerfilController::class, 'uploadLogo']);

        // Serviços
        Route::get('categorias',         [ParceiroServicoController::class, 'categorias']);
        Route::get('servicos',           [ParceiroServicoController::class, 'index']);
        Route::post('servicos',          [ParceiroServicoController::class, 'store']);
        Route::put('servicos/{id}',      [ParceiroServicoController::class, 'update']);
        Route::delete('servicos/{id}',   [ParceiroServicoController::class, 'destroy']);

        // Agenda
        Route::get('agenda',                        [ParceiroAgendaController::class, 'index']);
        Route::post('agenda',                       [ParceiroAgendaController::class, 'store']);
        Route::patch('agenda/{id}/confirmar',       [ParceiroAgendaController::class, 'confirmar']);
        Route::patch('agenda/{id}/concluir',        [ParceiroAgendaController::class, 'concluir']);
        Route::patch('agenda/{id}/cancelar',        [ParceiroAgendaController::class, 'cancelar']);

        // Tarefas
        Route::get('tarefas',                       [ParceiroTarefaController::class, 'index']);
        Route::post('tarefas',                      [ParceiroTarefaController::class, 'store']);
        Route::patch('tarefas/{id}/toggle',         [ParceiroTarefaController::class, 'toggle']);
        Route::delete('tarefas/{id}',               [ParceiroTarefaController::class, 'destroy']);
    });
});
