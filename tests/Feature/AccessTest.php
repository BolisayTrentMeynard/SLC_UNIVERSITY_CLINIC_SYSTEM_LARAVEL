<?php

namespace Tests\Feature;

/** Who may open which page or endpoint. */
class AccessTest extends ClinicTestCase
{
    public function test_logged_out_staff_pages_go_to_the_login_page(): void
    {
        $this->page(null, '/doctor/queue.html')->assertRedirect();
        $this->page(null, '/nurse/assess.html')->assertRedirect();
    }

    public function test_each_role_opens_its_own_pages(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->page($nurse, '/nurse/assess.html')->assertOk();
        $this->page($doctor, '/doctor/queue.html')->assertOk();
    }

    public function test_doctor_only_pages_refuse_the_nurse(): void
    {
        $nurse = $this->nurse();
        $this->page($nurse, '/doctor/users.html')->assertForbidden();
        $this->page($nurse, '/doctor/school-years.html')->assertForbidden();
        $this->page($this->doctor(), '/doctor/users.html')->assertOk();
    }

    public function test_user_management_is_doctor_only(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        $this->api(null, 'GET', '/api/users')->assertStatus(401);
        $this->api($nurse, 'GET', '/api/users')->assertForbidden();
        $this->api($nurse, 'POST', '/api/users', [])->assertForbidden();

        $list = $this->api($doctor, 'GET', '/api/users');
        $list->assertOk();
        $usernames = array_column($list->json('users'), 'username');
        $this->assertContains('nurse1', $usernames);
        $this->assertContains('doctor1', $usernames);
    }

    public function test_school_years_nurse_reads_only_doctor_changes(): void
    {
        $nurse = $this->nurse();
        $this->api($nurse, 'GET', '/api/school-years')->assertOk();
        $this->api($nurse, 'POST', '/api/school-years', [])->assertForbidden();
        $this->api($nurse, 'DELETE', '/api/school-years/1')->assertForbidden();
    }
}
