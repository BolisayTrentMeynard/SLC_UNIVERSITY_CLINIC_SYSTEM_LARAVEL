<?php

namespace Tests\Feature;

class StatisticsTest extends ClinicTestCase
{
    private function complete(array $doctor, int $visitId, array $body)
    {
        return $this->api($doctor, 'POST', "/api/visits/{$visitId}/complete", $body)->assertOk();
    }

    public function test_summary_counts_visits_and_leaves_out_screening_copies(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->registerPatient($nurse, 'STAT-001');

        $this->complete($doctor, $this->triage($nurse, 'STAT-001', 'Consultation'), ['diagnosis' => 'Acute Pharyngitis']);
        $this->complete($doctor, $this->triage($nurse, 'STAT-001', 'Checkup'), ['diagnosis' => 'Healthy']);
        $this->complete($doctor, $this->triage($nurse, 'STAT-001', 'Checkup'), ['diagnosis' => 'Hypertension', 'putToScreening' => true, 'screenedNote' => 'Elevated BP']);

        $this->api(null, 'GET', '/api/statistics/summary')->assertStatus(401);
        $summary = $this->api($doctor, 'GET', '/api/statistics/summary');
        $summary->assertOk();
        $this->assertSame(3, $summary->json('summary.totalVisits'));          // the screening copy is not counted
        $this->assertSame(1, $summary->json('summary.consultations'));
        $this->assertSame(2, $summary->json('summary.checkups'));
        $this->assertSame(1, $summary->json('summary.totalScreeningCopies'));
    }

    public function test_morbidity_groups_diagnoses_by_frequency(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->registerPatient($nurse, 'STAT-002');
        foreach (['Acute Bronchitis', 'Acute Bronchitis', 'Migraine'] as $diagnosis) {
            $this->complete($doctor, $this->triage($nurse, 'STAT-002', 'Consultation'), ['diagnosis' => $diagnosis]);
        }

        $report = $this->api($nurse, 'GET', '/api/statistics/morbidity');
        $report->assertOk();
        $this->assertSame(3, $report->json('morbidity.totalCases'));
        $this->assertSame('Acute Bronchitis', $report->json('morbidity.items.0.diagnosis'));
        $this->assertSame(2, $report->json('morbidity.items.0.caseCount'));
        $this->assertEqualsWithDelta(66.7, $report->json('morbidity.items.0.percentage'), 0.01);
        $this->assertSame('Migraine', $report->json('morbidity.items.1.diagnosis'));
        $this->assertEqualsWithDelta(33.3, $report->json('morbidity.items.1.percentage'), 0.01);
    }

    public function test_confidential_visits_are_never_counted_for_the_nurse(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->registerPatient($nurse, 'STAT-003');
        $this->complete($doctor, $this->triage($nurse, 'STAT-003', 'Consultation'), ['diagnosis' => 'Secret Diagnosis', 'confidential' => true]);

        $forNurse = $this->api($nurse, 'GET', '/api/statistics/morbidity');
        $this->assertSame(0, $forNurse->json('morbidity.totalCases'));
        $this->assertStringNotContainsString('Secret Diagnosis', $forNurse->getContent());

        $forDoctor = $this->api($doctor, 'GET', '/api/statistics/morbidity');
        $this->assertSame(1, $forDoctor->json('morbidity.totalCases'));
    }

    public function test_distribution_trends_and_csv_export_answer(): void
    {
        $doctor = $this->doctor();
        $this->api($doctor, 'GET', '/api/statistics/distribution')->assertOk()->assertJsonStructure(['distribution']);
        $this->api($doctor, 'GET', '/api/statistics/trends')->assertOk()->assertJsonStructure(['trends']);

        $export = $this->api($doctor, 'GET', '/api/statistics/export');
        $export->assertOk();
        $this->assertStringContainsString('text/csv', $export->headers->get('Content-Type'));
        $this->assertStringContainsString('clinic-statistics.csv', $export->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Total Visits', $export->getContent());
    }
}
