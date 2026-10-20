# Laravel demo: final run-through (not part of the repo)

Run in `SLC_laravel_app`: `php artisan route:clear`, `php artisan config:clear`, `php artisan serve`, MySQL on in XAMPP.
Logins: `nurse1` and `doctor1`, password `clinic-dev-2026`. Keep F12 open and watch the Console for red "Content Security Policy" lines.

## Step 10 (not tested yet)
1. `curl.exe -i http://127.0.0.1:8000/healthz` gives `{"status":"ok"}`.
2. `curl.exe -i http://127.0.0.1:8000/readyz` gives `{"status":"ready"}`.
3. `curl.exe -i http://127.0.0.1:8000/api/nothing` gives 404 JSON with a `Content-Security-Policy` header.
4. As `doctor1`: finish a Consultation with one medicine, open that student's record, click "Print slip": the slip opens and Print works.
5. As `nurse1`: the slip of a Confidential visit is refused (403).

## Whole app
**Login:** nurse1 on the Nurse portal lands on Assess; doctor1 on the Nurse portal is refused; logged-out `/doctor/queue.html` goes to login; logout works.
**Nurse:** Assess (search, past visits, send Consultation and Checkup); Student Records (list, search, department filter, open a record, Edit); Add Student; Import from Excel (small file); Statistics for Checkup and Consultation (no confidential visits counted).
**Doctor:** Exam Queue (examine, complete, Sent to hospital, Confidential, Add to Screening on a Checkup); Screening, Checkup, Consultation logs (search, filters, paging, Export to Excel); Statistics pages and Export; User Management (add, edit, deactivate); School Years (add, edit, delete).
**Security:** a nurse opening `/doctor/users.html` is refused; no red CSP lines on any page above.

## Say openly to the TC
The hosted system is Node (same pages, same database design); this Laravel copy is the same frontend on a Laravel backend that runs on the laptop.
