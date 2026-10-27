<?php

namespace Tests\Feature;

class SecurityTest extends ClinicTestCase
{
    public function test_api_and_login_page_carry_the_security_headers(): void
    {
        foreach (['/api/session', '/'] as $uri) {
            $response = $this->api(null, 'GET', $uri);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $uri);
            $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'), $uri);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'), $uri);
            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self'", $csp, $uri);
            $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $csp)[0], $uri);   // no inline scripts
            $this->assertFalse($response->headers->has('Strict-Transport-Security'), $uri);             // plain HTTP
        }
    }

    public function test_unknown_api_paths_answer_json_404_with_headers(): void
    {
        $nurse = $this->nurse();

        // Logged in: a plain JSON 404 that still carries the security headers.
        $get = $this->api($nurse, 'GET', '/api/nothing');
        $get->assertStatus(404)->assertJson(['error' => 'API endpoint not found.']);
        $this->assertNotNull($get->headers->get('Content-Security-Policy'));
        $this->api($nurse, 'POST', '/api/nothing', [])->assertStatus(404)->assertJson(['error' => 'API endpoint not found.']);
        $this->api($nurse, 'DELETE', '/api/nothing')->assertStatus(404)->assertJson(['error' => 'API endpoint not found.']);
    }

    public function test_unknown_api_paths_follow_the_same_order_as_node(): void
    {
        $nurse = $this->nurse();

        // Not logged in: 401 first (the login check comes before "not found"), for every method.
        $this->api(null, 'GET', '/api/nothing')->assertStatus(401)->assertJson(['error' => 'Authentication required.']);
        $this->api(null, 'POST', '/api/nothing', [])->assertStatus(401)->assertJson(['error' => 'Authentication required.']);

        // Logged in but a change with the wrong CSRF token: 403 before "not found".
        $bad = array_merge($nurse, ['csrf' => 'not-the-token']);
        $this->api($bad, 'POST', '/api/nothing', [])->assertStatus(403);
    }

    public function test_errors_outside_the_api_are_json_without_a_stack_trace(): void
    {
        $doctor = $this->doctor();
        $missing = $this->page($doctor, '/doctor/nothing.html');
        $missing->assertStatus(404)->assertExactJson(['error' => 'File not found.']);
        $this->assertNull($missing->json('trace'));

        $nurse = $this->nurse();
        $this->page($nurse, '/doctor/users.html')->assertStatus(403)
            ->assertExactJson(['error' => 'This dashboard is restricted to its assigned role.']);
    }

    public function test_health_checks(): void
    {
        $this->api(null, 'GET', '/healthz')->assertOk()->assertJson(['status' => 'ok']);
        $this->api(null, 'GET', '/readyz')->assertOk()->assertJson(['status' => 'ready']);
    }

    public function test_requests_from_another_site_are_refused(): void
    {
        $credentials = ['username' => 'nurse1', 'password' => 'clinic-dev-2026', 'portal' => 'nurse'];
        $origin = rtrim((string) config('app.url'), '/');

        $this->api(null, 'POST', '/api/login', $credentials, ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(403);
        $this->api(null, 'POST', '/api/login', $credentials, ['Origin' => 'http://evil.example'])->assertStatus(403);
        $this->api(null, 'POST', '/api/login', $credentials, ['Origin' => 'not a url'])->assertStatus(403);

        // Same origin (what the browser sends from the clinic's own pages) and no Origin at all both pass.
        $this->api(null, 'POST', '/api/login', $credentials, ['Origin' => $origin, 'Sec-Fetch-Site' => 'same-origin'])->assertOk();
        $this->api(null, 'POST', '/api/login', $credentials)->assertOk();
    }

    public function test_changes_from_another_site_are_refused_even_when_logged_in(): void
    {
        $nurse = $this->nurse();
        $this->api($nurse, 'POST', '/api/logout', [], ['Origin' => 'http://evil.example'])->assertStatus(403);
        $this->api($nurse, 'GET', '/api/session')->assertOk();   // still logged in
    }
}
