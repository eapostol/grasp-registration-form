<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/ContactAdminSecurity.php';

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: $label\n";
}
function rejects(callable $operation, string $label): void
{
    try { $operation(); } catch (Throwable $error) { check(true, $label); return; }
    check(false, $label);
}

$fixture = [
    'project_id' => 'grasp-511222', 'site_key' => 'public-test-key',
    'api_key' => 'private-test-credential',
    'allowed_hostnames' => ['reg-form-project.ddev.site'],
    'recipient' => 'administrator@example.com',
];
$keys = ['GRASP_CONTACT_CONFIG', 'GRASP_CONTACT_PROJECT_ID', 'GRASP_CONTACT_SITE_KEY',
    'GRASP_CONTACT_API_KEY', 'GRASP_CONTACT_ALLOWED_HOSTNAMES', 'GRASP_CONTACT_RECIPIENT'];
$saved = [];
foreach ($keys as $key) { $saved[$key] = getenv($key); putenv($key); }
$file = tempnam(sys_get_temp_dir(), 'grasp-contact-test-');
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
try {
    chmod($file, 0600);
    putenv('GRASP_CONTACT_CONFIG=' . $file);
    file_put_contents($file, json_encode($fixture));
    check(contactAdminConfig() === $fixture, 'loads protected file outside document root');
    check(contactAdminPublicConfig($fixture) === ['siteKey' => 'public-test-key'], 'public endpoint projection excludes secrets and recipient');
    putenv('GRASP_CONTACT_API_KEY=replacement-private-key');
    check(contactAdminConfig()['api_key'] === 'replacement-private-key', 'environment credential overrides file');
    putenv('GRASP_CONTACT_ALLOWED_HOSTNAMES=LOCALHOST, reg-form-project.ddev.site');
    check(contactAdminConfig()['allowed_hostnames'] === ['localhost', 'reg-form-project.ddev.site'], 'environment hostname list normalized');
    putenv('GRASP_CONTACT_API_KEY');
    putenv('GRASP_CONTACT_ALLOWED_HOSTNAMES');
    foreach ([['api_key', ''], ['recipient', "a@example.com\r\nBcc: victim@example.com"],
        ['allowed_hostnames', ['https://example.com/path']], ['allowed_hostnames', []],
        ['project_id', '../other-project'], ['site_key', []]] as [$field, $value]) {
        $bad = $fixture; $bad[$field] = $value;
        file_put_contents($file, json_encode($bad));
        rejects(fn() => contactAdminConfig(), 'rejects invalid configuration: ' . $field);
    }
    file_put_contents($file, '{invalid json');
    rejects(fn() => contactAdminConfig(), 'rejects malformed JSON');
    putenv('GRASP_CONTACT_CONFIG=' . dirname(__DIR__) . '/config/contact-admin.example.json');
    rejects(fn() => contactAdminConfig(), 'refuses configuration inside public root');
    putenv('GRASP_CONTACT_CONFIG=' . $file . '-not-found');
    rejects(fn() => contactAdminConfig(), 'missing configuration fails closed');
    foreach ($fixture as $key => $value) {
        putenv('GRASP_CONTACT_' . strtoupper($key) . '=' . (is_array($value) ? implode(',', $value) : $value));
    }
    check(contactAdminConfig() === $fixture, 'supports complete environment-only configuration');

    $calls = 0;
    $valid = function (array $config, array $request) use (&$calls, $fixture): array {
        $calls++;
        check($request === ['event' => ['token' => 'one-use-token', 'siteKey' => $fixture['site_key']]], 'assessment uses server site key and omits unsupported checkbox action');
        return ['tokenProperties' => ['valid' => true, 'hostname' => 'reg-form-project.ddev.site']];
    };
    check(!contactAdminVerify($fixture, '', $valid), 'missing token rejected');
    check(!contactAdminVerify($fixture, str_repeat('x', 16385), $valid), 'oversized token rejected');
    check($calls === 0, 'bad tokens rejected before provider request');
    check(contactAdminVerify($fixture, 'one-use-token', $valid), 'valid checkbox and exact hostname accepted');
    foreach ([['valid' => false, 'hostname' => 'reg-form-project.ddev.site', 'invalidReason' => 'EXPIRED'],
        ['valid' => false, 'hostname' => 'reg-form-project.ddev.site', 'invalidReason' => 'DUPE'],
        ['valid' => true, 'hostname' => 'attacker.example.com'],
        ['valid' => true, 'hostname' => 'reg-form-project.ddev.site.attacker.example'],
        ['valid' => true], ['valid' => 'true', 'hostname' => 'reg-form-project.ddev.site']] as $properties) {
        check(!contactAdminVerify($fixture, 'token', fn() => ['tokenProperties' => $properties]), 'rejects invalid/reused/expired/wrong-host or malformed token properties');
    }
    check(!contactAdminVerify($fixture, 'token', fn() => ['error' => ['code' => 429]]), 'provider error cannot authorize sending');
    foreach (['timeout', 'invalid JSON', 'HTTP 403', 'HTTP 429'] as $failure) {
        rejects(fn() => contactAdminVerify($fixture, 'token', function () use ($failure) {
            throw new RuntimeException($failure);
        }), 'provider failure propagated for 503: ' . $failure);
    }
    echo "$checks security checks passed. Synthetic assessments only; no email sent.\n";
} finally {
    unlink($file);
    foreach ($saved as $key => $value) putenv($value === false ? $key : "$key=$value");
    if ($originalDocumentRoot === null) unset($_SERVER['DOCUMENT_ROOT']);
    else $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot;
}
