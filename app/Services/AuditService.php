<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    /**
     * Registra um evento de auditoria no sistema.
     */
    public static function log(
        string $action,
        string $descricao,
        ?int $franquiaId = null,
        ?Model $entity = null,
        ?array $dadosAnteriores = null,
        ?array $dadosNovos = null,
        ?Request $request = null
    ): ?AuditLog {
        try {
            $user = $request?->user() ?? auth()->user();

            $ip = $request?->ip();
            $userAgent = $request ? substr((string)$request->userAgent(), 0, 500) : null;

            return AuditLog::create([
                'user_id'          => $user?->id,
                'user_name'        => $user?->name ?? 'Sistema',
                'user_email'       => $user?->email,
                'role'             => $user?->currentAccessToken() ? self::extractRole($user->currentAccessToken()) : null,
                'franquia_id'      => $franquiaId,
                'action'           => $action,
                'descricao'        => $descricao,
                'entity_type'      => $entity ? get_class($entity) : null,
                'entity_id'        => $entity?->getKey(),
                'dados_anteriores' => $dadosAnteriores,
                'dados_novos'      => $dadosNovos,
                'ip_address'       => $ip,
                'user_agent'       => $userAgent,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            // Logs de auditoria não devem interromper o fluxo operacional caso tabela não exista em dev
            return null;
        }
    }

    private static function extractRole($token): ?string
    {
        $abilities = $token->abilities ?? [];
        foreach ($abilities as $ability) {
            if (str_starts_with($ability, 'role:')) {
                return substr($ability, 5);
            }
        }
        return null;
    }
}
