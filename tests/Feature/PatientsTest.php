<?php

namespace Tests\Feature;

class PatientsTest extends ClinicTestCase
{
    public function test_list_register_read_and_edit_a_patient(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();

        $this->api(null, 'GET', '/api/patients')->assertStatus(401);

        $this->registerPatient($nurse, 'TEST-001');
        $list = $this->api($nurse, 'GET', '/api/patients?search=TEST-001');
        $list->assertOk();
        $row = $list->json('patients.0');
        $this->assertSame('TEST-001', $row['id']);
        $this->assertSame('Juan Santos Dela Cruz', $row['name']);
        $this->assertSame('2007-03-08', $row['dateOfBirth']);
        $this->assertArrayHasKey('lastVisit', $row);
        $this->assertNull($row['lastVisit']);

        // The same ID cannot be registered twice.
        $this->api($nurse, 'POST', '/api/patients', ['id' => 'TEST-001', 'lastName' => 'A', 'firstName' => 'B'])->assertStatus(409);

        // Read one patient (nurse and doctor), unknown ID is 404.
        $read = $this->api($doctor, 'GET', '/api/patients/TEST-001');
        $read->assertOk();
        $this->assertSame('0918 000 0000', $read->json('patient.guardianContact'));
        $this->api($nurse, 'GET', '/api/patients/NOPE')->assertStatus(404);

        // Only the nurse edits; the ID stays; the name is rebuilt from its parts.
        $edit = [
            'lastName' => 'Reyes', 'firstName' => 'Juan', 'middleName' => '', 'college' => 'College of Education',
            'gender' => 'Male', 'dateOfBirth' => '2007-03-09', 'civilStatus' => 'Married', 'nationality' => 'Filipino',
            'contactNumber' => '0999', 'address' => 'New address', 'guardianName' => 'G', 'guardianContact' => '1',
        ];
        $this->api($doctor, 'PUT', '/api/patients/TEST-001', $edit)->assertForbidden();
        $this->api($nurse, 'PUT', '/api/patients/NOPE', $edit)->assertStatus(404);
        $this->api($nurse, 'PUT', '/api/patients/TEST-001', array_merge($edit, ['lastName' => ' ']))->assertStatus(400);
        $this->api($nurse, 'PUT', '/api/patients/TEST-001', array_merge($edit, ['dateOfBirth' => '2007-02-31']))->assertStatus(400);

        $saved = $this->api($nurse, 'PUT', '/api/patients/TEST-001', $edit);
        $saved->assertOk();
        $this->assertSame('TEST-001', $saved->json('patient.id'));
        $this->assertSame('Juan Reyes', $saved->json('patient.name'));
        $this->assertSame('College of Education', $saved->json('patient.college'));
        $this->assertSame('2007-03-09', $saved->json('patient.dateOfBirth'));
    }
}
