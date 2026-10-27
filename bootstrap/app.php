<?php

use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireStaff;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        // Security headers go on the OUTSIDE of everything, so an error answered by an inner step (a CSRF refusal, a 401) still carries them.
        $middleware->web(prepend: [SecurityHeaders::class]);
        // Passwords are taken exactly as typed (Laravel trims every other text field).
        $middleware->trimStrings(except: ['password']);
        $middleware->alias([
            'staff' => RequireStaff::class,
            'role'  => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // clinic-api.js reads {"error": "..."} from every failed call; the Node app also answers every error with JSON.
        $exceptions->render(function (\Throwable $e, $request) {
            $isApi = $request->is('api/*');
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['error' => $e->validator->errors()->first()], 400);
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $notFound = $isApi ? 'API endpoint not found.' : 'File not found.';
                $message = match (true) {
                    // Laravel turns a wrong CSRF token into a 419; Node answers 403 with this text.
                    $status === 419 => 'Invalid CSRF token. Reload the page and try again.',
                    // A missing route or wrong method gets the generic text; our own abort(404, '...') keeps its message.
                    $status === 405 => $notFound,
                    $status === 404 && ($e->getMessage() === '' || str_starts_with($e->getMessage(), 'The route')) => $notFound,
                    $status === 429 => 'Too many login attempts. Try again in ten minutes.',
                    $e->getMessage() !== '' => $e->getMessage(),
                    default => 'The request could not be completed.',
                };
                return response()->json(['error' => $message], match ($status) { 405 => 404, 419 => 403, default => $status });
            }
            if (config('app.debug')) {
                return null; // show Laravel's debug page while you build
            }
            return response()->json(['error' => 'Something went wrong on the server.'], 500);
        });
    })->create();
