<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Models\UserContext;
use App\Models\UserRole;

/**
 * `User` usa SoftDeletes, e `users.email` é único — então um `$user->delete()`
 * comum nunca libera o e-mail, e o cliente não consegue recadastrar a mesma
 * franquia/parceiro/candidato/admin depois de excluir. Isso apaga de verdade,
 * mas só quando o usuário não tem mais nenhum outro papel/contexto vinculado
 * (um mesmo usuário pode ser, por exemplo, admin E franquia ao mesmo tempo) —
 * senão apagaríamos o acesso de um painel que a pessoa ainda usa.
 *
 * Chamar depois de já ter removido o UserContext/UserRole do papel que está
 * sendo excluído, para que a checagem reflita o que sobra.
 */
trait DeletesOrphanUser
{
    private function excluirUsuarioOrfao(?int $userId): void
    {
        if (!$userId) {
            return;
        }

        if (UserContext::where('user_id', $userId)->exists()) {
            return;
        }
        if (UserRole::where('user_id', $userId)->exists()) {
            return;
        }

        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return;
        }

        $user->tokens()->delete();
        $user->forceDelete();
    }
}
