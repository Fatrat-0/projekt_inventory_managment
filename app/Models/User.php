<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
                if ($this->initiatedTransferIntents()->sharedLock()->first(['id']) || $this->transferWorkflowEvents()->sharedLock()->first(['id'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'user' => 'A fiók átmozgatási igényhez vagy annak eseménynaplójához kapcsolódik, ezért nem törölhető.',
                    ]);
                }
                if ($this->warehouseMemberships()->sharedLock()->first(['id'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'user' => 'A fiók raktári tagsággal rendelkezik. Törlés előtt szüntesd meg a tagságot.',
                    ]);
                }
            }
            throw $exception;
        }
    }

    public function warehouseMemberships(): HasMany
    {
        return $this->hasMany(WarehouseMembership::class);
    }

    public function initiatedTransferIntents(): HasMany
    {
        return $this->hasMany(TransferIntent::class, 'requested_by');
    }

    public function transferWorkflowEvents(): HasMany
    {
        return $this->hasMany(TransferWorkflowEvent::class, 'actor_id');
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
