<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Middleware\EnsureActiveSession;
use App\Services\UserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UserAccess $access): RedirectResponse
    {
        $user = $access->updateProfile($request->user(), $request->safe()->only(['name', 'email']));
        if ((int) $user->access_version !== (int) $request->user()->access_version) {
            $request->session()->regenerate();
            $request->session()->put(EnsureActiveSession::VERSION_KEY, (int) $user->access_version);
            \Illuminate\Support\Facades\Auth::setUser($user);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
