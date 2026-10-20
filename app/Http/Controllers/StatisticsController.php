<?php

namespace App\Http\Controllers;

use App\Services\StatisticsService;
use Illuminate\Http\Request;

/** /api/statistics (port of statistics.controller.js and statistics-trends.controller.js). Role rules live in StatisticsService. */
class StatisticsController extends Controller
{
    private function actor(Request $request): array
    {
        return $request->session()->get('user');
    }

    private function filters(Request $request): array
    {
        return StatisticsService::clean($request->query->all());
    }

    public function summary(Request $request)
    {
        return response()->json(['summary' => StatisticsService::summary($this->filters($request), $this->actor($request))]);
    }

    public function morbidity(Request $request)
    {
        return response()->json(['morbidity' => StatisticsService::morbidity($this->filters($request), $this->actor($request))]);
    }

    public function distribution(Request $request)
    {
        return response()->json(['distribution' => StatisticsService::distribution($this->filters($request), $this->actor($request))]);
    }

    public function trends(Request $request)
    {
        return response()->json(['trends' => StatisticsService::trends($this->filters($request), $this->actor($request))]);
    }

    public function export(Request $request)
    {
        $csv = StatisticsService::exportCsv($this->filters($request), $this->actor($request));
        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="clinic-statistics.csv"',
        ]);
    }
}
