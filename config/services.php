<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Gateway de pagamento PIX (créditos do candidato). Ver ASAAS_SETUP.md.
    // Sem ASAAS_API_KEY definida, o AsaasService opera em modo mock.
    'asaas' => [
        'api_key'       => env('ASAAS_API_KEY'),
        'base_url'      => env('ASAAS_BASE_URL', 'https://api-sandbox.asaas.com/v3'),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
    ],

    // Cloudflare Turnstile (anti-bot do formulário externo de leads).
    // Sem TURNSTILE_SECRET_KEY definida, a validação é ignorada.
    'turnstile' => [
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Extração de dados do currículo por IA (ver PLANO_EXTRACAO_CURRICULO_IA.md).
    //
    // Sem OPENAI_API_KEY, ou com `ativa` em false, o endpoint responde
    // "extração indisponível" e o formulário segue no preenchimento manual —
    // nunca bloqueia um cadastro.
    'openai' => [
        'api_key'  => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout'  => (int) env('OPENAI_TIMEOUT', 20),
        'ativa'    => filter_var(env('EXTRACAO_CURRICULO_ATIVA', true), FILTER_VALIDATE_BOOL),
        // Currículo real dificilmente passa de 8 mil caracteres; o corte protege
        // o custo contra arquivos anômalos.
        'max_caracteres' => (int) env('EXTRACAO_MAX_CARACTERES', 15000),
        // Abaixo disto o PDF é imagem/escaneado: responde sem chamar a IA.
        'min_caracteres' => (int) env('EXTRACAO_MIN_CARACTERES', 200),
    ],

    // Webhook de leads externos (formulário WordPress/Elementor do cliente).
    // Token compartilhado enviado na URL do webhook (?token=) ou no header
    // X-Webhook-Token. Sem LEADS_EXTERNOS_WEBHOOK_TOKEN, a checagem é ignorada.
    'leads_externos' => [
        'webhook_token' => env('LEADS_EXTERNOS_WEBHOOK_TOKEN'),
    ],

];
