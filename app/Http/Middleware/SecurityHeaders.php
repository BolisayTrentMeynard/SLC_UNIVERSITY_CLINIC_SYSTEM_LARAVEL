<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of backend/middleware/securityHeaders.js (Node).
 * Sent with every response that goes through Laravel (pages and /api).
 */
class SecurityHeaders
{
    // Strict policy for the app's own pages and API: no inline or third-party scripts.
    // Inline styles stay allowed because several pages use style="" attributes.
    private const APP_CSP = [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "worker-src 'self' blob:",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ];

    // Printable certificate and prescription pages carry their own small print script.
    private const PRINT_CSP = [
        "default-src 'none'",
        "script-src 'unsafe-inline'",
        "style-src 'unsafe-inline'",
        "img-src 'self' data:",
        "frame-ancestors 'self'",
        "base-uri 'none'",
        "form-action 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isPrintPage = (bool) preg_match('#^/(certificates|prescriptions)/[^/]+/(print|preview)/?$#', $request->getPathInfo());
        $csp = implode('; ', $isPrintPage ? self::PRINT_CSP : self::APP_CSP);

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $csp);

        // Only when the site is served over HTTPS (SESSION_SECURE_COOKIE=true in .env).
        if (config('session.secure')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
