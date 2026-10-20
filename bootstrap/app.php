<?php

use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireStaff;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Login runs before a session exists, so it cannot send a CSRF token (same as the Node app).
        $middleware->validateCsrfTokens(except: ['api/login']);
        // Passwords are taken exactly as typed (Laravel trims every other text field).
        $middleware->trimStrings(except: ['password']);
        $middleware->alias([
            'staff' => RequireStaff::class,
            'role'  => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // clinic-api.js reads {"error": "..."} from every failed /api call.
        $exceptions->render(function (\Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof TokenMismatchException) {
                return response()->json(['error' => 'Invalid CSRF token. Reload the page and try again.'], 403);
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['error' => $e->validator->errors()->first()], 400);
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = match (true) {
                    // A missing route or wrong method gets the generic text; our own abort(404, '...') keeps its message.
                    $status === 405 => 'API endpoint not found.',
                    $status === 404 && ($e->getMessage() === '' || str_starts_with($e->getMessage(), 'The route')) => 'API endpoint not found.',
                    $status === 429 => 'Too many login attempts. Try again in ten minutes.',
                    $e->getMessage() !== '' => $e->getMessage(),
                    default => 'The request could not be completed.',
                };
                return response()->json(['error' => $message], $status === 405 ? 404 : $status);
            }
            if (config('app.debug')) {
                return null; // show Laravel's debug page while you build
            }
            return response()->json(['error' => 'Something went wrong on the server.'], 500);
        });
    })->create();
