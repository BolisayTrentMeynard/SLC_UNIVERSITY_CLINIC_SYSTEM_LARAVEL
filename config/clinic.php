<?php

// Clinic details used by the printable prescription slip, and the optional origin lock.
// Set the values in .env (see .env.example); these are the defaults.
return [
    'university' => env('UNIVERSITY_NAME', 'University of La Salette, Inc.'),
    'name' => env('CLINIC_NAME', 'Health Services Department'),
    'address' => env('CLINIC_ADDRESS', ''),
    'contact' => env('CLINIC_CONTACT', ''),

    // Optional: lock the API to one origin, e.g. https://clinic.example.edu (empty = same host only).
    'origin' => env('CLINIC_ORIGIN', ''),
];
