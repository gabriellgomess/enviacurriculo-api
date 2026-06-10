<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    /**
     * POST /auth/forgot-password
     * Envia o e-mail com o link de redefinição apontando para o painel informado.
     * Resposta é sempre genérica para não revelar se o e-mail existe.
     */
    public function forgot(Request $request)
    {
        $request->validate([
            'email'  => 'required|email',
            'painel' => 'required|in:candidato,empresa,franquia,parceiro,admin',
        ]);

        $status = Password::sendResetLink(
            $request->only('email'),
            function ($user, string $token) use ($request) {
                $user->notify(new ResetPasswordNotification($token, $request->painel));
            }
        );

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Aguarde antes de solicitar um novo link.',
            ], 429);
        }

        // RESET_LINK_SENT ou INVALID_USER → mesma resposta (anti-enumeração)
        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link de redefinição.',
        ]);
    }

    /**
     * POST /auth/reset-password
     * Valida o token e define a nova senha. Revoga todos os tokens de acesso ativos.
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete(); // desloga de todos os dispositivos
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Link inválido ou expirado. Solicite uma nova redefinição.',
            ], 422);
        }

        return response()->json([
            'message' => 'Senha redefinida com sucesso. Faça login com a nova senha.',
        ]);
    }
}
