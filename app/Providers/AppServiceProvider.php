<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useTailwind();

        ResetPassword::toMailUsing(function ($user, string $token): MailMessage {
            $url = rtrim(config('app.url'), '/')
                .route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], absolute: false);
            $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Jelszóbeállítás a Készletkezelőben')
                ->greeting('Üdvözlünk!')
                ->line('Saját jelszavadat az alábbi hivatkozáson állíthatod be.')
                ->action('Jelszó beállítása', $url)
                ->line('A hivatkozás egyszer használható, és '.$minutes.' percig érvényes.')
                ->salutation('Készletkezelő');
        });

        Event::listen(Login::class, function (Login $event) {
            if ($event->guard === 'web' && request()->hasSession()) {
                request()->session()->put(EnsureActiveSession::VERSION_KEY, (int) $event->user->access_version);
            }
        });
    }
}
