<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TransferWorkflowOperations;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $user = app(TransferWorkflowOperations::class)->currentActor($request->user(), exclusive: true);
            abort_unless(hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash')), 403);
            if (!$user->hasVerifiedEmail() && $user->markEmailAsVerified()) event(new Verified($user));
        }, 3);

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
