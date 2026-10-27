<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /api/prescriptions: list, dispense, cancel (port of prescriptions.controller.js and prescriptions.service.js). */
class PrescriptionAdminController extends Controller
{
    private const SELECT = 'SELECT rx.*, v.confidential, v.visit_date, v.diagnosis, p.name AS patient_name, p.college
                            FROM prescriptions rx
                            JOIN visits v ON v.id = rx.visit_id
                            JOIN patients p ON p.id = rx.patient_id';

    private function actor(Request $request): array
    {
        return $request->session()->get('user');
    }

    private function now(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function error(string $message, int $status)
    {
        return response()->json(['error' => $message], $status);
    }

    /** Turn rows into the API shape; confidential visits are hidden from everyone but doctors. */
    private function present(array $rows, array $actor): array
    {
        if (! $rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $items = DB::select(
            'SELECT id, prescription_id, medicine_name, dose, frequency, duration_days, quantity, instructions
             FROM prescription_items WHERE prescription_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id',
            $ids
        );

        return array_map(function ($row) use ($items, $actor) {
            $hidden = (int) $row->confidential === 1 && ($actor['role'] ?? '') !== 'doctor';
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
                'dispensedBy' => $row->dispensed_by_name,
                'dispensedAt' => $row->dispensed_at,
                'cancelledReason' => $row->cancelled_reason,
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

    private function one(int $id, array $actor): ?array
    {
        $rows = DB::select(self::SELECT . ' WHERE rx.id = ?', [$id]);
        return $rows ? $this->present($rows, $actor)[0] : null;
    }

    public function list(Request $request)
    {
        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'dispensed', 'cancelled'], true)) {
            return $this->error('Invalid status.', 400);
        }
        $rows = DB::select(self::SELECT . ' WHERE rx.status = ? ORDER BY rx.id DESC LIMIT 100', [$status]);
        return response()->json(['prescriptions' => $this->present($rows, $this->actor($request))]);
    }

    /** Marks the prescription as handed out. Any staff member can do it. */
    public function dispense(Request $request, int $id)
    {
        $actor = $this->actor($request);
        $changed = DB::update(
            "UPDATE prescriptions SET status = 'dispensed', dispensed_by = ?, dispensed_by_name = ?, dispensed_at = ?
             WHERE id = ? AND status = 'pending'",
            [$actor['id'] ?? null, $actor['name'] ?? '', $this->now(), $id]
        );
        if (! $changed) {
            $row = DB::selectOne('SELECT status FROM prescriptions WHERE id = ?', [$id]);
            if (! $row) {
                return $this->error('Prescription not found.', 404);
            }
            return $this->error("This prescription is already {$row->status}.", 409);
        }
        return response()->json(['prescription' => $this->one($id, $actor)]);
    }

    /** Doctor only; needs a reason; only pending prescriptions can be cancelled. */
    public function cancel(Request $request, int $id)
    {
        $actor = $this->actor($request);
        if (($actor['role'] ?? '') !== 'doctor') {
            return $this->error('Only a doctor can cancel a prescription.', 403);
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return $this->error('Reason is required.', 400);
        }
        if (mb_strlen($reason) > 300) {
            return $this->error('Reason must be at most 300 characters.', 400);
        }
        $changed = DB::update(
            "UPDATE prescriptions SET status = 'cancelled', cancelled_reason = ? WHERE id = ? AND status = 'pending'",
            [$reason, $id]
        );
        if (! $changed) {
            return $this->error('Only pending prescriptions can be cancelled.', 409);
        }
        return response()->json(['prescription' => $this->one($id, $actor)]);
    }
}
