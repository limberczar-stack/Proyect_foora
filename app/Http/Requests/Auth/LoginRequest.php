<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Intentos fallidos permitidos por email + IP antes de bloquear.
     */
    public const MAX_INTENTOS = 5;

    /**
     * Segundos que dura el bloqueo.
     */
    public const SEGUNDOS_BLOQUEO = 60;

    /**
     * Un único mensaje para email inexistente y contraseña incorrecta,
     * así no se revela qué correos están registrados.
     */
    public const MENSAJE_CREDENCIALES = 'El correo o la contraseña no son correctos.';

    public const MENSAJE_INACTIVO = 'Tu cuenta está desactivada. Pide al administrador del local que la reactive.';

    public const MENSAJE_SIN_PANEL = 'Esta cuenta no tiene acceso al panel del restaurante.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido, por ejemplo nombre@local.pe.',
            'password.required' => 'Escribe tu contraseña.',
        ];
    }

    /**
     * Valida las credenciales y abre la sesión.
     *
     * El estado de la cuenta (inactiva o sin acceso al panel) solo se revela
     * cuando la contraseña es correcta.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($this->throttleKey(), self::SEGUNDOS_BLOQUEO);

            throw ValidationException::withMessages(['auth' => self::MENSAJE_CREDENCIALES]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        if (! $user->activo) {
            throw ValidationException::withMessages(['auth' => self::MENSAJE_INACTIVO]);
        }

        if (! $user->puedeEntrarAlPanel()) {
            throw ValidationException::withMessages(['auth' => self::MENSAJE_SIN_PANEL]);
        }

        Auth::login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_INTENTOS)) {
            return;
        }

        event(new Lockout($this));

        $segundos = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'auth' => "Demasiados intentos. Vuelve a intentarlo en {$segundos} segundos.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
