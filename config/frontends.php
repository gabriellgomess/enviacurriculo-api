<?php

/*
|--------------------------------------------------------------------------
| URLs dos frontends (SPAs) por painel
|--------------------------------------------------------------------------
| Usadas para montar links enviados por e-mail (ex.: reset de senha).
| Em dev, sobrescreva no .env com as portas locais de cada painel.
*/

return [
    'candidato' => env('FRONTEND_URL_CANDIDATO', 'https://candidato.nexustech.net.br'),
    'empresa'   => env('FRONTEND_URL_EMPRESA',   'https://empresa.nexustech.net.br'),
    'franquia'  => env('FRONTEND_URL_FRANQUIA',  'https://franquia.nexustech.net.br'),
    'parceiro'  => env('FRONTEND_URL_PARCEIRO',  'https://parceiro.nexustech.net.br'),
    'admin'     => env('FRONTEND_URL_ADMIN',     'https://administrativo.nexustech.net.br'),
];
