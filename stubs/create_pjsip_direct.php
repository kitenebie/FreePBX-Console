#!/usr/bin/env php
<?php

function respond(array $payload, int $exitCode = 0): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

if ($argc < 2) {
    respond(['status' => 'error', 'message' => 'Missing payload argument'], 1);
}

$decoded = base64_decode($argv[1], true);
if ($decoded === false) {
    respond(['status' => 'error', 'message' => 'Payload is not valid base64'], 1);
}

$payload = json_decode($decoded, true);
if (!is_array($payload)) {
    respond(['status' => 'error', 'message' => 'Payload is not valid JSON'], 1);
}

$extension = preg_replace('/[^0-9A-Za-z]/', '', (string) ($payload['extension'] ?? ''));
$name      = trim((string) ($payload['name']     ?? $extension));
$password  = (string) ($payload['password'] ?? '');

if ($extension === '' || $password === '') {
    respond(['status' => 'error', 'message' => 'Extension and password are required'], 1);
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=asterisk', 'freepbxuser', 'fCE0WhWM78Vv');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Always delete any existing entries first (clean slate)
    $pdo->prepare('DELETE FROM pjsip WHERE id = ?')->execute([$extension]);
    $pdo->prepare('DELETE FROM users WHERE extension = ?')->execute([$extension]);
    $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$extension]);

    // Insert PJSIP settings
    $settings = [
        ['type', 'endpoint'],
        ['aors', $extension], 
        ['auth', $extension],
        ['context', 'from-internal'],
        ['disallow', 'all'],
        ['allow', 'ulaw,alaw'],
        ['webrtc', 'yes'],
        ['media_encryption', 'dtls'],
        ['icesupport', 'yes'],
        ['bundle', 'yes'],
        ['rtcp_mux', 'yes'],
        ['rtp_symmetric', 'yes'],
        ['rewrite_contact', 'yes'],
        ['force_rport', 'yes'],
        ['direct_media', 'no'],
        ['use_avpf', 'yes']
    ];

    $stmt = $pdo->prepare('INSERT INTO pjsip (id, keyword, data, flags) VALUES (?, ?, ?, ?)');
    foreach ($settings as $i => $setting) {
        $stmt->execute([$extension, $setting[0], $setting[1], $i]);
    }

    // AOR settings
    $aorSettings = [
        ['type', 'aor'],
        ['max_contacts', '1'],
        ['remove_existing', 'yes']
    ];

    foreach ($aorSettings as $i => $setting) {
        $stmt->execute([$extension, $setting[0], $setting[1], 100 + $i]);
    }

    // Auth settings  
    $authSettings = [
        ['type', 'auth'],
        ['auth_type', 'userpass'],
        ['username', $extension],
        ['password', $password]
    ];

    foreach ($authSettings as $i => $setting) {
        $stmt->execute([$extension, $setting[0], $setting[1], 200 + $i]);
    }

    // Reload
    exec('fwconsole reload 2>&1', $reloadOutput);
    exec('asterisk -rx "module reload res_pjsip.so" 2>&1', $pjsipOutput);
    exec('asterisk -rx "pjsip show endpoint ' . escapeshellarg($extension) . '" 2>&1', $endpointOutput);

    respond([
        'status' => 'success',
        'message' => "Extension {$extension} created",
        'extension' => $extension,
        'remote_result' => [
            'reload_output' => implode("\n", $reloadOutput),
            'pjsip_output' => implode("\n", $pjsipOutput),
            'endpoint_check' => implode("\n", $endpointOutput)
        ]
    ]);

} catch (Throwable $e) {
    respond([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 1);
}