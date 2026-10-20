<?php

namespace App\Services;

use App\Support\Check;
use Illuminate\Support\Facades\DB;

/** Statistics pages and the CSV export (port of statistics.service.js and statistics-trends.service.js). */
class StatisticsService
{
    private const STAFF = ['nurse', 'doctor'];

    private static function isBlank(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }

    /** Query values as plain text; arrays and other junk count as empty. */
    public static function clean(array $q): array
    {
        $out = [];
        foreach (['startDate', 'endDate', 'college', 'type', 'scope', 'schoolYear'] as $key) {
            $out[$key] = isset($q[$key]) && is_string($q[$key]) ? $q[$key] : '';
        }
        return $out;
    }

    /**
     * View filter: '' (all combined), 'checkup', 'consultation' or 'screened'.
     * Returns a fixed SQL fragment (never user text), or '' for "all combined".
     */
    public static function scopeCondition(mixed $scope): string
    {
        if (self::isBlank($scope)) {
            return '';
        }
        return match (strtolower(trim((string) $scope))) {
            'checkup' => "v.visit_type = 'Checkup'",
            'consultation' => "v.visit_type = 'Consultation'",
            'screened' => "v.visit_type = 'Checkup' AND v.checkup_result = 'Needs screening'",
            default => abort(400, 'Invalid statistics view.'),
        };
    }

    /**
     * Narrows the period to one school year (schoolYear: an id or 'current'; empty = every year), combined with
     * any start/end date already chosen. Returns the filters with startDate/endDate set.
     */
    public static function narrowToSchoolYear(array $f): array
    {
        $year = SchoolYearService::resolveSchoolYear($f['schoolYear'] ?? '');
        if (! $year) {
            return $f;
        }
        $yStart = $year['startDate'] ?? $year['start_date'] ?? null;
        $yEnd = $year['endDate'] ?? $year['end_date'] ?? null;
        $startDate = $f['startDate'] ?? '';
        $endDate = $f['endDate'] ?? '';
        $startDate = self::isBlank($startDate) || $startDate < $yStart ? $yStart : $startDate;
        if ($yEnd && (self::isBlank($endDate) || $endDate > $yEnd)) {
            $endDate = $yEnd;
        }
        return array_merge($f, ['startDate' => $startDate, 'endDate' => $endDate, 'schoolYear' => '', 'schoolYearLabel' => $year['label'] ?? '']);
    }

    /** Date, department and view conditions shared by the reports. @return array{0: string[], 1: array} */
    private static function filters(array $f, array $base, bool $withCollege = true, bool $withType = false): array
    {
        $where = $base;
        $params = [];
        $scopeSql = self::scopeCondition($f['scope'] ?? '');
        if ($scopeSql !== '') {
            $where[] = $scopeSql;
        }
        if (! self::isBlank($f['startDate'] ?? '')) {
            $where[] = 'v.visit_date >= ?';
            $params[] = $f['startDate'];
        }
        if (! self::isBlank($f['endDate'] ?? '')) {
            $where[] = 'v.visit_date <= ?';
            $params[] = $f['endDate'];
        }
        if ($withCollege && ! self::isBlank($f['college'] ?? '')) {
            $where[] = 'p.college = ?';
            $params[] = $f['college'];
        }
        if ($withType && ! self::isBlank($f['type'] ?? '')) {
            $where[] = 'v.visit_type = ?';
            $params[] = $f['type'];
        }
        return [$where, $params];
    }

    private static function requireStaff(array $actor, string $message = 'Clinic staff access required.'): void
    {
        Check::requireRole($actor, self::STAFF, $message);
    }

    private static function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }

    /** Totals: visits, consultations, checkups, cleared, elevated to screening, unique patients. Cancelled visits and screening copies are left out. */
    public static function summary(array $input, array $actor): array
    {
        self::requireStaff($actor, 'Clinic staff access required for statistics.');
        $f = self::narrowToSchoolYear($input);
        [$where, $params] = self::filters($f, ["v.status = 'completed'", 'v.screened_copy_of IS NULL']);
        $res = DB::selectOne(
            "SELECT COUNT(*) AS total_visits,
                    COUNT(DISTINCT v.patient_id) AS unique_patients,
                    SUM(CASE WHEN v.visit_type = 'Consultation' THEN 1 ELSE 0 END) AS consultations,
                    SUM(CASE WHEN v.visit_type = 'Checkup' THEN 1 ELSE 0 END) AS checkups,
                    SUM(CASE WHEN v.visit_type = 'Checkup' AND (v.checkup_result = 'Cleared' OR v.checkup_result IS NULL) THEN 1 ELSE 0 END) AS checkups_cleared,
                    SUM(CASE WHEN v.visit_type = 'Checkup' AND v.checkup_result = 'Needs screening' THEN 1 ELSE 0 END) AS checkups_screened
             FROM visits v JOIN patients p ON p.id = v.patient_id
             WHERE " . implode(' AND ', $where),
            $params
        );
        $checkups = (int) ($res->checkups ?? 0);
        $screened = (int) ($res->checkups_screened ?? 0);

        // Screening records generated (inside the school year, when one is chosen)
        $copyWhere = ['screened_copy_of IS NOT NULL', "status <> 'cancelled'"];
        $copyParams = [];
        if (! empty($input['schoolYear'])) {
            if (! self::isBlank($f['startDate'] ?? '')) {
                $copyWhere[] = 'visit_date >= ?';
                $copyParams[] = $f['startDate'];
            }
            if (! self::isBlank($f['endDate'] ?? '')) {
                $copyWhere[] = 'visit_date <= ?';
                $copyParams[] = $f['endDate'];
            }
        }
        $copies = DB::selectOne('SELECT COUNT(*) AS screening_copies FROM visits WHERE ' . implode(' AND ', $copyWhere), $copyParams);

        return [
            'totalVisits' => (int) ($res->total_visits ?? 0),
            'uniquePatients' => (int) ($res->unique_patients ?? 0),
            'consultations' => (int) ($res->consultations ?? 0),
            'checkups' => $checkups,
            'checkupOutcomes' => [
                'cleared' => (int) ($res->checkups_cleared ?? 0),
                'needsScreening' => $screened,
                'screeningRatePct' => self::pct($screened, $checkups),
            ],
            'totalScreeningCopies' => (int) ($copies->screening_copies ?? 0),
        ];
    }

    /** Completed visits grouped by diagnosis, ranked by frequency with the share of all diagnosed cases. */
    public static function morbidity(array $input, array $actor): array
    {
        self::requireStaff($actor);
        $f = self::narrowToSchoolYear($input);
        [$where, $params] = self::filters(
            $f, ["v.status = 'completed'", 'v.screened_copy_of IS NULL', "v.diagnosis <> ''", 'v.diagnosis IS NOT NULL'], true, true
        );
        $clause = 'WHERE ' . implode(' AND ', $where);
        $total = (int) (DB::selectOne("SELECT COUNT(*) AS total_diagnosed FROM visits v JOIN patients p ON p.id = v.patient_id {$clause}", $params)->total_diagnosed ?? 0);
        $rows = DB::select(
            "SELECT v.diagnosis, COUNT(*) AS case_count, MAX(v.visit_date) AS last_seen_date
             FROM visits v JOIN patients p ON p.id = v.patient_id
             {$clause}
             GROUP BY v.diagnosis
             ORDER BY case_count DESC, v.diagnosis ASC",
            $params
        );
        return [
            'totalCases' => $total,
            'items' => array_map(fn ($r) => [
                'diagnosis' => $r->diagnosis,
                'caseCount' => (int) $r->case_count,
                'percentage' => self::pct((int) $r->case_count, $total),
                'lastSeenDate' => $r->last_seen_date,
            ], $rows),
        ];
    }

    /** Visits across colleges, gender and outside lab sources. */
    public static function distribution(array $input, array $actor): array
    {
        self::requireStaff($actor);
        $f = self::narrowToSchoolYear($input);
        [$where, $params] = self::filters($f, ["v.status = 'completed'", 'v.screened_copy_of IS NULL'], false);
        $clause = 'WHERE ' . implode(' AND ', $where);
        $colleges = DB::select(
            "SELECT COALESCE(NULLIF(p.college, ''), 'Unspecified') AS college,
                    COUNT(*) AS visit_count,
                    SUM(CASE WHEN v.visit_type = 'Consultation' THEN 1 ELSE 0 END) AS consultations,
                    SUM(CASE WHEN v.visit_type = 'Checkup' THEN 1 ELSE 0 END) AS checkups
             FROM visits v JOIN patients p ON p.id = v.patient_id
             {$clause}
             GROUP BY p.college
             ORDER BY visit_count DESC",
            $params
        );
        $genders = DB::select(
            "SELECT COALESCE(NULLIF(p.gender, ''), 'Unspecified') AS gender, COUNT(*) AS count
             FROM visits v JOIN patients p ON p.id = v.patient_id
             {$clause}
             GROUP BY p.gender
             ORDER BY count DESC",
            $params
        );
        $labs = DB::select(
            "SELECT COALESCE(NULLIF(v.lab_source_name, ''), 'Internal / None') AS lab_source, COUNT(*) AS reports_count
             FROM visits v
             {$clause}
             GROUP BY v.lab_source_name
             ORDER BY reports_count DESC",
            $params
        );
        return [
            'byCollege' => array_map(fn ($r) => [
                'college' => $r->college, 'totalVisits' => (int) $r->visit_count,
                'consultations' => (int) $r->consultations, 'checkups' => (int) $r->checkups,
            ], $colleges),
            'byGender' => array_map(fn ($r) => ['gender' => $r->gender, 'count' => (int) $r->count], $genders),
            'externalLabSources' => array_map(fn ($r) => ['sourceName' => $r->lab_source, 'count' => (int) $r->reports_count], $labs),
        ];
    }

    private static function daysAgo(int $days): string
    {
        return (new \DateTime(SchoolYearService::localDate()))->modify("-{$days} days")->format('Y-m-d');
    }

    private static function setting(string $name, int|float $default): int|float
    {
        $v = env($name);
        $n = is_numeric($v) ? $v + 0 : 0;
        return $n ?: $default;
    }

    /** BMI categories (follow the filters) and this-week vs last-week cases per diagnosis (always the last 14 days). */
    public static function trends(array $input, array $actor): array
    {
        self::requireStaff($actor);
        $f = self::narrowToSchoolYear($input);
        [$bmiWhere, $bmiParams] = self::filters($f, ["v.status = 'completed'", 'v.screened_copy_of IS NULL', 'v.bmi IS NOT NULL']);
        $bmiRows = DB::select(
            "SELECT category, COUNT(*) AS n FROM (SELECT COALESCE(NULLIF(v.bmi_status, ''), 'Unspecified') AS category
               FROM visits v JOIN patients p ON p.id = v.patient_id
              WHERE " . implode(' AND ', $bmiWhere) . ') t
              GROUP BY category
              ORDER BY n DESC',
            $bmiParams
        );

        $today = self::daysAgo(0);
        $thisStart = self::daysAgo(6);
        $lastStart = self::daysAgo(13);
        $lastEnd = self::daysAgo(7);
        $weekWhere = ["v.status = 'completed'", 'v.screened_copy_of IS NULL', "v.diagnosis <> ''",
            'v.diagnosis IS NOT NULL', 'v.visit_date >= ?', 'v.visit_date <= ?'];
        $weekParams = [$thisStart, $lastStart, $lastEnd, $lastStart, $today];
        $scopeSql = self::scopeCondition($f['scope'] ?? '');
        if ($scopeSql !== '') {
            $weekWhere[] = $scopeSql;
        }
        if (! self::isBlank($f['college'] ?? '')) {
            $weekWhere[] = 'p.college = ?';
            $weekParams[] = $f['college'];
        }
        $weekRows = DB::select(
            'SELECT v.diagnosis,
                    SUM(CASE WHEN v.visit_date >= ? THEN 1 ELSE 0 END) AS this_week,
                    SUM(CASE WHEN v.visit_date >= ? AND v.visit_date <= ? THEN 1 ELSE 0 END) AS last_week
               FROM visits v JOIN patients p ON p.id = v.patient_id
              WHERE ' . implode(' AND ', $weekWhere) . '
              GROUP BY v.diagnosis',
            $weekParams
        );

        return [
            'bmi' => array_map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->n], $bmiRows),
            'weekly' => array_map(fn ($r) => [
                'diagnosis' => $r->diagnosis, 'thisWeek' => (int) $r->this_week, 'lastWeek' => (int) $r->last_week,
            ], $weekRows),
            // Outbreak rule: at least minThisWeek cases this week and at least ratio times last week (.env OUTBREAK_MIN_CASES, OUTBREAK_RATIO).
            'rule' => ['minThisWeek' => self::setting('OUTBREAK_MIN_CASES', 5), 'ratio' => self::setting('OUTBREAK_RATIO', 1.5)],
        ];
    }

    private static function q(string $s): string
    {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /** CSV for Excel: summary, diagnosis ranking and visits by college. */
    public static function exportCsv(array $input, array $actor): string
    {
        self::requireStaff($actor);
        $f = self::narrowToSchoolYear($input);
        $summary = self::summary($f, $actor);
        $morbidity = self::morbidity($f, $actor);
        $distribution = self::distribution($f, $actor);

        $lines = [
            '--- UNIVERSITY CLINIC HEALTH SURVEILLANCE REPORT ---',
            'Generated At,' . Check::isoNow(),
            'School Year,' . self::q((string) (($f['schoolYearLabel'] ?? '') ?: 'All school years')),
            "Total Visits,{$summary['totalVisits']}",
            "Consultations,{$summary['consultations']}",
            "Checkups,{$summary['checkups']}",
            "Checkups Cleared,{$summary['checkupOutcomes']['cleared']}",
            "Checkups Elevated to Screening,{$summary['checkupOutcomes']['needsScreening']}",
            '',
            '--- MORBIDITY / DIAGNOSIS RANKING ---',
            'Rank,Diagnosis,Cases,Percentage,Last Seen',
        ];
        foreach ($morbidity['items'] as $i => $item) {
            $lines[] = ($i + 1) . ',' . self::q($item['diagnosis']) . ",{$item['caseCount']},{$item['percentage']}%," . ($item['lastSeenDate'] ?? '');
        }
        $lines[] = '';
        $lines[] = '--- DISTRIBUTION (DSTRBTN) BY COLLEGE ---';
        $lines[] = 'College,Total Visits,Consultations,Checkups';
        foreach ($distribution['byCollege'] as $c) {
            $lines[] = self::q($c['college']) . ",{$c['totalVisits']},{$c['consultations']},{$c['checkups']}";
        }
        return implode("\n", $lines);
    }
}
