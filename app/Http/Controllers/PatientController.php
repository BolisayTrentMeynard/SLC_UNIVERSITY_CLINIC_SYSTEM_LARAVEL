<?php

namespace App\Http\Controllers;

use App\Services\PatientService;
use Illuminate\Http\Request;

/** /api/patients (port of patients.controller.js). Role rules live in PatientService. */
class PatientController extends Controller
{
    private function actor(Request $request): array
    {
        return $request->session()->get('user');
    }

    public function search(Request $request)
    {
        // With ?limit= the Student Records page gets one page plus the real totals; without it, the plain list.
        if ($request->query->has('limit')) {
            return response()->json(PatientService::searchPage($request->query->all(), $this->actor($request)));
        }
        return response()->json(['patients' => PatientService::search(
            $request->query('search'), $this->actor($request), $request->query('schoolYear', '')
        )]);
    }

    public function register(Request $request)
    {
        return response()->json(['patient' => PatientService::register($request->json()->all(), $this->actor($request))], 201);
    }

    public function read(string $id)
    {
        return response()->json(['patient' => PatientService::get($id)]);
    }

    public function update(Request $request, string $id)
    {
        return response()->json(['patient' => PatientService::update($id, $request->json()->all(), $this->actor($request))]);
    }

    public function history(string $id)
    {
        return response()->json(['history' => PatientService::getHistory($id)]);
    }

    public function updateHistory(Request $request, string $id)
    {
        return response()->json(['history' => PatientService::saveHistory($id, $request->json()->all(), $this->actor($request))]);
    }

    public function requirements(string $id)
    {
        return response()->json(['requirements' => PatientService::getRequirements($id)]);
    }

    public function updateRequirements(Request $request, string $id)
    {
        return response()->json(['requirements' => PatientService::saveRequirements($id, $request->json()->all(), $this->actor($request))]);
    }
}
