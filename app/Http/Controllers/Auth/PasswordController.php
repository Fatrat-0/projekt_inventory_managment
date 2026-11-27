<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSession;
use App\Services\{TransferWorkflowOperations, UserAccess};
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash};
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function setup(Request $request)
    {
        return $request->user()->password_change_required
            ? response()->view('auth.first-password')->header('Cache-Control', 'private, no-store')
            : redirect()->route('dashboard');
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => 'Add meg a jelenlegi jelszavadat.',
            'current_password.current_password' => 'A jelenlegi jelszó hibás.',
            'password.required' => 'Adj meg új jelszót.',
            'password.min' => 'Az új jelszó legalább :min karakterből álljon.',
            'password.confirmed' => 'A két jelszó nem egyezik.',
            'password.different' => 'Az új jelszó különbözzön a jelenlegitől.',
        ]);

        $first = (bool) $request->user()->password_change_required;
        $user = DB::transaction(function () use ($request, $validated) {
            $current = app(TransferWorkflowOperations::class)->currentActor($request->user(), exclusive: true);
            if (!Hash::check($validated['current_password'], $current->password)) {
                throw ValidationException::withMessages(['current_password' => 'A jelenlegi jelszó hibás.'])->errorBag('updatePassword');
            }
            UserAccess::revoke($current);
            if ($current->password_change_required) $current->lock_version++;
            $current->forceFill(['password' => $validated['password'], 'password_change_required' => false])->save();

            return $current;
        }, 3);
        Auth::setUser($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureActiveSession::VERSION_KEY, $user->access_version);

        return $first ? redirect()->route('dashboard') : back()->with('status', 'password-updated');
    }
}
