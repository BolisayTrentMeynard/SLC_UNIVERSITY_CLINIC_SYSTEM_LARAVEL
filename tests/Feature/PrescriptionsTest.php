<?php

namespace Tests\Feature;

/** Prescriptions made while completing a visit: list, print slip, dispense, cancel, confidential rule. */
class PrescriptionsTest extends ClinicTestCase
{
    private function prescribe(array $nurse, array $doctor, string $patientId, bool $confidential = false): void
    {
        $id = $this->triage($nurse, $patientId, 'Consultation');
        $this->api($doctor, 'POST', "/api/visits/{$id}/complete", [
            'diagnosis' => 'Fever <b>and</b> cough',
            'confidential' => $confidential,
            'prescription' => ['items' => [[
                'medicine' => 'Paracetamol 500mg', 'dose' => '1 tablet', 'frequency' => 'Three times daily (TID)',
                'durationDays' => 3, 'quantity' => 9, 'instructions' => 'After meals <script>',
            ]]],
        ])->assertOk();
    }

    public function test_list_print_dispense_and_cancel(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->registerPatient($nurse, 'RX-001');
        $this->prescribe($nurse, $doctor, 'RX-001');
        $this->prescribe($nurse, $doctor, 'RX-001');

        $forPatient = $this->api($doctor, 'GET', '/api/patients/RX-001/prescriptions');
        $forPatient->assertOk();
        $this->assertCount(2, $forPatient->json('prescriptions'));
        $first = $forPatient->json('prescriptions.0');
        $this->assertSame('pending', $first['status']);
        $this->assertSame('Paracetamol 500mg', $first['items'][0]['medicine']);
        $ids = array_map('intval', array_column($forPatient->json('prescriptions'), 'id'));

        // The pending list.
        $pending = $this->api($nurse, 'GET', '/api/prescriptions');
        $pending->assertOk();
        $this->assertCount(2, $pending->json('prescriptions'));
        $this->api($nurse, 'GET', '/api/prescriptions?status=nonsense')->assertStatus(400);

        // Print slip: escaped text, print-page CSP, staff only.
        $this->assertContains($this->page(null, "/prescriptions/{$ids[0]}/print")->getStatusCode(), [302, 401]);   // logged out
        $slip = $this->page($doctor, "/prescriptions/{$ids[0]}/print");
        $slip->assertOk();
        $slip->assertSee('Paracetamol 500mg', false);
        $slip->assertDontSee('<script>', false);
        $this->assertStringContainsString("default-src 'none'", $slip->headers->get('Content-Security-Policy'));
        $this->page($nurse, '/prescriptions/999999/print')->assertStatus(404);

        // Dispense: any staff, once.
        $this->api($nurse, 'POST', "/api/prescriptions/{$ids[0]}/dispense", [])->assertOk()
            ->assertJsonPath('prescription.status', 'dispensed');
        $this->api($nurse, 'POST', "/api/prescriptions/{$ids[0]}/dispense", [])->assertStatus(409);
        $this->api($nurse, 'POST', '/api/prescriptions/999999/dispense', [])->assertStatus(404);

        // Cancel: doctor only, needs a reason, pending only.
        $this->api($nurse, 'POST', "/api/prescriptions/{$ids[1]}/cancel", ['reason' => 'Wrong medicine'])->assertForbidden();
        $this->api($doctor, 'POST', "/api/prescriptions/{$ids[1]}/cancel", ['reason' => ' '])->assertStatus(400);
        $this->api($doctor, 'POST', "/api/prescriptions/{$ids[1]}/cancel", ['reason' => 'Wrong medicine'])->assertOk()
            ->assertJsonPath('prescription.status', 'cancelled');
        $this->api($doctor, 'POST', "/api/prescriptions/{$ids[0]}/cancel", ['reason' => 'Too late'])->assertStatus(409);
    }

    public function test_confidential_prescriptions_are_hidden_from_the_nurse(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->registerPatient($nurse, 'RX-002');
        $this->prescribe($nurse, $doctor, 'RX-002', true);

        $this->assertCount(0, $this->api($nurse, 'GET', '/api/patients/RX-002/prescriptions')->json('prescriptions'));
        $this->assertCount(1, $this->api($doctor, 'GET', '/api/patients/RX-002/prescriptions')->json('prescriptions'));

        $row = $this->api($nurse, 'GET', '/api/prescriptions')->json('prescriptions.0');
        $this->assertNull($row['patientId']);
        $this->assertSame('Confidential patient', $row['patientName']);

        $id = (int) $row['id'];
        $this->page($nurse, "/prescriptions/{$id}/print")->assertForbidden();
        $this->page($doctor, "/prescriptions/{$id}/print")->assertOk();
    }
}
