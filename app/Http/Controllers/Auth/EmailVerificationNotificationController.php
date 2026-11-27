<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        if (!UserAccess::mailAvailable()) {
            return back()->withErrors(['email' => 'A megerősítő linkhez kézbesítő levelező szükséges.']);
        }
        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface) {
            return back()->withErrors(['email' => 'A levelező nem tudta elküldeni a megerősítő linket.']);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
