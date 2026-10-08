<?php

return [

    /*
     * Data de corte do faturamento estimado (Desempenho da Rede).
     *
     * Aprovados sem data de admissão entram como estimado no mês corrente
     * só se foram aprovados a partir desta data. Os mais antigos sem
     * admissão ficam fora da tela — combinado com o cliente em out/2026.
     */
    'estimado_desde' => env('FINANCEIRO_ESTIMADO_DESDE', '2026-09-01'),

];
