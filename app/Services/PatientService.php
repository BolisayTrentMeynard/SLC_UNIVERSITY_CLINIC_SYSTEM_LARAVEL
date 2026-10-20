<?php

namespace App\Services;

use App\Support\Check;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Patients, medical history and lab requirements (port of patients.service.js; the /record and prescriptions routes are not used by the frontend and are skipped). */
class PatientService
{
    private const STAFF = ['nurse', 'doctor'];
    private const STAFF_MESSAGE = 'Clinic staff access required.';

    // Same order as the paper Laboratory Request. `column` holds the date it was marked done (NULL = missing).
    public const REQUIREMENTS = [
        ['key' => 'cbc', 'label' => 'CBC', 'column' => 'cbc_on'],
        ['key' => 'cxrPa', 'label' => 'CXR-PA', 'column' => 'cxr_pa_on'],
        ['key' => 'urinalysis', 'label' => 'Urinalysis', 'column' => 'urinalysis_on'],
        ['key' => 'fecalysis', 'label' => 'Fecalysis', 'column' => 'fecalysis_on'],
        ['key' => 'hepaBScreening', 'label' => 'Hepa-B Screening', 'column' => 'hepa_b_screening_on'],
        ['key' => 'hepaBTiter', 'label' => 'Hepa-B Titer', 'column' => 'hepa_b_titer_on'],
        ['key' => 'bloodType', 'label' => 'Blood Type', 'column' => 'blood_type_on'],
        ['key' => 'pregnancyTest', 'label' => 'Pregnancy Test', 'column' => 'pregnancy_test_on', 'notForMale' => true],
        ['key' => 'drugTest', 'label' => 'Drug Test', 'column' => 'drug_test_on'],
    ];

    // ---------- small helpers ----------

    private static function optionalDate(mixed $value, string $field): ?string
    {
        $date = Check::text($value, $field, 10);
        return $date === '' ? null : Check::dateText($date, $field);
    }

    private static function flag(mixed $value): int
    {
        return $value === true || $value === 'true' ? 1 : 0;
    }

    private static function requireStaff(array $actor): void
    {
        Check::requireRole($actor, self::STAFF, self::STAFF_MESSAGE);
    }

    public static function assertPatient(string $patientId): void
    {
        if (! DB::selectOne('SELECT id FROM patients WHERE id = ?', [$patientId])) {
            abort(404, 'Patient not found.');
        }
    }

    // ---------- lists ----------

    private static function listed(object $r): array
    {
        $row = [
            'id' => $r->id,
            'name' => $r->name,
            'college' => $r->college,
            'course' => $r->course,
            'year' => $r->year,
            'category' => $r->category,
            'dateOfBirth' => $r->date_of_birth ?: null,
            'lastVisit' => $r->last_visit ? substr((string) $r->last_visit, 0, 10) : null,
        ];
        // Laboratory requirements done / needed (only the Student Records page asks for them)
        if (property_exists($r, 'req_done')) {
            $row['requirementsDone'] = (int) $r->req_done;
            $row['requirementsNeeded'] = (int) $r->req_needed;
        }
        return $row;
    }

    /** Search by name or ID for the patient pickers (no paging). */
    public static function search(mixed $search, array $actor, mixed $schoolYear = ''): array
    {
        $term = mb_substr(trim((string) ($search ?? '')), 0, 200);
        $hideConfidential = $actor['role'] !== 'doctor' ? 'AND v.confidential = 0' : '';
        $year = SchoolYearService::resolveSchoolYear($schoolYear);
        $join = $year ? 'JOIN student_enrollments e ON e.patient_id = p.id AND e.school_year_id = ?' : '';
        $side = $year ? 'e' : 'p';
        $rows = DB::select(
            "SELECT p.id, p.name, {$side}.college AS college, {$side}.course AS course, {$side}.year AS year, p.category,
                    DATE_FORMAT(p.date_of_birth, '%Y-%m-%d') AS date_of_birth,
                    (SELECT MAX(v.visit_date) FROM visits v WHERE v.patient_id = p.id {$hideConfidential}) AS last_visit
             FROM patients p {$join}
             WHERE INSTR(LOWER(p.name), LOWER(?)) > 0 OR INSTR(LOWER(p.id), LOWER(?)) > 0
             ORDER BY p.name, p.id LIMIT 500",
            [...($year ? [$year['id']] : []), $term, $term]
        );
        return array_map(fn ($r) => self::listed($r), $rows);
    }

    private static function doneSql(): string
    {
        $parts = array_map(function ($x) {
            $done = "(r.{$x['column']} IS NOT NULL)";
            return ! empty($x['notForMale']) ? "($done AND COALESCE(p.gender, '') <> 'Male')" : $done;
        }, self::REQUIREMENTS);
        return '(' . implode(' + ', $parts) . ')';
    }

    private static function neededSql(): string
    {
        return '(' . count(self::REQUIREMENTS) . " - IF(COALESCE(p.gender, '') = 'Male', 1, 0))";
    }

    /** One page of the Student Records list with real totals and college counts. */
    public static function searchPage(array $q, array $actor): array
    {
        $term = mb_substr(trim((string) ($q['search'] ?? '')), 0, 200);
        $chosen = mb_substr(trim((string) ($q['college'] ?? '')), 0, 255);
        $size = min(max((int) ($q['limit'] ?? 0) ?: 10, 1), 100);
        $skip = max((int) ($q['offset'] ?? 0), 0);
        $hideConfidential = $actor['role'] !== 'doctor' ? 'AND v.confidential = 0' : '';

        $year = SchoolYearService::schoolYearFilter($q['schoolYear'] ?? '');
        $inYear = implode('', array_map(fn ($c) => ' AND ' . $c, $year['conditions']));
        $join = $year['year'] ? 'JOIN student_enrollments e ON e.patient_id = p.id AND e.school_year_id = ?' : '';
        $side = $year['year'] ? 'e' : 'p';
        $joinParams = $year['year'] ? [$year['year']['id']] : [];
        $reqJoin = 'LEFT JOIN student_requirements r ON r.patient_id = p.id';
        $done = self::doneSql();
        $needed = self::neededSql();
        $reqFilter = match ((string) ($q['requirements'] ?? '')) {
            'incomplete' => " AND $done < $needed",
            'complete' => " AND $done >= $needed",
            default => '',
        };

        $rows = DB::select(
            "SELECT p.id, p.name, {$side}.college AS college, {$side}.course AS course, {$side}.year AS year, p.category,
                    DATE_FORMAT(p.date_of_birth, '%Y-%m-%d') AS date_of_birth,
                    (SELECT MAX(v.visit_date) FROM visits v WHERE v.patient_id = p.id {$hideConfidential}{$inYear}) AS last_visit,
                    {$done} AS req_done, {$needed} AS req_needed
             FROM patients p {$join} {$reqJoin}
             WHERE (INSTR(LOWER(p.name), LOWER(?)) > 0 OR INSTR(LOWER(p.id), LOWER(?)) > 0)
               AND (? = '' OR {$side}.college = ?){$reqFilter}
             ORDER BY p.name, p.id LIMIT {$size} OFFSET {$skip}",
            [...$year['params'], ...$joinParams, $term, $term, $chosen, $chosen]
        );
        $groups = DB::select(
            "SELECT {$side}.college AS college, COUNT(*) AS n FROM patients p {$join} {$reqJoin}
             WHERE (INSTR(LOWER(p.name), LOWER(?)) > 0 OR INSTR(LOWER(p.id), LOWER(?)) > 0){$reqFilter}
             GROUP BY {$side}.college ORDER BY {$side}.college",
            [...$joinParams, $term, $term]
        );
        $all = array_sum(array_map(fn ($g) => (int) $g->n, $groups));
        $match = null;
        foreach ($groups as $g) {
            if (mb_strtolower((string) $g->college) === mb_strtolower($chosen)) {
                $match = $g;
                break;
            }
        }
        return [
            'patients' => array_map(fn ($r) => self::listed($r), $rows),
            'total' => $chosen !== '' ? ($match ? (int) $match->n : 0) : $all,
            'all' => $all,
            'colleges' => array_values(array_map(
                fn ($g) => ['name' => $g->college, 'count' => (int) $g->n],
                array_filter($groups, fn ($g) => $g->college)
            )),
        ];
    }

    // ---------- identity ----------

    private static function parseIdentity(array $b): array
    {
        $lastName = Check::text($b['lastName'] ?? null, 'Last name', 100, true);
        $firstName = Check::text($b['firstName'] ?? null, 'First name', 100, true);
        $middleName = Check::text($b['middleName'] ?? null, 'Middle name', 100);
        $gender = Check::text($b['gender'] ?? null, 'Gender', 10);
        if ($gender !== '' && ! in_array($gender, ['Male', 'Female'], true)) {
            abort(400, 'Select a valid gender.');
        }
        $dateOfBirth = self::optionalDate($b['dateOfBirth'] ?? null, 'Date of birth');
        $category = ($b['category'] ?? null) === 'employee' ? 'employee' : 'student';
        $name = implode(' ', array_filter([$firstName, $middleName, $lastName], fn ($s) => $s !== ''));
        if (mb_strlen($name) > 200) {
            abort(400, 'Patient name is too long.');
        }
        return [
            'name' => $name, 'lastName' => $lastName, 'firstName' => $firstName, 'middleName' => $middleName,
            'college' => Check::text($b['college'] ?? null, 'College', 255),
            'course' => Check::text($b['course'] ?? null, 'Course', 100),
            'year' => Check::text($b['year'] ?? null, 'Year', 20),
            'category' => $category,
            'gender' => $gender !== '' ? $gender : null,
            'dateOfBirth' => $dateOfBirth,
            'civilStatus' => Check::text($b['civilStatus'] ?? null, 'Civil status', 20),
            'nationality' => Check::text($b['nationality'] ?? null, 'Nationality', 50) ?: 'Filipino',
            'contactNumber' => Check::text($b['contactNumber'] ?? null, 'Contact number', 30),
            'address' => Check::text($b['address'] ?? null, 'Address', 300),
            'guardianName' => Check::text($b['guardianName'] ?? null, 'Guardian name', 200),
            'guardianContact' => Check::text($b['guardianContact'] ?? null, 'Guardian contact', 30),
        ];
    }

    // ---------- enrollment (one student list per school year) ----------

    /** With $fields the year's details are saved (updated if already enrolled); without, the student's current details are copied. */
    public static function enrollPatient(string $patientId, ?int $schoolYearId, ?array $fields = null): bool
    {
        if (! $schoolYearId) {
            return false;
        }
        $now = Check::isoNow();
        if ($fields) {
            $n = DB::affectingStatement(
                'INSERT INTO student_enrollments(patient_id, school_year_id, college, course, year, created_at)
                 VALUES(?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE college = VALUES(college), course = VALUES(course), year = VALUES(year)',
                [$patientId, $schoolYearId, $fields['college'], $fields['course'], $fields['year'], $now]
            );
            return $n === 1;
        }
        $n = DB::affectingStatement(
            'INSERT IGNORE INTO student_enrollments(patient_id, school_year_id, college, course, year, created_at)
             SELECT id, ?, college, course, year, ? FROM patients WHERE id = ?',
            [$schoolYearId, $now, $patientId]
        );
        return $n === 1;
    }

    /** A student who gets a visit is on the list of the school year the visit is dated in (used by the visits step). */
    public static function enrollForVisit(string $patientId, ?string $visitDate): void
    {
        $yearId = SchoolYearService::schoolYearIdFor($visitDate ?: SchoolYearService::localDate());
        self::enrollPatient($patientId, $yearId, null);
    }

    public static function register(array $body, array $actor): array
    {
        self::requireStaff($actor);
        $id = Check::text($body['id'] ?? null, 'Patient ID', 50, true);
        if (! preg_match('/^[A-Za-z0-9._-]+$/D', $id)) {
            abort(400, 'Patient ID may only contain letters, numbers, dots, dashes, and underscores.');
        }
        $f = self::parseIdentity($body);
        $target = SchoolYearService::resolveSchoolYear('current');   // new students go on today's school year list
        $reEnrolled = false;
        try {
            DB::transaction(function () use ($id, $f, $target, &$reEnrolled) {
                $found = DB::selectOne('SELECT id FROM patients WHERE id = ? FOR UPDATE', [$id]);
                if (! $found) {
                    DB::insert(
                        'INSERT INTO patients(id, name, college, course, year, category, last_name, first_name,
                           middle_name, gender, date_of_birth, civil_status, nationality, contact_number, address,
                           guardian_name, guardian_contact)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            $id, $f['name'], $f['college'], $f['course'], $f['year'], $f['category'], $f['lastName'],
                            $f['firstName'], $f['middleName'], $f['gender'], $f['dateOfBirth'], $f['civilStatus'],
                            $f['nationality'], $f['contactNumber'], $f['address'], $f['guardianName'], $f['guardianContact'],
                        ]
                    );
                    self::enrollPatient($id, $target['id'] ?? null, $f);
                    return;
                }
                // Already in the system from an earlier school year: enroll them again instead of rejecting the ID.
                if (! $target) {
                    abort(409, 'A patient with this ID already exists.');
                }
                if (DB::selectOne('SELECT 1 AS x FROM student_enrollments WHERE patient_id = ? AND school_year_id = ?', [$id, $target['id']])) {
                    abort(409, "This student is already enrolled in school year {$target['label']}.");
                }
                // Only the school-year details change; the rest of the student's profile is left alone.
                DB::update(
                    "UPDATE patients SET college = IF(? = '', college, ?), course = IF(? = '', course, ?), year = IF(? = '', year, ?)
                     WHERE id = ?",
                    [$f['college'], $f['college'], $f['course'], $f['course'], $f['year'], $f['year'], $id]
                );
                self::enrollPatient($id, $target['id'], $f);
                $reEnrolled = true;
            });
        } catch (QueryException $e) {
            if (Check::isDuplicate($e)) {
                abort(409, 'A patient with this ID already exists.');
            }
            throw $e;
        }
        return [
            'id' => $id, 'name' => $f['name'], 'college' => $f['college'], 'course' => $f['course'],
            'year' => $f['year'], 'category' => $f['category'],
            'reEnrolled' => $reEnrolled, 'schoolYear' => $target['label'] ?? null,
        ];
    }

    private static function patientOf(object $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'category' => $r->category,
            'lastName' => $r->last_name ?: '', 'firstName' => $r->first_name ?: '', 'middleName' => $r->middle_name ?: '',
            'college' => $r->college ?: '', 'course' => $r->course ?: '', 'year' => $r->year ?: '',
            'gender' => $r->gender ?: '', 'dateOfBirth' => $r->date_of_birth ?: '',
            'civilStatus' => $r->civil_status ?: '', 'nationality' => $r->nationality ?: '',
            'contactNumber' => $r->contact_number ?: '', 'address' => $r->address ?: '',
            'guardianName' => $r->guardian_name ?: '', 'guardianContact' => $r->guardian_contact ?: '',
        ];
    }

    public static function get(string $id): array
    {
        $row = DB::selectOne(
            "SELECT id, name, category, last_name, first_name, middle_name, college, course, year, gender,
                    DATE_FORMAT(date_of_birth, '%Y-%m-%d') AS date_of_birth, civil_status, nationality,
                    contact_number, address, guardian_name, guardian_contact
             FROM patients WHERE id = ?",
            [$id]
        );
        if (! $row) {
            abort(404, 'Patient not found.');
        }
        return self::patientOf($row);
    }

    /** Nurse edits a student. The ID never changes; the full name is rebuilt from its parts. */
    public static function update(string $id, array $body, array $actor): array
    {
        Check::requireRole($actor, ['nurse'], 'Only a nurse can perform this action.');
        self::assertPatient($id);
        $f = self::parseIdentity($body);
        DB::update(
            'UPDATE patients SET name=?, last_name=?, first_name=?, middle_name=?, college=?, course=?, year=?,
               gender=?, date_of_birth=?, civil_status=?, nationality=?, contact_number=?, address=?,
               guardian_name=?, guardian_contact=?
             WHERE id=?',
            [$f['name'], $f['lastName'], $f['firstName'], $f['middleName'], $f['college'], $f['course'], $f['year'],
             $f['gender'], $f['dateOfBirth'], $f['civilStatus'], $f['nationality'], $f['contactNumber'], $f['address'],
             $f['guardianName'], $f['guardianContact'], $id]
        );
        // The college, course and year level also change on this school year's list, if the student is on it.
        $current = SchoolYearService::resolveSchoolYear('current');
        if ($current) {
            DB::update(
                'UPDATE student_enrollments SET college = ?, course = ?, year = ? WHERE patient_id = ? AND school_year_id = ?',
                [$f['college'], $f['course'], $f['year'], $id, $current['id']]
            );
        }
        return self::get($id);
    }

    // ---------- past medical history ----------

    private static function historyOf(?object $row): array
    {
        $r = $row ?? new \stdClass();
        $g = fn (string $k) => $r->$k ?? null;
        return [
            'asthma' => (int) $g('asthma') === 1,
            'allergy' => (int) $g('allergy') === 1,
            'allergyDetails' => $g('allergy_details') ?: '',
            'febrileConvulsion' => (int) $g('febrile_convulsion') === 1,
            'faintingSpell' => (int) $g('fainting_spell') === 1,
            'epilepsy' => (int) $g('epilepsy') === 1,
            'vaccines' => $g('vaccines') ?: '',
            'otherCondition' => $g('other_condition') ?: '',
            'asthmaLastAttack' => $g('asthma_last_attack') ?: '',
            'asthmaMedicine' => $g('asthma_medicine') ?: '',
            'allergyFood' => $g('allergy_food') ?: '',
            'allergyMedicine' => $g('allergy_medicine') ?: '',
            'hepatitisB' => (int) $g('hepatitis_b') === 1,
        ];
    }

    public static function getHistory(string $patientId): array
    {
        self::assertPatient($patientId);
        return self::historyOf(DB::selectOne('SELECT * FROM patient_medical_history WHERE patient_id = ?', [$patientId]));
    }

    public static function saveHistory(string $patientId, array $b, array $actor): array
    {
        self::requireStaff($actor);
        self::assertPatient($patientId);
        DB::insert(
            'INSERT INTO patient_medical_history(patient_id, asthma, allergy, allergy_details,
               febrile_convulsion, fainting_spell, epilepsy, vaccines, other_condition, updated_at, updated_by,
               asthma_last_attack, asthma_medicine, allergy_food, allergy_medicine, hepatitis_b)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE asthma = VALUES(asthma), allergy = VALUES(allergy),
               allergy_details = VALUES(allergy_details), febrile_convulsion = VALUES(febrile_convulsion),
               fainting_spell = VALUES(fainting_spell), epilepsy = VALUES(epilepsy),
               vaccines = VALUES(vaccines), other_condition = VALUES(other_condition),
               asthma_last_attack = VALUES(asthma_last_attack), asthma_medicine = VALUES(asthma_medicine),
               allergy_food = VALUES(allergy_food), allergy_medicine = VALUES(allergy_medicine),
               hepatitis_b = VALUES(hepatitis_b),
               updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [
                $patientId, self::flag($b['asthma'] ?? null), self::flag($b['allergy'] ?? null),
                Check::text($b['allergyDetails'] ?? null, 'Allergy details', 300),
                self::flag($b['febrileConvulsion'] ?? null), self::flag($b['faintingSpell'] ?? null), self::flag($b['epilepsy'] ?? null),
                Check::text($b['vaccines'] ?? null, 'Vaccines', 300),
                Check::text($b['otherCondition'] ?? null, 'Other condition', 300),
                Check::isoNow(), $actor['id'],
                Check::text($b['asthmaLastAttack'] ?? null, 'Date of last asthma attack', 50),
                Check::text($b['asthmaMedicine'] ?? null, 'Asthma medicine', 300),
                Check::text($b['allergyFood'] ?? null, 'Food allergy', 300),
                Check::text($b['allergyMedicine'] ?? null, 'Allergy medicine', 300),
                self::flag($b['hepatitisB'] ?? null),
            ]
        );
        return self::getHistory($patientId);
    }

    // ---------- laboratory requirements ----------

    private static function requirementsOf(?object $row, ?string $gender): array
    {
        $r = (array) ($row ?? []);
        $male = $gender === 'Male';
        $items = array_map(function ($x) use ($r, $male) {
            $date = $r[$x['column']] ?? null;
            return [
                'key' => $x['key'],
                'label' => $x['label'],
                'applicable' => ! (! empty($x['notForMale']) && $male),
                'done' => (bool) $date,
                'date' => $date ?: null,
            ];
        }, self::REQUIREMENTS);
        $needed = array_values(array_filter($items, fn ($i) => $i['applicable']));
        $completed = count(array_filter($needed, fn ($i) => $i['done']));
        return [
            'items' => $items,
            'completed' => $completed,
            'required' => count($needed),
            'complete' => $completed === count($needed),
        ];
    }

    public static function getRequirements(string $patientId): array
    {
        self::assertPatient($patientId);
        $row = DB::selectOne('SELECT * FROM student_requirements WHERE patient_id = ?', [$patientId]);
        $person = DB::selectOne('SELECT gender FROM patients WHERE id = ?', [$patientId]);
        return self::requirementsOf($row, $person->gender);
    }

    /** A box that stays ticked keeps its first date; a new tick gets today; unticked goes back to missing; a missing key is left as it was. */
    public static function saveRequirements(string $patientId, array $b, array $actor): array
    {
        self::requireStaff($actor);
        self::assertPatient($patientId);
        foreach (self::REQUIREMENTS as $x) {
            if (array_key_exists($x['key'], $b) && $b[$x['key']] !== null && ! is_bool($b[$x['key']])) {
                abort(400, "{$x['label']} must be ticked or not ticked.");
            }
        }
        $today = SchoolYearService::localDate();
        DB::transaction(function () use ($patientId, $b, $actor, $today) {
            $before = (array) (DB::selectOne('SELECT * FROM student_requirements WHERE patient_id = ? FOR UPDATE', [$patientId]) ?? []);
            $dates = array_map(function ($x) use ($b, $before, $today) {
                $had = $before[$x['column']] ?? null;
                if (! array_key_exists($x['key'], $b) || $b[$x['key']] === null) {
                    return $had ?: null;
                }
                if (! $b[$x['key']]) {
                    return null;
                }
                return $had ?: $today;
            }, self::REQUIREMENTS);
            $columns = array_column(self::REQUIREMENTS, 'column');
            DB::insert(
                'INSERT INTO student_requirements(patient_id, ' . implode(', ', $columns) . ', updated_at, updated_by)
                 VALUES(?, ' . implode(', ', array_fill(0, count($columns), '?')) . ', ?, ?)
                 ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $columns)) . ',
                   updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
                [$patientId, ...$dates, Check::isoNow(), $actor['id']]
            );
        });
        return self::getRequirements($patientId);
    }
}
