<?php

namespace App\Http\Requests\Auth;

use App\Modules\Identity\Actions\CanSignIn;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * A wrong or missing entry goes back to the login page, never "back": the last URL the session
     * saw may be a file an old tab loaded (a photo, the logo) after it ran out.
     *
     * @var string
     */
    protected $redirectRoute = 'login';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Who may sign in here, and with a password (CanSignIn: the same rule as SSO).
        $tenantId = app(TenantContext::class)->id();
        $rule = app(CanSignIn::class);
        $credentials = $this->only('email', 'password');

        if (! Auth::attemptWhen($credentials, fn ($user) => $rule->handle($user, CanSignIn::PASSWORD, $tenantId), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            // The right password of a company that signs in with SSO only: say so (only once the
            // password is right, so it tells nothing to someone guessing).
            $users = Auth::guard('web')->getProvider();
            $user = $users->retrieveByCredentials($credentials);
            $ssoOnly = $user !== null && $users->validateCredentials($user, $credentials)
                && $rule->handle($user, CanSignIn::SSO, $tenantId);

            throw ValidationException::withMessages([
                'email' => trans($ssoOnly ? 'auth.use_sso' : 'auth.failed'),
            ])->redirectTo(route('login'));
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ])->redirectTo(route('login'));
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
