<?php

namespace Tests\Unit;

use App\Models\Envio;
use Tests\TestCase;

/**
 * Campos gravados junto com a troca de status do vínculo
 * (Envio::preencherCamposDoStatus), usados pelos painéis Admin, Franquia e Empresa.
 */
class EnvioCamposDoStatusTest extends TestCase
{
    private function contratado(): Envio
    {
        $envio = new Envio();
        $envio->status = 'aprovado';
        $envio->salario_aprovado = 2327;
        $envio->tipo_contrato = 'clt';
        $envio->data_admissao = '2026-09-21';
        return $envio;
    }

    public function test_reposicao_mantem_salario_contrato_e_admissao(): void
    {
        // Regressão: as telas mandam esses campos em branco ao marcar reposição
        $envio = $this->contratado();
        $envio->status = 'reposicao';
        $envio->preencherCamposDoStatus([
            'observacao' => 'Substituto: fulano', 'salario_aprovado' => null, 'tipo_contrato' => null,
            'data_admissao' => null, 'data_saida' => '2026-10-05',
        ]);

        $this->assertEquals(2327, $envio->salario_aprovado);
        $this->assertSame('clt', $envio->tipo_contrato);
        $this->assertSame('2026-09-21', $envio->data_admissao->toDateString());
        $this->assertSame('2026-10-05', $envio->data_saida->toDateString());
        $this->assertSame('Substituto: fulano', $envio->observacao);
    }

    public function test_reposicao_aceita_correcao_dos_dados_da_contratacao(): void
    {
        $envio = $this->contratado();
        $envio->status = 'reposicao';
        $envio->preencherCamposDoStatus(['salario_aprovado' => 2500, 'data_admissao' => '2026-09-22']);

        $this->assertEquals(2500, $envio->salario_aprovado);
        $this->assertSame('2026-09-22', $envio->data_admissao->toDateString());
    }

    public function test_outros_status_seguem_gravando_o_que_vier(): void
    {
        $envio = $this->contratado();
        $envio->status = 'desistiu';
        $envio->preencherCamposDoStatus(['salario_aprovado' => null, 'data_admissao' => null]);

        $this->assertNull($envio->salario_aprovado);
        $this->assertNull($envio->data_admissao);
        $this->assertSame('clt', $envio->tipo_contrato, 'campo que não veio na requisição não muda');
    }
}
