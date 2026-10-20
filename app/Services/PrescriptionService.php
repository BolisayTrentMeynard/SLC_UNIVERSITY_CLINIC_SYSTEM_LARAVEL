<?php

namespace App\Services;

use App\Support\Check;
use Illuminate\Support\Facades\DB;

/**
 * Prescriptions written during an exam (port of the parts of prescriptions.service.js the frontend uses:
 * saving with a visit, and listing for the Student Record page). Dispense, cancel, the pending list and the
 * print slip are not used by the frontend and are skipped.
 */
class PrescriptionService
{
    // Keep in sync with RX_FREQUENCIES in public/js/rx-api.js
    public const FREQUENCIES = [
        'Once daily', 'Twice daily (BID)', 'Three times daily (TID)', 'Four times daily (QID)',
        'Every 4 hours', 'Every 6 hours', 'Every 8 hours', 'At bedtime', 'As needed (PRN)', 'Single dose',
    ];

    private static function wholeNumber(mixed $value, string $field, int $min, int $max): int
    {
        $n = null;
        if (is_int($value)) {
            $n = $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $n = (int) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $f = (float) trim($value);
            if (is_finite($f) && floor($f) === $f && abs($f) < 1e15) {
                $n = (int) $f;
            }
        }
        if ($n === null || $n < $min || $n > $max) {
            abort(400, "{$field} must be a whole number from {$min} to {$max}.");
        }
        return $n;
    }

    /** Validate the prescription part of a visit request. Returns [] when there is none. */
    public static function parseItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $list = array_is_list($raw) ? $raw : ($raw['items'] ?? null);
        if ($list === null) {
            return [];
        }
        if (! is_array($list) || ! array_is_list($list)) {
            abort(400, 'Prescription items must be a list.');
        }
        if (count($list) > 10) {
            abort(400, 'A prescription can have at most 10 medicines.');
        }
        $seen = [];
        $items = [];
        foreach ($list as $i => $item) {
            $label = 'Medicine ' . ($i + 1);
            if (! is_array($item)) {
                abort(400, "{$label} is invalid.");
            }
            $medicine = Check::text($item['medicine'] ?? null, "{$label}: medicine name", 150, true);
            $key = mb_strtolower($medicine);
            if (isset($seen[$key])) {
                abort(400, 'The same medicine is listed twice. Combine it into one line.');
            }
            $seen[$key] = true;
            $frequency = Check::text($item['frequency'] ?? null, "{$label}: frequency", 60, true);
            if (! in_array($frequency, self::FREQUENCIES, true)) {
                abort(400, "{$label}: choose a frequency from the list.");
            }
            $items[] = [
                'medicine' => $medicine,
                'dose' => Check::text($item['dose'] ?? null, "{$label}: dose", 100, true),
                'frequency' => $frequency,
                'durationDays' => self::wholeNumber($item['durationDays'] ?? null, "{$label}: duration (days)", 1, 365),
                'quantity' => self::wholeNumber($item['quantity'] ?? null, "{$label}: quantity", 1, 1000),
                'instructions' => Check::text($item['instructions'] ?? null, "{$label}: instructions", 300),
            ];
        }
        return $items;
    }

    /** Call inside the visit transaction, so a bad prescription cancels the whole exam. */
    public static function createInTx(int $visitId, string $patientId, array $items, array $actor): ?int
    {
        if (! $items) {
            return null;
        }
        DB::insert(
            'INSERT INTO prescriptions(visit_id, patient_id, status, created_by, created_by_name, created_at)
             VALUES(?,?,?,?,?,?)',
            [$visitId, $patientId, 'pending', $actor['id'], $actor['name'], Check::isoNow()]
        );
        $id = (int) DB::getPdo()->lastInsertId();
        foreach ($items as $item) {
            DB::insert(
                'INSERT INTO prescription_items(prescription_id, medicine_name, dose, frequency, duration_days, quantity, instructions)
                 VALUES(?,?,?,?,?,?,?)',
                [$id, $item['medicine'], $item['dose'], $item['frequency'], $item['durationDays'], $item['quantity'], $item['instructions']]
            );
        }
        return $id;
    }

    private const SELECT_RX = 'SELECT rx.*, v.confidential, v.visit_date, v.diagnosis, p.name AS patient_name, p.college
                               FROM prescriptions rx
                               JOIN visits v ON v.id = rx.visit_id
                               JOIN patients p ON p.id = rx.patient_id';

    /** @param object[] $rows */
    private static function withItems(array $rows, array $actor): array
    {
        if (! $rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $items = DB::select(
            "SELECT id, prescription_id, medicine_name, dose, frequency, duration_days, quantity, instructions
             FROM prescription_items WHERE prescription_id IN ({$marks}) ORDER BY id",
            $ids
        );
        return array_map(function ($row) use ($items, $actor) {
            // Same rule as visits: confidential visits are hidden from everyone but doctors.
            $hidden = (int) $row->confidential === 1 && $actor['role'] !== 'doctor';
            return [
                'id' => (int) $row->id,
                'visitId' => (int) $row->visit_id,
                'status' => $row->status,
                'patientId' => $hidden ? null : $row->patient_id,
                'patientName' => $hidden ? 'Confidential patient' : $row->patient_name,
                'college' => $hidden ? '' : $row->college,
                'diagnosis' => $hidden ? '' : $row->diagnosis,
                'visitDate' => $row->visit_date,
                'confidential' => (int) $row->confidential === 1,
                'prescribedBy' => $row->created_by_name,
                'createdAt' => $row->created_at,
                'dispensedBy' => $row->dispensed_by_name ?? null,
                'dispensedAt' => $row->dispensed_at ?? null,
                'cancelledReason' => $row->cancelled_reason ?? null,
                'items' => array_values(array_map(fn ($i) => [
                    'id' => (int) $i->id,
                    'medicine' => $i->medicine_name,
                    'dose' => $i->dose,
                    'frequency' => $i->frequency,
                    'durationDays' => (int) $i->duration_days,
                    'quantity' => (int) $i->quantity,
                    'instructions' => $i->instructions,
                ], array_filter($items, fn ($i) => (int) $i->prescription_id === (int) $row->id))),
            ];
        }, $rows);
    }

    public static function get(int $id, array $actor): array
    {
        $rows = DB::select(self::SELECT_RX . ' WHERE rx.id = ?', [$id]);
        if (! $rows) {
            abort(404, 'Prescription not found.');
        }
        return self::withItems($rows, $actor)[0];
    }

    public static function listForPatient(string $patientId, array $actor): array
    {
        $hide = $actor['role'] === 'doctor' ? '' : 'AND v.confidential = 0';
        $rows = DB::select(
            self::SELECT_RX . " WHERE rx.patient_id = ? {$hide} ORDER BY rx.id DESC LIMIT 50",
            [$patientId]
        );
        return self::withItems($rows, $actor);
    }
}
