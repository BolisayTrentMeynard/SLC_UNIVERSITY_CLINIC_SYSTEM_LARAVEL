<?php

namespace App\Http\Controllers;

use App\Services\VisitService;
use Illuminate\Http\Request;

/** /api/visits (port of visits.controller.js). Role rules live in VisitService. */
class VisitController extends Controller
{
    private function actor(Request $request): array
    {
        return $request->session()->get('user');
    }

    /** A query value as text; ?search[]=x and the like count as empty. */
    private function q(Request $request, string $key): string
    {
        $value = $request->query($key);
        return is_string($value) ? $value : '';
    }

    /** Positive whole number from the URL: not digits means the route does not exist (404), 0 or too big is "not found". */
    private function routeId(string $value): int
    {
        if (! preg_match('/^\d+$/D', $value)) {
            abort(404, 'API endpoint not found.');
        }
        if (strlen($value) > 15 || (int) $value < 1) {
            abort(404, 'Record not found.');
        }
        return (int) $value;
    }

    public function list(Request $request)
    {
        $visits = VisitService::list([
            'search' => $this->q($request, 'search'),
            'type' => $this->q($request, 'type'),
            'college' => $this->q($request, 'college'),
            'copies' => $this->q($request, 'copies'),
            'schoolYear' => $this->q($request, 'schoolYear'),
        ], $this->actor($request));
        return response()->json(['visits' => $visits]);
    }

    public function triage(Request $request)
    {
        return response()->json(['visit' => VisitService::triage($request->json()->all(), $this->actor($request))], 201);
    }

    public function queue(Request $request)
    {
        return response()->json(['visits' => VisitService::queue($this->actor($request))]);
    }

    public function screened(Request $request)
    {
        return response()->json(['visits' => VisitService::screened($this->actor($request), $this->q($request, 'schoolYear'))]);
    }

    public function complete(Request $request, string $id)
    {
        $id = $this->routeId($id);
        return response()->json(['visit' => VisitService::complete($id, $request->json()->all(), $this->actor($request))]);
    }

    public function update(Request $request, string $id)
    {
        $id = $this->routeId($id);
        return response()->json(['visit' => VisitService::updateTriage($id, $request->json()->all(), $this->actor($request))]);
    }

    public function cancel(Request $request, string $id)
    {
        $id = $this->routeId($id);
        return response()->json(['visit' => VisitService::cancel($id, $this->actor($request))]);
    }

    public function screen(Request $request, string $id)
    {
        $id = $this->routeId($id);
        return response()->json(['visit' => VisitService::screen($id, $request->json()->all(), $this->actor($request))]);
    }
}
