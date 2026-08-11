<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lê um currículo em PDF ou DOCX e devolve os dados estruturados.
 *
 * O texto é extraído aqui, no servidor, e só ele vai para a OpenAI. Isso é mais
 * barato que enviar o arquivo, e é o que permite descobrir que um PDF é
 * escaneado ANTES de gastar qualquer token.
 *
 * Sem OCR, por decisão do cliente: imagem e PDF sem texto devolvem
 * `extraido: false` com o motivo, e o formulário é preenchido à mão.
 *
 * Nada aqui pode impedir um cadastro. Qualquer falha — chave ausente, rede,
 * quota, resposta inválida — vira `extraido: false`.
 */
class ExtratorCurriculo
{
    public const MOTIVO_DESLIGADO   = 'desligado';
    public const MOTIVO_FORMATO     = 'formato_nao_suportado';
    public const MOTIVO_SEM_TEXTO   = 'sem_texto';
    public const MOTIVO_FALHA_IA    = 'falha_ia';

    /** Formatos de que se consegue tirar texto sem OCR. */
    private const EXTENSOES = ['pdf', 'doc', 'docx'];

    public function extrair(UploadedFile $arquivo): array
    {
        if (!config('services.openai.ativa') || empty(config('services.openai.api_key'))) {
            return $this->indisponivel(self::MOTIVO_DESLIGADO);
        }

        $extensao = strtolower($arquivo->getClientOriginalExtension());

        if (!in_array($extensao, self::EXTENSOES, true)) {
            return $this->indisponivel(self::MOTIVO_FORMATO);
        }

        $texto = $this->extrairTexto($arquivo, $extensao);

        // Menos que o mínimo = PDF escaneado, DOC antigo ou arquivo vazio.
        if ($texto === null || mb_strlen($texto) < config('services.openai.min_caracteres')) {
            return $this->indisponivel(self::MOTIVO_SEM_TEXTO);
        }

        $texto = mb_substr($texto, 0, config('services.openai.max_caracteres'));

        try {
            $dados = $this->estruturar($texto);
        } catch (\Throwable $e) {
            Log::warning('Extração de currículo falhou: ' . $e->getMessage());
            return $this->indisponivel(self::MOTIVO_FALHA_IA);
        }

        return ['extraido' => true, 'dados' => $dados];
    }

    /* ───────────────────────── Texto ───────────────────────── */

    private function extrairTexto(UploadedFile $arquivo, string $extensao): ?string
    {
        try {
            return match ($extensao) {
                'pdf'          => $this->textoDePdf($arquivo->getRealPath()),
                'docx', 'doc'  => $this->textoDeDocx($arquivo->getRealPath()),
                default        => null,
            };
        } catch (\Throwable $e) {
            Log::warning('Leitura do arquivo falhou: ' . $e->getMessage());
            return null;
        }
    }

    private function textoDePdf(string $caminho): ?string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            Log::warning('smalot/pdfparser não instalado — PDF não será lido.');
            return null;
        }

        $parser = new \Smalot\PdfParser\Parser();

        return $this->limpar($parser->parseFile($caminho)->getText());
    }

    /**
     * DOCX é um ZIP com XML dentro — não precisa de biblioteca.
     *
     * O `.doc` antigo (binário do Word 97) não é ZIP e cai fora: o ZipArchive
     * falha ao abrir e o arquivo segue para o preenchimento manual.
     */
    private function textoDeDocx(string $caminho): ?string
    {
        $zip = new \ZipArchive();

        if ($zip->open($caminho) !== true) {
            return null;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return null;
        }

        // Quebras de parágrafo e de linha viram \n antes de remover as tags,
        // senão o currículo inteiro chega como uma única linha.
        $xml = preg_replace('#<w:(p|br)\b[^>]*/?>#', "\n", $xml);

        return $this->limpar(strip_tags($xml));
    }

    private function limpar(string $texto): string
    {
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $texto = preg_replace('/[ \t]+/u', ' ', $texto);
        $texto = preg_replace('/\n{3,}/u', "\n\n", $texto);

        return trim($texto);
    }

    /* ───────────────────────── OpenAI ───────────────────────── */

    private function estruturar(string $texto): array
    {
        $resposta = Http::withToken(config('services.openai.api_key'))
            ->timeout(config('services.openai.timeout'))
            ->post(rtrim(config('services.openai.base_url'), '/') . '/chat/completions', [
                'model' => config('services.openai.model'),
                // Zero para não "melhorar" o que está escrito no currículo.
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $this->instrucao()],
                    ['role' => 'user',   'content' => $texto],
                ],
                // Structured Outputs: a resposta vem no formato do schema,
                // sem precisar interpretar texto solto.
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name'   => 'curriculo',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
            ]);

        if (!$resposta->successful()) {
            throw new \RuntimeException('OpenAI respondeu ' . $resposta->status() . ': ' . $resposta->body());
        }

        $conteudo = $resposta->json('choices.0.message.content');
        $dados    = json_decode((string) $conteudo, true);

        if (!is_array($dados)) {
            throw new \RuntimeException('Resposta da OpenAI não é JSON válido.');
        }

        return $this->normalizar($dados);
    }

    private function instrucao(): string
    {
        return <<<'TXT'
        Você extrai dados de currículos brasileiros e devolve JSON no schema dado.

        Regras:
        - Extraia SOMENTE o que está literalmente escrito no texto.
        - Campo ausente, ilegível ou duvidoso: null. Nunca invente, deduza ou
          complete informação.
        - Datas no formato AAAA-MM-DD. Se só houver mês e ano, use o dia 01.
        - CPF e telefone: devolva apenas os dígitos.
        - estado: sigla de duas letras (SC, PR, SP...).
        - pretensao_salarial: número, sem "R$" nem pontuação de milhar.
        - cargos_interesse: até 8 cargos citados como objetivo/pretensão. Se o
          currículo não declara objetivo, use o cargo mais recente da
          experiência.
        - experiencia_profissional, educacao, habilidades e informacoes_adicionais:
          texto corrido, preservando o conteúdo original de forma organizada.
        TXT;
    }

    /** Espelha os campos do formulário de cadastro de candidato. */
    private function schema(): array
    {
        $texto = ['type' => ['string', 'null']];

        $propriedades = [
            'nome'                     => $texto,
            'email'                    => $texto,
            'telefone'                 => $texto,
            'cpf'                      => $texto,
            'data_nascimento'          => $texto,
            'cep'                      => $texto,
            'rua'                      => $texto,
            'numero'                   => $texto,
            'bairro'                   => $texto,
            'cidade'                   => $texto,
            'estado'                   => $texto,
            'pretensao_salarial'       => ['type' => ['number', 'null']],
            'cargos_interesse'         => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
            ],
            'experiencia_profissional' => $texto,
            'educacao'                 => $texto,
            'habilidades'              => $texto,
            'idiomas'                  => $texto,
            'informacoes_adicionais'   => $texto,
        ];

        return [
            'type'                 => 'object',
            'properties'           => $propriedades,
            // strict exige listar todas as chaves como obrigatórias; o que não
            // existir no currículo vem null.
            'required'             => array_keys($propriedades),
            'additionalProperties' => false,
        ];
    }

    /** Limpezas que não vale pedir ao modelo — mais barato fazer aqui. */
    private function normalizar(array $dados): array
    {
        foreach (['cpf', 'telefone', 'cep'] as $campo) {
            if (!empty($dados[$campo])) {
                $dados[$campo] = preg_replace('/\D/', '', (string) $dados[$campo]);
            }
        }

        if (!empty($dados['estado'])) {
            $dados['estado'] = mb_strtoupper(mb_substr(trim($dados['estado']), 0, 2));
        }

        if (!empty($dados['email'])) {
            $dados['email'] = mb_strtolower(trim($dados['email']));
            if (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
                $dados['email'] = null;
            }
        }

        $dados['cargos_interesse'] = array_slice(
            array_values(array_filter((array) ($dados['cargos_interesse'] ?? []))),
            0, 8
        );

        return $dados;
    }

    private function indisponivel(string $motivo): array
    {
        return [
            'extraido' => false,
            'motivo'   => $motivo,
            'mensagem' => match ($motivo) {
                self::MOTIVO_FORMATO   => 'Só é possível ler currículos em PDF ou DOCX. Preencha o formulário manualmente.',
                self::MOTIVO_SEM_TEXTO => 'Este arquivo não tem texto selecionável — provavelmente é digitalizado ou uma imagem. Preencha o formulário manualmente.',
                default                => 'Não foi possível extrair os dados deste arquivo. Preencha o formulário manualmente.',
            },
        ];
    }
}
