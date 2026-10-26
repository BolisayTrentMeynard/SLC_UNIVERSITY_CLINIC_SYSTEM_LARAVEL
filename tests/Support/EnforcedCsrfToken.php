<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Laravel skips CSRF checking while it runs under phpunit (runningUnitTests()), so the
 * "wrong CSRF token is refused" rule could never be tested. This copy always checks, like a real request.
 */
class EnforcedCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
