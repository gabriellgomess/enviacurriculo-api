<?php

namespace Tests\Feature;

use App\Models\Envio;
use App\Models\EnvioStatusHistorico;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Histórico de status do vínculo: registro automático pelo model e
 * preenchimento do passado pelo comando envios:historico-inicial.
 *
 * Roda em SQLite em memória com só as tabelas necessárias — as migrations
 * completas do projeto usam recursos do MySQL.
 */
class HistoricoStatusEnvioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('envios', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('candidato_id')->default(1);
            $t->unsignedBigInteger('vaga_id')->default(1);
            $t->unsignedBigInteger('curriculo_id')->default(1);
            $t->string('status')->default('enviado');
            $t->string('origem')->nullable();
            $t->decimal('salario_aprovado', 10, 2)->nullable();
            $t->date('data_admissao')->nullable();
            $t->timestamps();
        });

        (include database_path('migrations/2026_10_08_000001_create_envio_status_historico_table.php'))->up();
    }

    public function test_registra_a_criacao_e_cada_mudanca_de_status(): void
    {
        $envio = Envio::create(['candidato_id' => 1, 'vaga_id' => 1, 'curriculo_id' => 1, 'status' => 'enviado']);

        $this->travelTo('2026-09-28 10:00:00');
        $envio->update(['status' => 'aprovado', 'salario_aprovado' => 3000, 'data_admissao' => '2026-10-01']);

        $linhas = EnvioStatusHistorico::where('envio_id', $envio->id)->orderBy('id')->get();
        $this->assertSame([[null, 'enviado'], ['enviado', 'aprovado']],
            $linhas->map(fn($l) => [$l->status_anterior, $l->status_novo])->all());
        $this->assertSame('2026-09-28', $linhas[1]->ocorrido_em->toDateString());
        $this->assertFalse($linhas[1]->estimado);
        $this->assertSame('sistema', $linhas[1]->painel); // sem token, fora de um painel
    }

    public function test_editar_sem_mudar_status_nao_gera_linha_nem_muda_a_data_da_aprovacao(): void
    {
        $envio = Envio::create(['candidato_id' => 1, 'vaga_id' => 1, 'curriculo_id' => 1, 'status' => 'enviado']);
        $this->travelTo('2026-09-28 10:00:00');
        $envio->update(['status' => 'aprovado']);

        // Correção de salário e de admissão em outubro: a aprovação continua em setembro
        $this->travelTo('2026-10-05 15:00:00');
        $envio->update(['salario_aprovado' => 3500, 'data_admissao' => '2026-10-02']);

        $this->assertSame(2, EnvioStatusHistorico::where('envio_id', $envio->id)->count());
        $aprovacao = EnvioStatusHistorico::where('envio_id', $envio->id)->where('status_novo', 'aprovado')->first();
        $this->assertSame('2026-09-28', $aprovacao->ocorrido_em->toDateString());
    }

    public function test_comando_preenche_o_passado_com_datas_estimadas_e_e_idempotente(): void
    {
        // Vínculos anteriores ao histórico: inseridos sem passar pelo model
        DB::table('envios')->insert([
            ['id' => 10, 'status' => 'aprovado', 'origem' => 'franquia', 'data_admissao' => '2026-09-01',
             'updated_at' => '2026-08-24 09:00:00', 'created_at' => '2026-08-11 09:00:00'],
            ['id' => 11, 'status' => 'reposicao', 'origem' => 'migracao', 'data_admissao' => '2026-07-10',
             'updated_at' => '2026-08-02 14:00:00', 'created_at' => '2026-07-01 09:00:00'],
            ['id' => 12, 'status' => 'reprovado', 'origem' => 'franquia', 'data_admissao' => null,
             'updated_at' => '2026-08-02 14:00:00', 'created_at' => '2026-07-01 09:00:00'],
        ]);

        $this->artisan('envios:historico-inicial', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, EnvioStatusHistorico::count(), 'simulação não grava');

        $this->artisan('envios:historico-inicial')->assertSuccessful();

        $aprov = fn($id) => \Carbon\Carbon::parse(EnvioStatusHistorico::where('envio_id', $id)
            ->where('status_novo', 'aprovado')->value('ocorrido_em'))->toDateString();
        // Aprovado: última alteração
        $this->assertSame('2026-08-24', $aprov(10));
        // Reposição: aprovação na admissão; a reposição na última alteração
        $this->assertSame('2026-07-10', $aprov(11));
        $this->assertSame(1, EnvioStatusHistorico::where('envio_id', 11)->where('status_novo', 'reposicao')->count());
        // Reprovado não entra
        $this->assertSame(0, EnvioStatusHistorico::where('envio_id', 12)->count());
        $this->assertSame(3, EnvioStatusHistorico::where('estimado', true)->count());

        // Rodar de novo não duplica
        $this->artisan('envios:historico-inicial')->assertSuccessful();
        $this->assertSame(3, EnvioStatusHistorico::count());
    }
}
