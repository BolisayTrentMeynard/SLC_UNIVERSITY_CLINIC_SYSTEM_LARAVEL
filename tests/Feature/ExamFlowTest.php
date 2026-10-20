<?php

namespace Tests\Feature;

/** Nurse triage, doctor queue, free-text diagnosis, confidential box and the Screening copy. */
class ExamFlowTest extends ClinicTestCase
{
    public function test_exam_flow(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->registerPatient($nurse, '2026-00123');

        // The nurse triages one Checkup and one Consultation; only a nurse can triage.
        $idA = $this->triage($nurse, '2026-00123', 'Checkup');
        $idB = $this->triage($nurse, '2026-00123', 'Consultation');
        $this->api($doctor, 'POST', '/api/visits/triage', ['patientId' => '2026-00123', 'visitType' => 'Checkup', 'visitDate' => date('Y-m-d')])->assertForbidden();

        // Only the doctor sees the queue and completes visits.
        $this->api($nurse, 'GET', '/api/visits/queue')->assertForbidden();
        $queue = $this->api($doctor, 'GET', '/api/visits/queue');
        $queue->assertOk();
        $this->assertEqualsCanonicalizing([$idA, $idB], array_map('intval', array_column($queue->json('visits'), 'id')));
        $full = ['diagnosis' => 'Acute tension headache', 'soapObjective' => 'BP normal', 'actionsTaken' => 'Rest and fluids'];
        $this->api($nurse, 'POST', "/api/visits/{$idA}/complete", $full)->assertForbidden();

        // The diagnosis is required and limited to 500 characters.
        $this->api($doctor, 'POST', "/api/visits/{$idA}/complete", array_merge($full, ['diagnosis' => ' ']))->assertStatus(400);
        $this->api($doctor, 'POST', "/api/visits/{$idA}/complete", array_merge($full, ['diagnosis' => str_repeat('x', 501)]))->assertStatus(400);

        // A long free-text diagnosis is saved as typed, and Screening gets its own copy.
        $long = 'Urinary tract infection suspected from submitted laboratory results; '
            . 'advised hydration, repeat urinalysis in one week and follow-up with the clinic physician.';
        $this->assertGreaterThan(100, strlen($long));
        $doneA = $this->api($doctor, 'POST', "/api/visits/{$idA}/complete", array_merge($full, [
            'diagnosis' => $long, 'putToScreening' => true, 'screenedNote' => 'UTI in lab results',
        ]));
        $doneA->assertOk();
        $this->assertSame($long, $doneA->json('visit.diagnosis'));
        $this->assertSame('BP normal', $doneA->json('visit.soapObjective'));
        $this->assertSame('Rest and fluids', $doneA->json('visit.actionsTaken'));
        $this->assertFalse($doneA->json('visit.confidential'));
        $this->assertFalse($doneA->json('visit.isScreened'));

        $screened = $this->api($doctor, 'GET', '/api/visits/screened');
        $screened->assertOk();
        $this->assertCount(1, $screened->json('visits'));
        $copy = $screened->json('visits.0');
        $this->assertSame($idA, (int) $copy['screenedCopyOf']);
        $this->assertSame('Checkup', $copy['visitType']);
        $this->assertSame($long, $copy['diagnosis']);
        $this->assertSame('UTI in lab results', $copy['screenedNote']);
        $this->assertTrue($copy['isScreened']);
        $this->api($doctor, 'POST', "/api/visits/{$idA}/screen", [])->assertStatus(409);

        // Completing twice is refused.
        $this->api($doctor, 'POST', "/api/visits/{$idA}/complete", $full)->assertStatus(409);

        // A Consultation is never screened; the confidential box hides it from the nurse.
        $doneB = $this->api($doctor, 'POST', "/api/visits/{$idB}/complete", array_merge($full, [
            'diagnosis' => 'Anxiety, referred for counselling', 'confidential' => true, 'putToScreening' => true,
        ]));
        $doneB->assertOk();
        $this->assertFalse($doneB->json('visit.isScreened'));
        $this->assertTrue($doneB->json('visit.confidential'));

        $nurseIds = array_map('intval', array_column($this->api($nurse, 'GET', '/api/visits?search=2026-00123')->json('visits'), 'id'));
        $doctorIds = array_map('intval', array_column($this->api($doctor, 'GET', '/api/visits?search=2026-00123')->json('visits'), 'id'));
        $this->assertEqualsCanonicalizing([$idA, (int) $copy['id']], $nurseIds);            // idB is hidden
        $this->assertEqualsCanonicalizing([$idA, $idB, (int) $copy['id']], $doctorIds);
    }

    public function test_the_nurse_can_cancel_a_waiting_visit(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->registerPatient($nurse, 'TEST-002');
        $id = $this->triage($nurse, 'TEST-002', 'Consultation');

        $this->api($nurse, 'POST', "/api/visits/{$id}/cancel", [])->assertOk();
        $queue = $this->api($doctor, 'GET', '/api/visits/queue');
        $this->assertNotContains($id, array_map('intval', array_column($queue->json('visits'), 'id')));
    }
}
