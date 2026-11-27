<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Password};
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.*' => 'Adj meg érvényes e-mail-címet.',
        ]);

        if (!UserAccess::mailAvailable()) {
            return back()->withErrors(['email' => 'Jelszóbeállításhoz kérj segítséget a rendszergazdától.']);
        }
        try {
            DB::transaction(fn () => Password::sendResetLink(
                [...$request->only('email'), 'deactivated_at' => null, fn ($query) => $query->lockForUpdate()],
            ));
        } catch (TransportExceptionInterface) {
            // Keep the public response independent of account and delivery status.
        }

        return back()->with('status', 'Ha az e-mail-címhez aktív fiók tartozik, elküldjük a jelszóbeállító linket.');
    }
}
