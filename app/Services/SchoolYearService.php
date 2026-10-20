<?php

namespace App\Services;

use App\Support\Check;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * School years (port of backend/services/school-years.service.js).
 * A visit belongs to the school year its visit_date falls in, worked out each time from the dates.
 * Later steps (patients, visits, statistics) call schoolYearFilter() / schoolYearIdFor() from here.
 */
class SchoolYearService
{
    private const MAX_AUTO_YEARS = 25;
    private const MAX_SPAN_DAYS = 548;

    /** Today on the server clock in the app timezone, as YYYY-MM-DD. */
    public static function localDate(): string
    {
        return now()->format('Y-m-d');
    }

    private static function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private static function dayNumber(string $iso): int
    {
        return intdiv(self::utc($iso)->getTimestamp(), 86400);
    }

    private static function shiftDays(string $iso, int $days): string
    {
        return self::utc($iso)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private static function shiftYears(string $iso, int $years): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $iso));
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y + $years, $m)))->format('t');
        return sprintf('%04d-%02d-%02d', $y + $years, $m, min($d, $lastDay));
    }

    /** "2026-2027" from the two dates ("2026" if both are in the same calendar year). */
    public static function defaultLabel(string $start, string $end): string
    {
        $a = substr($start, 0, 4);
        $b = substr($end, 0, 4);
        return $a === $b ? $a : "$a-$b";
    }

    private static function allRows(): array
    {
        return DB::table('school_years')
            ->orderBy('start_date')
            ->get(['id', 'label', 'start_date', 'end_date'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** Creates the next school year(s) once the latest one has ended. Safe to call on every request. */
    private static function ensureUpcoming(): void
    {
        $today = self::localDate();
        for ($i = 0; $i < self::MAX_AUTO_YEARS; $i++) {
            $last = DB::table('school_years')->orderByDesc('start_date')->first();
            if (! $last || $today <= $last->end_date) {
                return;
            }
            $start = self::shiftYears($last->start_date, 1);
            $end = self::shiftYears($last->end_date, 1);
            if ($start <= $last->end_date) {
                $start = self::shiftDays($last->end_date, 1);
            }
            if ($end <= $start) {
                $end = self::shiftDays(self::shiftYears($start, 1), -1);
            }
            $label = self::defaultLabel($start, $end);
            $now = Check::isoNow();
            $insert = fn (string $name) => DB::table('school_years')->insert([
                'label' => $name, 'start_date' => $start, 'end_date' => $end,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            try {
                $insert($label);
            } catch (QueryException $e) {
                if (! Check::isDuplicate($e)) {
                    throw $e;
                }
                try {
                    $insert("$label ($start)");
                } catch (QueryException $again) {
                    if (! Check::isDuplicate($again)) {
                        throw $again;
                    }
                }
            }
        }
    }

    /** Index of today's school year in rows (sorted by start date), or -1 if there are none. */
    private static function currentIndex(array $rows, string $today): int
    {
        $found = -1;
        foreach ($rows as $index => $row) {
            if ($row['start_date'] <= $today) {
                $found = $index;
            }
        }
        return ($found === -1 && count($rows)) ? 0 : $found;
    }

    private static function describe(array $rows, int $index, int $current, ?int $visitCount): array
    {
        $row = $rows[$index];
        $next = $rows[$index + 1] ?? null;
        $status = $index === $current ? 'current' : ($index < $current ? 'past' : 'upcoming');
        return [
            'id' => (int) $row['id'],
            'label' => $row['label'],
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'coversUntil' => $next ? self::shiftDays($next['start_date'], -1) : null,
            'status' => $status,
            'isCurrent' => $index === $current,
            'visitCount' => $visitCount,
        ];
    }

    private static function countVisits(string $startDate, ?string $coversUntil): int
    {
        $q = DB::table('visits')
            ->where('status', '<>', 'cancelled')
            ->whereNull('screened_copy_of')
            ->where('visit_date', '>=', $startDate);
        if ($coversUntil) {
            $q->where('visit_date', '<=', $coversUntil);
        }
        return (int) $q->count();
    }

    /** Every school year, newest first, with its status and how many visits it holds. */
    public static function list(): array
    {
        self::ensureUpcoming();
        return self::buildList();
    }

    private static function buildList(): array
    {
        $rows = self::allRows();
        $today = self::localDate();
        $current = self::currentIndex($rows, $today);
        $years = [];
        foreach ($rows as $i => $_) {
            $base = self::describe($rows, $i, $current, null);
            $base['visitCount'] = self::countVisits($base['startDate'], $base['coversUntil']);
            $years[] = $base;
        }
        return [
            'schoolYears' => array_reverse($years),
            'currentId' => $current >= 0 ? (int) $rows[$current]['id'] : null,
            'today' => $today,
        ];
    }

    /** Id of the school year a date falls in, or null if none exist. Read-only. */
    public static function schoolYearIdFor(string $dateIso): ?int
    {
        $rows = self::allRows();
        if (! $rows) {
            return null;
        }
        $index = self::currentIndex($rows, $dateIso);
        return $index < 0 ? null : (int) $rows[$index]['id'];
    }

    /** '' = none (no filter), 'current', or a numeric id. Returns id, label, startDate, endDate (last day that counts, or null). */
    public static function resolveSchoolYear(mixed $value): ?array
    {
        $wanted = trim((string) ($value ?? ''));
        if ($wanted === '') {
            return null;
        }
        if ($wanted !== 'current' && ! preg_match('/^\d+$/D', $wanted)) {
            abort(400, 'Invalid school year.');
        }
        self::ensureUpcoming();
        $rows = self::allRows();
        if ($wanted === 'current') {
            $index = self::currentIndex($rows, self::localDate());
            if ($index < 0) {
                return null;
            }
        } else {
            $index = -1;
            foreach ($rows as $i => $r) {
                if ((int) $r['id'] === (int) $wanted) {
                    $index = $i;
                }
            }
            if ($index < 0) {
                abort(404, 'School year not found.');
            }
        }
        $next = $rows[$index + 1] ?? null;
        return [
            'id' => (int) $rows[$index]['id'],
            'label' => $rows[$index]['label'],
            'startDate' => $rows[$index]['start_date'],
            'endDate' => $next ? self::shiftDays($next['start_date'], -1) : null,
        ];
    }

    /** Conditions + values that keep only visits inside a school year (used by later steps). */
    public static function schoolYearFilter(mixed $value, string $column = 'v.visit_date'): array
    {
        $year = self::resolveSchoolYear($value);
        if (! $year) {
            return ['year' => null, 'conditions' => [], 'params' => []];
        }
        $conditions = ["$column >= ?"];
        $params = [$year['startDate']];
        if ($year['endDate']) {
            $conditions[] = "$column <= ?";
            $params[] = $year['endDate'];
        }
        return ['year' => $year, 'conditions' => $conditions, 'params' => $params];
    }

    // ---------- doctor edits ----------

    private static function parseYear(array $body): array
    {
        $startDate = Check::dateText($body['startDate'] ?? null, 'Start date');
        $endDate = Check::dateText($body['endDate'] ?? null, 'End date');
        if ($endDate <= $startDate) {
            abort(400, 'The end date must be after the start date.');
        }
        if (self::dayNumber($endDate) - self::dayNumber($startDate) > self::MAX_SPAN_DAYS) {
            abort(400, 'A school year cannot be longer than 18 months. Check the dates.');
        }
        $label = Check::text($body['label'] ?? null, 'School year name', 50) ?: self::defaultLabel($startDate, $endDate);
        return ['label' => $label, 'startDate' => $startDate, 'endDate' => $endDate];
    }

    private static function assertNoOverlap(array $year, int $exceptId): void
    {
        $row = DB::table('school_years')
            ->where('start_date', '<=', $year['endDate'])
            ->where('end_date', '>=', $year['startDate'])
            ->where('id', '<>', $exceptId)
            ->first();
        if ($row) {
            abort(409, "These dates overlap school year {$row->label}.");
        }
    }

    private static function duplicateError(QueryException $e): never
    {
        abort(409, preg_match('/label/i', $e->getMessage())
            ? 'A school year with this name already exists.'
            : 'A school year already starts on this date.');
    }

    /** Does not run ensureUpcoming(), so no surprise year appears after the doctor adds one. */
    private static function described(int $id): ?array
    {
        foreach (self::buildList()['schoolYears'] as $y) {
            if ($y['id'] === $id) {
                return $y;
            }
        }
        return null;
    }

    public static function create(array $body, array $actor): ?array
    {
        Check::requireRole($actor, ['doctor'], 'Only a doctor can manage school years.');
        $year = self::parseYear($body);
        self::assertNoOverlap($year, 0);
        $now = Check::isoNow();
        try {
            $id = DB::table('school_years')->insertGetId([
                'label' => $year['label'], 'start_date' => $year['startDate'], 'end_date' => $year['endDate'],
                'created_at' => $now, 'updated_at' => $now, 'updated_by' => $actor['id'],
            ]);
        } catch (QueryException $e) {
            if (Check::isDuplicate($e)) {
                self::duplicateError($e);
            }
            throw $e;
        }
        return self::described((int) $id);
    }

    public static function update(string $idValue, array $body, array $actor): ?array
    {
        Check::requireRole($actor, ['doctor'], 'Only a doctor can manage school years.');
        $id = Check::idFrom($idValue);
        if (! DB::table('school_years')->where('id', $id)->exists()) {
            abort(404, 'School year not found.');
        }
        $year = self::parseYear($body);
        self::assertNoOverlap($year, $id);
        try {
            DB::table('school_years')->where('id', $id)->update([
                'label' => $year['label'], 'start_date' => $year['startDate'], 'end_date' => $year['endDate'],
                'updated_at' => Check::isoNow(), 'updated_by' => $actor['id'],
            ]);
        } catch (QueryException $e) {
            if (Check::isDuplicate($e)) {
                self::duplicateError($e);
            }
            throw $e;
        }
        return self::described($id);
    }

    /** Only an empty school year can be removed: deleting one with visits would silently move them to the year before. */
    public static function delete(string $idValue, array $actor): array
    {
        Check::requireRole($actor, ['doctor'], 'Only a doctor can manage school years.');
        $id = Check::idFrom($idValue);
        $rows = self::allRows();
        $index = -1;
        foreach ($rows as $i => $r) {
            if ((int) $r['id'] === $id) {
                $index = $i;
            }
        }
        if ($index < 0) {
            abort(404, 'School year not found.');
        }
        $info = self::describe($rows, $index, self::currentIndex($rows, self::localDate()), null);
        $visits = self::countVisits($info['startDate'], $info['coversUntil']);
        if ($visits > 0) {
            abort(409, "School year {$info['label']} has $visits visit record" . ($visits === 1 ? '' : 's') . ', so it cannot be deleted. Edit its dates instead.');
        }
        $enrolled = (int) DB::table('student_enrollments')->where('school_year_id', $id)->count();
        if ($enrolled > 0) {
            abort(409, "School year {$info['label']} has $enrolled enrolled student" . ($enrolled === 1 ? '' : 's') . ', so it cannot be deleted.');
        }
        DB::table('school_years')->where('id', $id)->delete();
        return ['id' => $id, 'deleted' => true];
    }
}
