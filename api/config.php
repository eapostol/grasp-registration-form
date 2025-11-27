<?php
// Basic configuration for enrollment form backend.
// Copy this file to a secure location and update values before going live.

return [
    // Where enrollment emails should be sent
    'email_to'      => 'ed@edapostol.com',
    // The "From" header for outgoing mail
    'email_from'    => 'no-reply@greenlandrecreational.com',
    'email_subject' => 'New GRASP Enrollment Submission',

    // Optional MySQL database storage for submitted forms.
    // Leave 'dsn' empty to disable DB storage.
    'db' => [
        // Example DSN: 'mysql:host=localhost;dbname=greenland;charset=utf8mb4'
        'dsn'      => 'mysql:host=db;port=3306;dbname=db;charset=utf8mb4',
        'user'     => 'db',
        'password' => 'db',
    ],
];