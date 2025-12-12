<?php
// api/config.php
// Environment-aware config for GRASP enrollment backend.

$host = $_SERVER['HTTP_HOST'] ?? '';
$uri  = $_SERVER['REQUEST_URI'] ?? '';

$isDdev         = (strpos($host, '.ddev.site') !== false);
$isStagingPath  = (strpos($uri, '/staging/') === 0);

// Base config (will be overridden per environment below)
$config = [
    'email_to'      => 'ed@edapostol.com',
    'email_from'    => 'no-reply@greenlandrecreational.com',
    'email_subject' => 'New GRASP Enrollment Submission',
    'db' => [
        'dsn'      => '',
        'user'     => '',
        'password' => '',
    ],
];

if ($isDdev) {
    // Local DDEV: DB "db" on host "db", mail captured by Mailpit
    $config['db'] = [
        'dsn'      => 'mysql:host=db;port=3306;dbname=db;charset=utf8mb4',
        'user'     => 'db',
        'password' => 'db',
    ];
    $config['email_to'] = 'ed@edapostol.com';

} else {
    // Remote WHC hosting
    // Adjust host/user/password based on cPanel's MySQL settings.
    $config['db'] = [
        'dsn'      => 'mysql:host=localhost;dbname=tscu0290_grasp_reg_db;charset=utf8mb4',
        'user'     => 'tscu0290_regdb_user',
        'password' => '[$6*4%ibssV0',
    ];

    if ($isStagingPath) {
        // e.g. /staging/reg-form/...
        $config['email_to'] = 'ed@edapostol.com';
    } else {
        // e.g. /reg-form/ on site1762545518.mywhc.ca or greenlandrecreational.com
        $config['email_to'] = 'info@greenlandrecreational.com';
    }
}

return $config;
