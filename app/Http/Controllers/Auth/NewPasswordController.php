<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccess;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return response()->view('auth.reset-password', ['request' => $request])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'token.*' => 'Nyisd meg újra a jelszóbeállító linket.',
            'email.*' => 'Adj meg érvényes e-mail-címet.',
            'password.required' => 'Adj meg új jelszót.',
            'password.confirmed' => 'A két jelszó nem egyezik.',
            'password.min' => 'Az új jelszó legalább :min karakterből álljon.',
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = DB::transaction(fn () => Password::reset(
            [...$request->only('email', 'password', 'password_confirmation', 'token'),
                'deactivated_at' => null, fn ($query) => $query->lockForUpdate()],
            function (User $user) use ($request) {
                UserAccess::revoke($user);
                if ($user->password_change_required) $user->lock_version++;
                $user->forceFill([
                    'password' => $request->password,
                    'password_change_required' => false,
                ])->save();

                event(new PasswordReset($user));
            }
        ), 3);

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status === Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', 'A jelszavadat elmentettük. Jelentkezz be az új jelszóval.')
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => 'A jelszóbeállító link érvénytelen vagy lejárt. Kérj új linket.']);
    }
}
