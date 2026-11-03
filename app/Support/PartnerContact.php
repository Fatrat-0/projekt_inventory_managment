<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Database\Connection;

class PartnerContact
{
    /** Match the same blank characters that Laravel trims from submitted fields. */
    public static function presentSql(string $column, Connection $connection): array
    {
        if (!in_array($column, ['email', 'phone'], true)) throw new \InvalidArgumentException('Invalid contact column.');
        if ($connection->getDriverName() === 'sqlite') {
            // SQLite's built-in trim/replace cannot remove NUL. Use the same normalizer as HTTP input.
            $connection->getPdo()->sqliteCreateFunction('partner_contact_present',
                fn ($value) => (int) (Str::trim((string) ($value ?? '')) !== ''), 1, \PDO::SQLITE_DETERMINISTIC);
            return ["partner_contact_present($column) = 1", []];
        }
        $characters = "\t\n\v\f\r \u{0085}\u{1680}\u{2028}\u{2029}".preg_replace_callback(
            '/\\\\x\{([0-9a-f]+)\}/i', fn ($match) => mb_chr(hexdec($match[1])), Str::INVISIBLE_CHARACTERS);
        $source = "REPLACE(COALESCE($column, ''), CHAR(0), '')";
        return ["$source REGEXP ?", ['[^'.$characters.']']];
    }
}
