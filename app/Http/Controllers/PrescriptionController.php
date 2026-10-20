<?php

namespace App\Http\Controllers;

use App\Services\PrescriptionService;
use Illuminate\Http\Request;

/** GET /api/patients/{id}/prescriptions (port of prescriptions.controller.js forPatient; the other prescription routes are not used by the frontend). */
class PrescriptionController extends Controller
{
    public function forPatient(Request $request, string $id)
    {
        return response()->json(['prescriptions' => PrescriptionService::listForPatient($id, $request->session()->get('user'))]);
    }
}
