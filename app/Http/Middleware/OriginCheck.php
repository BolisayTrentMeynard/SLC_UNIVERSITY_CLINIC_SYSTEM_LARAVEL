<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of originCheck() in backend/middleware/csrf.js.
 * Refuses login and every change (POST/PUT/DELETE) that comes from another site.
 * GET and HEAD are not checked. A request with no Origin header (curl, same-site forms) passes.
 */
class OriginCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            return $this->refuse('Cross-site requests are not allowed.');
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return $next($request);
        }

        $parts = parse_url($origin);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return $this->refuse('Invalid request origin.');
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '';
        if ($host !== $request->headers->get('Host') || ($path !== '' && $path !== '/')) {
            return $this->refuse('Cross-site requests are not allowed.');
        }

        $allowed = rtrim((string) config('clinic.origin'), '/');
        if ($allowed !== '' && rtrim($origin, '/') !== $allowed) {
            return $this->refuse('Cross-site requests are not allowed.');
        }

        return $next($request);
    }

    private function refuse(string $message): Response
    {
        return response()->json(['error' => $message], 403);
    }
}
