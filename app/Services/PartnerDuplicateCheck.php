<?php

namespace App\Services;

use App\Models\Partner;
use Illuminate\Support\Str;

class PartnerDuplicateCheck
{
    private function normalize(mixed $value, bool $name = false): string
    {
        if (!is_string($value) || mb_strlen($value) > 100) return '';
        return mb_strtolower($name ? Str::squish($value) : Str::trim($value));
    }

    public function check(mixed $name, mixed $email, ?Partner $except = null): array
    {
        $name = $this->normalize($name, true);
        $email = $this->normalize($email);
        if ($name === '' && $email === '') return ['matches' => [], 'total' => 0, 'token' => ''];

        // Normalize in PHP so Unicode case/whitespace matching is identical on MySQL and SQLite.
        $matches = Partner::query()->when($except, fn ($q) => $q->where('id', '<>', $except->id))
            ->orderBy('id')->get(['id', 'name', 'email', 'type', 'archived_at'])->map(function ($partner) use ($name, $email) {
                $sameName = $name !== '' && $name === $this->normalize($partner->name, true);
                $sameEmail = $email !== '' && $email === $this->normalize($partner->email);
                if (!$sameName && !$sameEmail) return null;
                return ['id' => $partner->id, 'name' => $partner->name, 'email' => $partner->email,
                    'type' => Partner::TYPES[$partner->type], 'archived' => $partner->isArchived(),
                    'same_name' => $sameName, 'same_email' => $sameEmail,
                    'edit_url' => route('partners.edit', $partner)];
            })->filter()->values();

        // Confirmation belongs to these inputs and this complete set of matches, never just the first five.
        $signature = json_encode([$except?->id, $name, $email, $matches->all()], JSON_THROW_ON_ERROR);
        return ['matches' => $matches->take(5)->values()->all(), 'total' => $matches->count(),
            'token' => $matches->isEmpty() ? '' : hash_hmac('sha256', $signature, config('app.key'))];
    }
}
