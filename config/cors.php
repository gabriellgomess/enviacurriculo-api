<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',
        'http://localhost:5176',
        'http://localhost:5177',
        'http://localhost:5178',
        'http://localhost:5179',
        'http://localhost:5180',
        'http://localhost:5181',
        'http://localhost:5182',
        'http://localhost:5183',
        'http://localhost:5184',
        'http://localhost:5185',
        // Produção — nexustech.net.br
        'https://enviacurriculo.nexustech.net.br',
        'https://administrativo.nexustech.net.br',
        'https://empresa.nexustech.net.br',
        'https://franquia.nexustech.net.br',
        'https://candidato.nexustech.net.br',
        'https://parceiro.nexustech.net.br',
        // Produção — enviacurriculo.com.br
        'https://enviacurriculo.com.br',
        'https://www.enviacurriculo.com.br',
        'https://administrativo.enviacurriculo.com.br',
        'https://empresa.enviacurriculo.com.br',
        'https://franquia.enviacurriculo.com.br',
        'https://candidato.enviacurriculo.com.br',
        'https://parceiro.enviacurriculo.com.br',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
