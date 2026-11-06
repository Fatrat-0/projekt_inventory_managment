<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $user) {
            if (ReturnQualityAction::where('user_id', $user->id)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'user' => 'A fiók minősítési naplóhoz kapcsolódik, ezért nem törölhető.',
                ]);
            }
        });
    }

    public function delete()
    {
        try {
            return parent::delete();
        } catch (\Illuminate\Database\QueryException $exception) {
            // An initial quality action may commit between the precheck and DELETE.
            // The FK protects it; use a current read even inside an older outer snapshot.
            if (ReturnQualityAction::where('user_id', $this->id)->sharedLock()->first(['id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'user' => 'A fiók minősítési naplóhoz kapcsolódik, ezért nem törölhető.',
                ]);
            }
            throw $exception;
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dashboard_layout' => 'array',
            'dashboard_layout_version' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
