<?php

namespace App\Services;

use App\Support\Check;
use Illuminate\Support\Facades\DB;

/**
 * Visits: nurse triage, doctor exam queue, completing an exam, Screening copies and the log lists
 * (port of visits.service.js). The old doctor "New Visit" form (POST /api/visits) is not used by the
 * frontend any more and is skipped.
 */
class VisitService
{
    public const VISIT_TYPES = ['Consultation', 'Checkup'];
    public const CHECKUP_RESULTS = ['Cleared', 'Needs screening'];
    private const STAFF = ['nurse', 'doctor'];

    // ---------- small helpers ----------

    private static function flag(mixed $value): int
    {
        return $value === true || $value === 'true' ? 1 : 0;
    }

    /** Only null and empty text count as blank (a typed 0 is a value). */
    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Number from JSON (number or numeric text) within min..max, else 400. */
    private static function number(mixed $value, string $field, float $min, float $max): float
    {
        $n = null;
        if (is_int($value) || is_float($value)) {
            $n = (float) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $n = (float) trim($value);
        }
        if ($n === null || ! is_finite($n) || $n < $min || $n > $max) {
            abort(400, "{$field} must be a number from " . self::show($min) . ' to ' . self::show($max) . '.');
        }
        return $n;
    }

    private static function show(float $n): string
    {
        return floor($n) === $n ? (string) (int) $n : (string) $n;
    }

    private static function requireRole(array $actor, array $roles, string $message): void
    {
        Check::requireRole($actor, $roles, $message);
    }

    // ---------- vitals ----------

    public static function parseBp(mixed $value): array
    {
        $bp = Check::text($value, 'Blood pressure', 10, true);
        if (! preg_match('/^(\d{2,3})\/(\d{2,3})$/D', $bp, $m)) {
            abort(400, 'Blood pressure must look like 120/80.');
        }
        $systolic = (int) $m[1];
        $diastolic = (int) $m[2];
        if ($systolic < 60 || $systolic > 260 || $diastolic < 30 || $diastolic > 160 || $systolic <= $diastolic) {
            abort(400, 'Enter a realistic blood pressure, such as 120/80.');
        }
        return [$systolic, $diastolic];
    }

    public static function bpStatusOf(int $systolic, int $diastolic): string
    {
        if ($systolic >= 140 || $diastolic >= 90) {
            return 'Hypertension Stage 2';
        }
        if ($systolic >= 130 || $diastolic >= 80) {
            return 'Hypertension Stage 1';
        }
        if ($systolic >= 120) {
            return 'Elevated';
        }
        return 'Normal';
    }

    /** @return array{0: float, 1: string} BMI to one decimal and its label */
    public static function bmiOf(float $weightKg, float $heightCm): array
    {
        $meters = $heightCm / 100;
        $bmi = floor(($weightKg / ($meters * $meters)) * 10 + 0.5) / 10;   // same rounding as JavaScript Math.round
        $status = $bmi < 18.5 ? 'Underweight' : ($bmi < 25 ? 'Normal' : ($bmi < 30 ? 'Overweight' : 'Obese'));
        return [$bmi, $status];
    }

    /**
     * Blood pressure, weight and height. A Consultation needs all three. For a Checkup each one is optional,
     * but a value that is typed must still be realistic. BMI needs weight and height; BP status needs BP.
     */
    public static function parseVitals(array $body, bool $required): array
    {
        $v = ['systolic' => null, 'diastolic' => null, 'weight' => null, 'height' => null,
            'bmi' => null, 'bmiStatus' => null, 'bpStatus' => null];
        if ($required || ! self::isBlank($body['bp'] ?? null)) {
            [$v['systolic'], $v['diastolic']] = self::parseBp($body['bp'] ?? null);
            $v['bpStatus'] = self::bpStatusOf($v['systolic'], $v['diastolic']);
        }
        if ($required || ! self::isBlank($body['weight'] ?? null)) {
            $v['weight'] = self::number($body['weight'] ?? null, 'Weight', 1, 500);
        }
        if ($required || ! self::isBlank($body['height'] ?? null)) {
            $v['height'] = self::number($body['height'] ?? null, 'Height', 30, 300);
        }
        if ($v['weight'] !== null && $v['height'] !== null) {
            [$v['bmi'], $v['bmiStatus']] = self::bmiOf($v['weight'], $v['height']);
        }
        return $v;
    }

    // ---------- rows to JSON ----------

    private static function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /** Confidential visits are hidden from everyone except doctors. */
    public static function rowToVisit(object $r, string $role): array
    {
        $hidden = (int) $r->confidential === 1 && $role !== 'doctor';
        $dob = $r->date_of_birth ?? null;
        $age = ! $hidden && $dob && strtotime((string) $dob) !== false
            ? (int) floor((time() - strtotime((string) $dob)) / 31557600)
            : null;
        return [
            'id' => (int) $r->id,
            'patientId' => $hidden ? null : $r->patient_id,
            'patientName' => $hidden ? 'Student (hidden)' : $r->patient_name,
            'college' => $hidden ? '' : $r->college,
            'visitType' => $r->visit_type,
            'visitDate' => $r->visit_date,
            'complaint' => $hidden ? 'Confidential' : $r->complaint,
            'diagnosis' => $hidden ? 'Confidential' : $r->diagnosis,
            'notes' => $hidden ? '' : ($r->notes ?? ''),
            'bp' => $hidden || $r->systolic === null || $r->diastolic === null ? '' : "{$r->systolic}/{$r->diastolic}",
            'bpStatus' => $hidden ? '' : ($r->bp_status ?? ''),
            'weightKg' => $hidden ? null : self::num($r->weight_kg),
            'heightCm' => $hidden ? null : self::num($r->height_cm),
            'bmi' => $hidden ? null : self::num($r->bmi),
            'bmiStatus' => $hidden ? '' : ($r->bmi_status ?? ''),
            'confidential' => (int) $r->confidential === 1,
            'createdByName' => $r->created_by_name,
            'status' => $r->status,
            'isScreened' => (int) $r->is_screened === 1,
            'screenedNote' => $hidden ? '' : ($r->screened_note ?? ''),
            'screenedAt' => $r->screened_at ?: null,
            'doctorName' => $r->doctor_name ?: null,
            'nurseName' => $r->nurse_name ?: null,
            'pulseRate' => $hidden ? null : self::num($r->pulse_rate),
            'initialDiagnosis' => $hidden ? '' : ($r->initial_diagnosis ?? ''),
            'externalLabsAttached' => (int) $r->external_labs_attached === 1,
            'sentToHospital' => (int) $r->sent_to_hospital === 1,
            'course' => $hidden ? '' : ($r->course ?? ''),
            'age' => $age,
            'actionsTaken' => $hidden ? '' : ($r->actions_taken ?? ''),
            'soapObjective' => $hidden ? '' : ($r->soap_objective ?? ''),
            'sex' => $hidden ? '' : ($r->gender ?? ''),
            'yearLevel' => $hidden ? '' : ($r->year ?? ''),
            'visitNumber' => ((int) $r->visit_number) ?: 1,
            'bodyTemperature' => $hidden ? null : self::num($r->body_temperature),
            'screenedCopyOf' => $r->screened_copy_of === null ? null : (int) $r->screened_copy_of,
            'urgent' => (int) $r->urgent === 1,
            'nurseNotes' => $hidden ? '' : ($r->nurse_notes ?? ''),
            'labSourceName' => $hidden ? '' : ($r->lab_source_name ?? ''),
            'labReceivedAt' => $hidden ? null : ($r->lab_received_at ?: null),
            'labSourceLocation' => $hidden ? '' : ($r->lab_source_location ?? ''),
            'checkupResult' => $r->checkup_result ?: null,
            'isScreeningCopy' => $r->screened_copy_of !== null,
            'createdAt' => $r->created_at,
        ];
    }

    private const SELECT_VISIT = "SELECT v.*, p.name AS patient_name, p.college, p.course, p.year, p.gender, p.date_of_birth,
                                         (SELECT COUNT(*) FROM visits vc WHERE vc.patient_id = v.patient_id AND vc.screened_copy_of IS NULL AND vc.status <> 'cancelled' AND vc.id <= v.id) AS visit_number
                                  FROM visits v JOIN patients p ON p.id = v.patient_id";

    private static function fetchVisit(int $id, array $actor): array
    {
        $row = DB::selectOne(self::SELECT_VISIT . ' WHERE v.id = ?', [$id]);
        if (! $row) {
            abort(404, 'Visit not found.');
        }
        return self::rowToVisit($row, $actor['role']);
    }

    // ---------- lists ----------

    /** Completed visits for Checkup, Consultation and Student Record pages. */
    public static function list(array $q, array $actor): array
    {
        $search = (string) ($q['search'] ?? '');
        $type = (string) ($q['type'] ?? '');
        $college = (string) ($q['college'] ?? '');
        $copies = (string) ($q['copies'] ?? '');
        $where = ["v.status = 'completed'"];
        $params = [];
        // copies=exclude hides the Screening copies, so a screened checkup is not listed twice.
        if ($copies === 'exclude') {
            $where[] = 'v.screened_copy_of IS NULL';
        }
        // schoolYear = an id or 'current' keeps one school year; empty keeps every year.
        $year = SchoolYearService::schoolYearFilter($q['schoolYear'] ?? '');
        array_push($where, ...$year['conditions']);
        array_push($params, ...$year['params']);
        $term = mb_substr(trim($search), 0, 200);
        if ($term !== '') {
            $where[] = '(INSTR(LOWER(p.name), LOWER(?)) > 0 OR INSTR(LOWER(p.id), LOWER(?)) > 0)';
            array_push($params, $term, $term);
            // Stop non-doctors from finding hidden patients by searching their name.
            if ($actor['role'] !== 'doctor') {
                $where[] = 'v.confidential = 0';
            }
        }
        if ($type !== '') {
            if (! in_array($type, self::VISIT_TYPES, true)) {
                abort(400, 'Invalid visit type.');
            }
            $where[] = 'v.visit_type = ?';
            $params[] = $type;
        }
        if ($college !== '') {
            $where[] = 'p.college = ?';
            $params[] = mb_substr($college, 0, 255);
        }
        $rows = DB::select(
            self::SELECT_VISIT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY v.visit_date DESC, v.id DESC LIMIT 200',
            $params
        );
        return array_map(fn ($r) => self::rowToVisit($r, $actor['role']), $rows);
    }

    /** Doctor's exam queue: triaged visits, urgent first, then oldest first. */
    public static function queue(array $actor): array
    {
        self::requireRole($actor, ['doctor'], 'Only a doctor can view the exam queue.');
        $rows = DB::select(
            self::SELECT_VISIT . " WHERE v.status = 'triaged' ORDER BY v.urgent DESC, v.visit_date ASC, v.id ASC LIMIT 200"
        );
        return array_map(fn ($r) => self::rowToVisit($r, $actor['role']), $rows);
    }

    public static function screened(array $actor, mixed $schoolYear = ''): array
    {
        self::requireRole($actor, self::STAFF, 'Clinic staff access required.');
        $year = SchoolYearService::schoolYearFilter($schoolYear);
        $more = implode('', array_map(fn ($c) => ' AND ' . $c, $year['conditions']));
        $rows = DB::select(
            self::SELECT_VISIT . " WHERE v.is_screened = 1{$more} ORDER BY v.screened_at DESC, v.id DESC LIMIT 200",
            $year['params']
        );
        return array_map(fn ($r) => self::rowToVisit($r, $actor['role']), $rows);
    }

    // ---------- nurse triage ----------

    /**
     * Date the outside lab result was handed to the nurse. Today unless the nurse picks another day.
     * It cannot be in the future or more than a year old. No lab clinic name means no lab date.
     */
    private static function parseLabDate(mixed $value, string $labSourceName): ?string
    {
        if ($labSourceName === '') {
            return null;
        }
        $today = SchoolYearService::localDate();
        if (self::isBlank($value)) {
            return $today;
        }
        $date = Check::dateText($value, 'Lab received date');
        $oldest = (new \DateTime($today))->modify('-1 year')->format('Y-m-d');
        if ($date > $today) {
            abort(400, 'The lab received date cannot be in the future.');
        }
        if ($date < $oldest) {
            abort(400, 'The lab received date cannot be more than one year ago.');
        }
        return $date;
    }

    /** Everything the nurse types at triage. Used by both "record" and "edit". */
    private static function parseTriage(array $body): array
    {
        $visitType = Check::text($body['visitType'] ?? null, 'Visit type', 20, true);
        if (! in_array($visitType, self::VISIT_TYPES, true)) {
            abort(400, 'Select a valid visit type.');
        }
        $isConsultation = $visitType === 'Consultation';
        $vitals = self::parseVitals($body, $isConsultation);   // a Checkup may have no vitals
        $pulse = self::isBlank($body['pulseRate'] ?? null) ? null : (int) round(self::number($body['pulseRate'], 'Pulse rate', 20, 250));
        $temperature = self::isBlank($body['temperature'] ?? null) ? null : self::number($body['temperature'], 'Body temperature', 30, 45);
        $visitDate = Check::dateText($body['visitDate'] ?? null, 'Visit date');
        $complaint = Check::text($body['complaint'] ?? null, 'Complaint', 500, $isConsultation);
        $initialDiagnosis = Check::text($body['initialDiagnosis'] ?? null, 'Initial diagnosis', 200);
        $nurseNotes = Check::text($body['nurseNotes'] ?? null, 'Nurse notes', 2000);
        // Only the name of the outside clinic is typed. The results stay on paper, and the date is set by the system.
        $labName = Check::text($body['labSourceName'] ?? null, 'Laboratory / medical center name', 200);
        $labDate = self::parseLabDate($body['labReceivedAt'] ?? null, $labName);
        $labLocation = $labName === '' ? '' : Check::text($body['labSourceLocation'] ?? null, 'Laboratory location', 200);
        return [
            'visitType' => $visitType, 'visitDate' => $visitDate, 'complaint' => $complaint,
            'vitals' => $vitals, 'pulse' => $pulse, 'temperature' => $temperature,
            'initialDiagnosis' => $initialDiagnosis, 'nurseNotes' => $nurseNotes,
            'labSourceName' => $labName, 'labReceivedAt' => $labDate, 'labSourceLocation' => $labLocation,
            'urgent' => self::flag($body['urgent'] ?? null),
            'externalLabsAttached' => self::flag($body['externalLabsAttached'] ?? null),
        ];
    }

    public static function triage(array $body, array $actor): array
    {
        self::requireRole($actor, ['nurse'], 'Only a nurse can record triage.');
        $patientId = Check::text($body['patientId'] ?? null, 'Patient ID', 50, true);
        PatientService::assertPatient($patientId);
        $t = self::parseTriage($body);
        $v = $t['vitals'];
        PatientService::enrollForVisit($patientId, $t['visitDate']);   // on the student list of the school year of the visit
        DB::insert(
            "INSERT INTO visits(patient_id, visit_type, visit_date, complaint, systolic, diastolic,
               weight_kg, height_cm, bmi, bmi_status, bp_status, diagnosis, notes, confidential,
               created_by, created_by_name, created_at, status, nurse_id, nurse_name,
               pulse_rate, initial_diagnosis, external_labs_attached, body_temperature,
               nurse_notes, lab_source_name, lab_received_at, urgent, lab_source_location)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,'','',0,?,?,?,'triaged',?,?,?,?,?,?,?,?,?,?,?)",
            [
                $patientId, $t['visitType'], $t['visitDate'], $t['complaint'], $v['systolic'], $v['diastolic'],
                $v['weight'], $v['height'], $v['bmi'], $v['bmiStatus'], $v['bpStatus'],
                $actor['id'], $actor['name'], Check::isoNow(),
                $actor['id'], $actor['name'], $t['pulse'], $t['initialDiagnosis'],
                $t['labSourceName'] !== '' ? 1 : $t['externalLabsAttached'], $t['temperature'],
                $t['nurseNotes'], $t['labSourceName'], $t['labReceivedAt'], $t['urgent'], $t['labSourceLocation'],
            ]
        );
        return self::fetchVisit((int) DB::getPdo()->lastInsertId(), $actor);
    }

    /** The nurse can correct a visit until the doctor has it; after that it is the doctor's record. */
    public static function updateTriage(int $id, array $body, array $actor): array
    {
        self::requireRole($actor, ['nurse'], 'Only a nurse can edit a visit that is waiting for the doctor.');
        $t = self::parseTriage($body);
        $v = $t['vitals'];
        DB::transaction(function () use ($id, $body, $t, $v) {
            $found = DB::selectOne('SELECT status, lab_source_name, lab_received_at FROM visits WHERE id = ? FOR UPDATE', [$id]);
            if (! $found) {
                abort(404, 'Visit not found.');
            }
            if ($found->status !== 'triaged') {
                abort(409, 'Only a visit that is still waiting for the doctor can be edited.');
            }
            // Keep the original lab date unless the clinic name changed; a cleared name clears the date.
            $sameLab = $t['labSourceName'] === $found->lab_source_name;
            $keepDate = self::isBlank($body['labReceivedAt'] ?? null) && $sameLab && $found->lab_received_at;
            $labDate = $keepDate ? $found->lab_received_at : $t['labReceivedAt'];
            DB::update(
                'UPDATE visits SET visit_type = ?, visit_date = ?, complaint = ?, systolic = ?, diastolic = ?,
                   weight_kg = ?, height_cm = ?, bmi = ?, bmi_status = ?, bp_status = ?, pulse_rate = ?,
                   initial_diagnosis = ?, external_labs_attached = ?, body_temperature = ?, nurse_notes = ?,
                   lab_source_name = ?, lab_source_location = ?, lab_received_at = ?, urgent = ?
                 WHERE id = ?',
                [
                    $t['visitType'], $t['visitDate'], $t['complaint'], $v['systolic'], $v['diastolic'],
                    $v['weight'], $v['height'], $v['bmi'], $v['bmiStatus'], $v['bpStatus'], $t['pulse'],
                    $t['initialDiagnosis'], $t['labSourceName'] !== '' ? 1 : $t['externalLabsAttached'],
                    $t['temperature'], $t['nurseNotes'],
                    $t['labSourceName'], $t['labSourceLocation'], $labDate, $t['urgent'], $id,
                ]
            );
        });
        return self::fetchVisit($id, $actor);
    }

    /** A visit entered by mistake is cancelled, not deleted, and only while it is still waiting. */
    public static function cancel(int $id, array $actor): array
    {
        self::requireRole($actor, ['nurse'], 'Only a nurse can cancel a visit that is waiting for the doctor.');
        $changed = DB::update("UPDATE visits SET status = 'cancelled' WHERE id = ? AND status = 'triaged'", [$id]);
        if (! $changed) {
            self::fetchVisit($id, $actor);   // 404 if it does not exist
            abort(409, 'Only a visit that is still waiting for the doctor can be cancelled.');
        }
        return self::fetchVisit($id, $actor);
    }

    // ---------- doctor exam ----------

    public static function complete(int $id, array $body, array $actor): array
    {
        self::requireRole($actor, ['doctor'], 'Only a doctor can complete a visit.');
        // Free text, as in the clinic's Excel record. The doctor decides what is confidential.
        $diagnosis = Check::text($body['diagnosis'] ?? null, 'Clinical diagnosis', 500, true);
        $soapObjective = Check::text($body['soapObjective'] ?? null, 'Pertinent P.E. and laboratory findings', 5000);
        $actionsTaken = Check::text($body['actionsTaken'] ?? null, 'Action taken', 5000);
        $confidential = self::flag($body['confidential'] ?? null);
        $items = PrescriptionService::parseItems($body['prescription'] ?? null);   // validated before anything is saved
        $screenedNote = Check::text($body['screenedNote'] ?? null, 'Screening note', 300);
        $wantsScreening = ($body['putToScreening'] ?? null) === true;
        // The doctor picks Cleared or Needs screening. Older screens only send the screening tick, so fall back to it.
        $checkupResult = self::isBlank($body['checkupResult'] ?? null)
            ? ($wantsScreening ? 'Needs screening' : 'Cleared')
            : $body['checkupResult'];
        if (! in_array($checkupResult, self::CHECKUP_RESULTS, true)) {
            abort(400, 'Checkup result must be Cleared or Needs screening.');
        }
        $sentToHospital = self::flag($body['sentToHospital'] ?? null);

        $rxId = DB::transaction(function () use (
            $id, $actor, $diagnosis, $soapObjective, $actionsTaken, $confidential, $items,
            $screenedNote, $checkupResult, $sentToHospital
        ) {
            $visit = DB::selectOne('SELECT id, patient_id, visit_type, status FROM visits WHERE id = ? FOR UPDATE', [$id]);
            if (! $visit) {
                abort(404, 'Visit not found.');
            }
            if ($visit->status !== 'triaged') {
                abort(409, 'This visit has already been completed.');
            }
            $isCheckup = $visit->visit_type === 'Checkup';
            $screen = $checkupResult === 'Needs screening' && $isCheckup;
            $now = Check::isoNow();
            // The original stays a plain Checkup; Screening gets its own copy (below).
            DB::update(
                "UPDATE visits SET diagnosis = ?, confidential = ?, status = 'completed', checkup_result = ?,
                   doctor_id = ?, doctor_name = ?, completed_at = ?,
                   soap_objective = ?, actions_taken = ?, sent_to_hospital = ?
                 WHERE id = ?",
                [
                    $diagnosis, $confidential, $isCheckup ? $checkupResult : null, $actor['id'], $actor['name'], $now,
                    $soapObjective, $actionsTaken, $sentToHospital, $id,
                ]
            );
            if ($screen) {
                self::copyToScreening($id, $actor, $screenedNote, $now);
            }
            return PrescriptionService::createInTx($id, $visit->patient_id, $items, $actor);
        });
        $result = self::fetchVisit($id, $actor);
        $result['prescription'] = $rxId ? PrescriptionService::get($rxId, $actor) : null;
        return $result;
    }

    // ---------- screening ----------

    /** A screened checkup is saved twice: the original stays in Checkup, and this copy goes to Screening. */
    private static function copyToScreening(int $visitId, array $actor, string $note, string $when): void
    {
        DB::insert(
            'INSERT INTO visits(patient_id, visit_type, visit_date, complaint, systolic, diastolic,
               weight_kg, height_cm, bmi, bmi_status, bp_status, diagnosis, notes, confidential,
               created_by, created_by_name, created_at, status, nurse_id, nurse_name, doctor_id, doctor_name,
               pulse_rate, initial_diagnosis, external_labs_attached, body_temperature,
               soap_subjective, soap_objective, soap_assessment, soap_plan, actions_taken,
               sent_to_hospital, completed_at, is_screened, screened_by, screened_at, screened_note,
               nurse_notes, lab_source_name, lab_received_at, urgent,
               screened_copy_of)
             SELECT patient_id, visit_type, visit_date, complaint, systolic, diastolic,
               weight_kg, height_cm, bmi, bmi_status, bp_status, diagnosis, notes, confidential,
               created_by, created_by_name, created_at, status, nurse_id, nurse_name, doctor_id, doctor_name,
               pulse_rate, initial_diagnosis, external_labs_attached, body_temperature,
               soap_subjective, soap_objective, soap_assessment, soap_plan, actions_taken,
               sent_to_hospital, completed_at, 1, ?, ?, ?,
               nurse_notes, lab_source_name, lab_received_at, urgent, id
               FROM visits WHERE id = ?',
            [$actor['id'], $when, $note, $visitId]
        );
        // The Screening copy keeps the outside lab location of the original visit.
        DB::update(
            'UPDATE visits c JOIN visits o ON o.id = c.screened_copy_of SET c.lab_source_location = o.lab_source_location WHERE o.id = ?',
            [$visitId]
        );
    }

    public static function screen(int $id, array $body, array $actor): array
    {
        self::requireRole($actor, ['doctor'], 'Only a doctor can put a checkup to screening.');
        $note = Check::text($body['screenedNote'] ?? null, 'Screening note', 300);
        DB::transaction(function () use ($id, $actor, $note) {
            $found = DB::selectOne(
                "SELECT id FROM visits WHERE id = ? AND visit_type = 'Checkup' AND status = 'completed'
                   AND is_screened = 0 AND screened_copy_of IS NULL FOR UPDATE",
                [$id]
            );
            if (! $found) {
                abort(409, 'Only a completed checkup that is not yet screened can be put to screening.');
            }
            if (DB::selectOne('SELECT id FROM visits WHERE screened_copy_of = ? LIMIT 1', [$id])) {
                abort(409, 'This checkup is already in Screening.');
            }
            self::copyToScreening($id, $actor, $note, Check::isoNow());
            DB::update("UPDATE visits SET checkup_result = 'Needs screening' WHERE id = ?", [$id]);
        });
        return self::fetchVisit($id, $actor);
    }
}
