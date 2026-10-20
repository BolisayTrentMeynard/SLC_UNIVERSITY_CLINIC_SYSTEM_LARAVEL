<?php

namespace App\Http\Controllers;

use App\Services\SchoolYearService;
use Illuminate\Http\Request;

class SchoolYearController extends Controller
{
    public function list()
    {
        return response()->json(SchoolYearService::list());
    }

    public function create(Request $request)
    {
        $year = SchoolYearService::create($request->json()->all(), $request->session()->get('user'));
        return response()->json(['schoolYear' => $year], 201);
    }

    public function update(Request $request, string $id)
    {
        $year = SchoolYearService::update($id, $request->json()->all(), $request->session()->get('user'));
        return response()->json(['schoolYear' => $year]);
    }

    public function remove(Request $request, string $id)
    {
        return response()->json(SchoolYearService::delete($id, $request->session()->get('user')));
    }
}
