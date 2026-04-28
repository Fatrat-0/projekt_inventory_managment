<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    // Felhasználók listázása
    public function index()
    {
        // Kilistázzuk az összes felhasználót, a legújabbal kezdve
        $users = User::latest()->paginate(10);
        return view('users.index', compact('users'));
    }

    // Új felhasználó űrlap
    public function create()
    {
        return view('users.create');
    }

    // Új felhasználó mentése
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return redirect()->route('users.index')->with('success', 'Új munkatárs sikeresen hozzáadva!');
    }

    // Felhasználó törlése
    public function destroy(User $user)
    {
        // Biztonsági védelem: Ne tudd kitörölni saját magad!
        if (auth()->id() === $user->id) {
            return back()->withErrors(['Nem törölheted a saját fiókodat!']);
        }

        $user->delete();
        return redirect()->route('users.index')->with('success', 'Felhasználó törölve.');
    }
}