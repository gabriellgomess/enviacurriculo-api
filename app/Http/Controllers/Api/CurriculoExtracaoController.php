<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExtratorCurriculo;
use Illuminate\Http\Request;

/**
 * Lê um currículo e devolve os dados para preencher o formulário.
 *
 * Um controller só, usado pelos quatro painéis: o que muda entre eles é a
 * proteção da rota, não o comportamento.
 *
 * Deliberadamente separado do cadastro. O arquivo sobe duas vezes — aqui para
 * ler, e depois no submit normal — e é isso que garante que o cadastro continue
 * funcionando exatamente como hoje se a extração falhar ou for desligada.
 *
 * Responde 200 mesmo quando não consegue extrair: para o formulário, "não deu
 * para ler" é um resultado previsto, não um erro.
 */
class CurriculoExtracaoController extends Controller
{
    public function __invoke(Request $request, ExtratorCurriculo $extrator)
    {
        $request->validate([
            // Mesmos formatos e tamanho aceitos no upload do cadastro.
            'arquivo' => 'required|file|max:10240|mimes:pdf,doc,docx,jpg,jpeg,png,webp',
        ]);

        return response()->json(
            $extrator->extrair($request->file('arquivo'))
        );
    }
}
