<?php

namespace Tests\Feature;

class AuthTest extends ClinicTestCase
{
    public function test_wrong_password_is_refused(): void
    {
        $this->postJson('/api/login', ['username' => 'nurse1', 'password' => 'wrong-password', 'portal' => 'nurse'])
            ->assertStatus(401)
            ->assertJsonStructure(['error']);
    }

    public function test_session_user_has_the_same_keys_as_node(): void
    {
        $nurse = $this->nurse();
        $user = $this->api($nurse, 'GET', '/api/session')->json('user');
        $this->assertSame(['id', 'name', 'patientId', 'role', 'username'], collect(array_keys($user))->sort()->values()->all());
        $this->assertNull($user['patientId']);
    }

    public function test_login_returns_user_and_csrf_token(): void
    {
        $response = $this->postJson('/api/login', ['username' => 'nurse1', 'password' => 'clinic-dev-2026', 'portal' => 'nurse']);
        $response->assertOk();
        $this->assertSame('nurse', $response->json('user.role'));
        $this->assertNotEmpty($response->json('csrfToken'));
    }

    public function test_wrong_portal_is_refused(): void
    {
        $this->postJson('/api/login', ['username' => 'doctor1', 'password' => 'clinic-dev-2026', 'portal' => 'nurse'])
            ->assertStatus(403);
    }

    public function test_session_needs_a_login_and_returns_the_user(): void
    {
        $this->api(null, 'GET', '/api/session')->assertStatus(401);

        $doctor = $this->doctor();
        $session = $this->api($doctor, 'GET', '/api/session');
        $session->assertOk();
        $this->assertSame('doctor1', $session->json('user.username'));
        $this->assertSame($doctor['csrf'], $session->json('csrfToken'));
    }

    public function test_logout_ends_the_session(): void
    {
        $nurse = $this->nurse();
        $this->api($nurse, 'POST', '/api/logout')->assertOk();
        $this->api($nurse, 'GET', '/api/session')->assertStatus(401);
    }

    public function test_a_change_without_the_right_csrf_token_is_refused(): void
    {
        $nurse = $this->nurse();
        $bad = array_merge($nurse, ['csrf' => 'not-the-token']);
        $refused = $this->api($bad, 'POST', '/api/logout');
        $refused->assertStatus(403)->assertExactJson(['error' => 'Invalid CSRF token. Reload the page and try again.']);
        $this->assertNotNull($refused->headers->get('Content-Security-Policy'));   // the refusal carries the security headers too
        // The session is still alive: the refused request did not log anyone out.
        $this->api($nurse, 'GET', '/api/session')->assertOk();
    }

    public function test_login_is_limited_to_30_attempts_per_10_minutes(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/login', ['username' => 'nurse1', 'password' => 'wrong', 'portal' => 'nurse'])->assertStatus(401);
        }
        $this->postJson('/api/login', ['username' => 'nurse1', 'password' => 'wrong', 'portal' => 'nurse'])->assertStatus(429);
    }
}
