<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PrescriptionAdminController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\PrescriptionPageController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VisitController;
use App\Http\Middleware\OriginCheck;
use App\Http\Middleware\RequireStaff;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Every route below gets the security headers (added in bootstrap/app.php, outside everything else).
Route::group([], function () {

    // Login page
    Route::get('/', fn () => response()->file(public_path('index.html')));

    // Health checks: no login, no patient data (same as Node /healthz and /readyz).
    Route::get('/healthz', fn () => response()->json(['status' => 'ok']));
    Route::get('/readyz', function () {
        try {
            DB::select('SELECT 1');
            return response()->json(['status' => 'ready']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'database unavailable'], 503);
        }
    });

    // API (same URLs the frontend already calls)
    // OriginCheck runs first: login and every change from another site is refused.
    Route::prefix('api')->middleware(OriginCheck::class)->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,10');

        Route::middleware('staff')->group(function () {
            Route::get('/session', [AuthController::class, 'session']);
            Route::post('/logout', [AuthController::class, 'logout']);

            // School years: staff can read; only a doctor can change (checked in the controller).
            Route::get('/school-years', [SchoolYearController::class, 'list']);
            Route::post('/school-years', [SchoolYearController::class, 'create']);
            Route::put('/school-years/{id}', [SchoolYearController::class, 'update']);
            Route::delete('/school-years/{id}', [SchoolYearController::class, 'remove']);

            // Users: doctor only (checked in the controller).
            Route::get('/users', [UserController::class, 'list']);
            Route::post('/users', [UserController::class, 'create']);
            Route::put('/users/{id}', [UserController::class, 'update']);
            Route::delete('/users/{id}', [UserController::class, 'deactivate']);

            // Patients: staff can read; nurse-only and staff-only rules are checked in PatientService.
            Route::get('/patients', [PatientController::class, 'search']);
            Route::post('/patients', [PatientController::class, 'register']);
            Route::get('/patients/{id}', [PatientController::class, 'read']);
            Route::put('/patients/{id}', [PatientController::class, 'update']);
            Route::get('/patients/{id}/history', [PatientController::class, 'history']);
            Route::put('/patients/{id}/history', [PatientController::class, 'updateHistory']);
            Route::get('/patients/{id}/requirements', [PatientController::class, 'requirements']);
            Route::put('/patients/{id}/requirements', [PatientController::class, 'updateRequirements']);
            Route::get('/patients/{id}/prescriptions', [PrescriptionController::class, 'forPatient']);

        // Prescriptions: any staff can list and dispense; only a doctor can cancel (checked in the controller).
        Route::get('/prescriptions', [PrescriptionAdminController::class, 'list']);
        Route::post('/prescriptions/{id}/dispense', [PrescriptionAdminController::class, 'dispense'])->whereNumber('id');
        Route::post('/prescriptions/{id}/cancel', [PrescriptionAdminController::class, 'cancel'])->whereNumber('id');

            // Visits: the fixed paths (triage, queue, screened) come before /visits/{id}/... so they are never mistaken for an id.
            // Role rules are checked in VisitService (nurse triages and cancels, doctor examines and screens).
            Route::post('/visits/triage', [VisitController::class, 'triage']);
            Route::get('/visits/queue', [VisitController::class, 'queue']);
            Route::get('/visits/screened', [VisitController::class, 'screened']);
            Route::post('/visits/{id}/complete', [VisitController::class, 'complete']);
            Route::post('/visits/{id}/screen', [VisitController::class, 'screen']);
            Route::post('/visits/{id}/cancel', [VisitController::class, 'cancel']);
            Route::put('/visits/{id}', [VisitController::class, 'update']);
            Route::get('/visits', [VisitController::class, 'list']);

            // Statistics: staff only (checked in StatisticsService).
            Route::get('/statistics/summary', [StatisticsController::class, 'summary']);
            Route::get('/statistics/morbidity', [StatisticsController::class, 'morbidity']);
            Route::get('/statistics/distribution', [StatisticsController::class, 'distribution']);
            Route::get('/statistics/trends', [StatisticsController::class, 'trends']);
            Route::get('/statistics/export', [StatisticsController::class, 'export']);
        });
    });

    // Printable prescription slip (the "Print slip" link on a student record). Staff only.
    Route::get('/prescriptions/{id}/print', [PrescriptionPageController::class, 'print'])
        ->whereNumber('id')
        ->middleware('staff');

    // Staff pages: /doctor/queue.html, /nurse/assess.html, ...
    Route::get('/{folder}/{page}.html', [PageController::class, 'show'])
        ->whereIn('folder', ['doctor', 'nurse'])
        ->where('page', '[A-Za-z0-9_-]+');

    // Unknown /api paths answer like Node does: not logged in = 401, a change without a CSRF token = 403, otherwise 404 JSON.
    $unknownApi = function (Request $request) {
        $notFound = fn () => response()->json(['error' => 'API endpoint not found.'], 404);
        return app(RequireStaff::class)->handle(
            $request,
            fn ($req) => app(ValidateCsrfToken::class)->handle($req, $notFound)
        );
    };
    Route::match(['POST', 'PUT', 'PATCH', 'DELETE'], '/api/{any}', $unknownApi)
        ->where('any', '.*')
        ->withoutMiddleware(ValidateCsrfToken::class); // checked by hand above, after the login check

    // Anything else: unknown /api paths as above, other paths a plain 404 (both carry the security headers).
    Route::fallback(function (Request $request) use ($unknownApi) {
        if ($request->is('api/*')) {
            return $unknownApi($request);
        }
        abort(404);
    });
});
