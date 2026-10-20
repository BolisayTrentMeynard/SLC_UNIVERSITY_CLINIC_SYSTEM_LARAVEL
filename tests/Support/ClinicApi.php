<?php

namespace Tests\Support;

/** Small helpers: log in like the browser does (real cookie + CSRF token) and call the API as that person. */
trait ClinicApi
{
    private function useCookies(?array $actor): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = $actor ? [config('session.cookie') => $actor['cookie']] : [];
    }

    /** Logs in through POST /api/login. Returns ['csrf' => ..., 'cookie' => ..., 'user' => ...]. */
    protected function login(string $username, string $portal): array
    {
        $this->useCookies(null);
        $response = $this->postJson('/api/login', [
            'username' => $username,
            'password' => 'clinic-dev-2026',
            'portal' => $portal,
        ]);
        $response->assertOk();
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie, 'Login did not set a session cookie.');

        return ['csrf' => $response->json('csrfToken'), 'cookie' => $cookie->getValue(), 'user' => $response->json('user')];
    }

    protected function nurse(): array
    {
        return $this->login('nurse1', 'nurse');
    }

    protected function doctor(): array
    {
        return $this->login('doctor1', 'doctor');
    }

    /** JSON request as $actor (null = not logged in). */
    protected function api(?array $actor, string $method, string $uri, array $data = [], array $headers = [])
    {
        $this->useCookies($actor);
        if ($actor) {
            $headers['X-CSRF-Token'] = $actor['csrf'];
        }

        return $this->json($method, $uri, $data, $headers);
    }

    /** Plain page request (HTML) as $actor. */
    protected function page(?array $actor, string $uri)
    {
        $this->useCookies($actor);

        return $this->get($uri);
    }

    protected function registerPatient(array $nurse, string $id = 'TEST-001', array $extra = []): void
    {
        $this->api($nurse, 'POST', '/api/patients', array_merge([
            'id' => $id, 'lastName' => 'Dela Cruz', 'firstName' => 'Juan', 'middleName' => 'Santos',
            'college' => 'College of Nursing', 'gender' => 'Male', 'dateOfBirth' => '2007-03-08',
            'civilStatus' => 'Single', 'nationality' => 'Filipino', 'contactNumber' => '0917 000 0000',
            'address' => 'Sample address', 'guardianName' => 'Maria Dela Cruz', 'guardianContact' => '0918 000 0000',
        ], $extra))->assertStatus(201);
    }

    /** Nurse triages a visit, returns the new visit id. */
    protected function triage(array $nurse, string $patientId, string $type, array $extra = []): int
    {
        $response = $this->api($nurse, 'POST', '/api/visits/triage', array_merge([
            'patientId' => $patientId, 'visitType' => $type, 'visitDate' => date('Y-m-d'),
            'complaint' => 'Headache and dizziness', 'bp' => '118/76', 'weight' => 60, 'height' => 165,
        ], $extra));
        $response->assertStatus(201);

        return (int) $response->json('visit.id');
    }
}
