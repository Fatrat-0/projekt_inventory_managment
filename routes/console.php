<?php

use Illuminate\Foundation\Inspiring;
use App\Models\User;
use App\Services\UserAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:create-admin {name} {email}', function () {
    if (UserAccess::usableAdmins()->exists()) {
        $this->error('Már van aktív adminisztrátor.');
        return 1;
    }

    $validator = Validator::make([
        'name' => $this->argument('name'),
        'email' => $this->argument('email'),
        'password' => $this->secret('Az adminisztrátor jelszava', false),
        'password_confirmation' => $this->secret('Jelszó megerősítése', false),
    ], [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'confirmed', Password::min(12)],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) $this->error($error);
        return 1;
    }

    $created = DB::transaction(function () use ($validator) {
        if (UserAccess::activeAdmins()->orderBy('id')->lockForUpdate()->get()->contains(fn (User $user) => $user->isUsableAdministrator())) return false;

        User::create($validator->validated() + ['role' => 'admin']);
        return true;
    }, 3);
    if (!$created) {
        $this->error('Már van aktív adminisztrátor.');
        return 1;
    }

    $this->info('Az adminisztrátori fiók létrejött.');
})->purpose('Az első aktív adminisztrátor létrehozása');
