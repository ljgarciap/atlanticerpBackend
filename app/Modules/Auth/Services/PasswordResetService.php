<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Mail\PasswordResetMail;
use App\Modules\Auth\Models\PasswordResetToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class PasswordResetService
{
    private const TTL_HOURS        = 1;
    private const TOKEN_BYTES      = 32;

    public function sendResetLink(string $email): void
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        PasswordResetToken::query()
            ->where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));

        PasswordResetToken::create([
            'email'      => $email,
            'token_hash' => $this->hash($rawToken),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        $resetUrl = $this->frontendUrl("/reset-password?token={$rawToken}");

        Mail::to($email)->send(new PasswordResetMail($user->first_name, $resetUrl));
    }

    public function reset(string $rawToken, string $newPassword): void
    {
        $tokenHash = $this->hash($rawToken);

        /** @var PasswordResetToken|null $record */
        $record = PasswordResetToken::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if ($record === null || $record->isExpired() || $record->isUsed()) {
            throw ValidationException::withMessages([
                'token' => ['El enlace es inválido o ha expirado. Solicita uno nuevo.'],
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $record->email)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => ['El enlace es inválido o ha expirado.'],
            ]);
        }

        $user->password_hash = $newPassword;
        $user->save();

        $record->update(['used_at' => now()]);

        $refreshTable = $user->getTable();
        $refreshTable = str_replace('users', 'refresh_tokens', $refreshTable);
        \Illuminate\Support\Facades\DB::table($refreshTable)
            ->where('user_id', $user->id)
            ->update(['revoked' => true]);
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function frontendUrl(string $path): string
    {
        $base = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        return $base . $path;
    }
}
