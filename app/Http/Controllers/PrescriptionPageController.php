<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Printable prescription slip: /prescriptions/{id}/print (port of pages.controller.js + prescription.template.js). */
class PrescriptionPageController extends Controller
{
    private static function esc($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    public function print(Request $request, int $id)
    {
        $actor = $request->session()->get('user');

        $rx = DB::selectOne(
            'SELECT rx.*, v.confidential, v.visit_date, p.name AS patient_name, p.college
             FROM prescriptions rx
             JOIN visits v ON v.id = rx.visit_id
             JOIN patients p ON p.id = rx.patient_id
             WHERE rx.id = ?',
            [$id]
        );
        if (!$rx) {
            return response()->json(['error' => 'Prescription not found.'], 404);
        }
        // Confidential visits are visible to doctors only (same rule as the API).
        if ((int) $rx->confidential === 1 && ($actor['role'] ?? '') !== 'doctor') {
            return response()->json(['error' => 'This prescription belongs to a confidential visit.'], 403);
        }

        $items = DB::select(
            'SELECT medicine_name, dose, frequency, duration_days, quantity, instructions
             FROM prescription_items WHERE prescription_id = ? ORDER BY id',
            [$id]
        );

        $e = [self::class, 'esc'];
        $rows = '';
        foreach ($items as $n => $i) {
            $days = (int) $i->duration_days;
            $rows .= '<tr><td>' . ($n + 1) . '</td>'
                . '<td><b>' . $e($i->medicine_name) . '</b><br><small>' . $e($i->instructions) . '</small></td>'
                . '<td>' . $e($i->dose) . '<br>' . $e($i->frequency) . '<br>for ' . $days . ' day' . ($days === 1 ? '' : 's') . '</td>'
                . '<td>' . (int) $i->quantity . '</td></tr>';
        }

        $state = $rx->status === 'dispensed'
            ? 'Dispensed ' . $e(substr((string) $rx->dispensed_at, 0, 10)) . ' by ' . $e($rx->dispensed_by_name)
            : ($rx->status === 'cancelled' ? 'CANCELLED' : 'NOT YET DISPENSED');

        $university = config('clinic.university');
        $clinic = config('clinic.name');

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Prescription RX-' . (int) $rx->id . '</title><style>'
            . 'body{font:14px/1.4 Arial,sans-serif;max-width:720px;margin:24px auto;padding:0 16px;color:#111}'
            . 'h1{font-size:18px;margin:0}h2{font-size:14px;margin:2px 0 14px;font-weight:normal}'
            . 'header{border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:12px}'
            . 'table{width:100%;border-collapse:collapse;margin:12px 0}'
            . 'th,td{border:1px solid #999;padding:6px 8px;text-align:left;vertical-align:top}'
            . '.meta{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}'
            . '.sign{margin-top:48px;width:260px;border-top:1px solid #111;padding-top:4px}'
            . '.state{margin-top:16px;font-weight:bold}'
            . '@media print{.noprint{display:none}}'
            . '</style></head><body>'
            . '<header><h1>' . $e($university) . '</h1><h2>' . $e($clinic) . ' &mdash; Prescription</h2></header>'
            . '<div class="meta"><div><b>Patient:</b> ' . $e($rx->patient_name) . ' (' . $e($rx->patient_id) . ')<br><b>College:</b> '
            . ($rx->college ? $e($rx->college) : '&mdash;') . '</div>'
            . '<div><b>Rx no.:</b> RX-' . (int) $rx->id . '<br><b>Date:</b> ' . $e(substr((string) $rx->visit_date, 0, 10)) . '</div></div>'
            . '<table><thead><tr><th>#</th><th>Medicine</th><th>Dose / frequency</th><th>Qty</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="sign">' . $e($rx->created_by_name) . '<br><small>Prescribing doctor</small></div>'
            . '<p class="state">' . $state . '</p>'
            . '<p class="noprint"><button onclick="window.print()">Print</button></p>'
            . '</body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
