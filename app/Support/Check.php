<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;

/** Input checks and role checks shared by every service (port of utils/validators.js and utils/authz.js). */
class Check
{
    public static function text(mixed $value, string $field, int $max, bool $required = false): string
    {
        if ($value === null) {
            $value = '';
        }
        if (! is_string($value)) {
            abort(400, "$field must be text.");
        }
        $result = trim($value);
        if ($required && $result === '') {
            abort(400, "$field is required.");
        }
        if (mb_strlen($result) > $max) {
            abort(400, "$field must be at most $max characters.");
        }
        return $result;
    }

    private static function isRealDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /** Required YYYY-MM-DD date with a custom field name. */
    public static function dateText(mixed $value, string $field): string
    {
        $date = self::text($value, $field, 10, true);
        if (! self::isRealDate($date)) {
            abort(400, 'Enter a valid ' . strtolower($field) . '.');
        }
        return $date;
    }

    /** Positive integer from a URL part, else 404. */
    public static function idFrom(string $value): int
    {
        if (! preg_match('/^\d+$/D', $value) || (int) $value < 1) {
            abort(404, 'Record not found.');
        }
        return (int) $value;
    }

    /** Numeric id in a URL. Not digits means the route does not exist. */
    public static function routeId(string $value): int
    {
        if (! preg_match('/^\d+$/D', $value)) {
            abort(404, 'API endpoint not found.');
        }
        return self::idFrom($value);
    }

    /** Second lock inside the controllers, same messages as the Node services. */
    public static function requireRole(array $user, array $roles, string $message): void
    {
        if (! in_array($user['role'] ?? null, $roles, true)) {
            abort(403, $message);
        }
    }

    /** MySQL "Duplicate entry" (Node's ER_DUP_ENTRY). */
    public static function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** Timestamp text like the Node app stores: 2026-10-19T08:30:00.000Z */
    public static function isoNow(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
