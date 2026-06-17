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

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Force delete everything related to this extension
        $pdo->exec("DELETE FROM pjsip WHERE id = '$extension'");
        $pdo->exec("DELETE FROM users WHERE extension = '$extension'");
        $pdo->exec("DELETE FROM devices WHERE id = '$extension'");
        
        // Commit the delete transaction
        $pdo->commit();
        
        // Start new transaction for creation
        $pdo->beginTransaction();

        // Create users entry
        $stmt = $pdo->prepare('INSERT INTO users (extension, password, name) VALUES (?, ?, ?)');
        $stmt->execute([$extension, $password, $name]);

        // Create devices entry  
        $stmt = $pdo->prepare('INSERT INTO devices (id, tech, dial) VALUES (?, ?, ?)');
        $stmt->execute([$extension, 'pjsip', "PJSIP/$extension"]);

        // Insert PJSIP endpoint settings
        $stmt = $pdo->prepare('INSERT INTO pjsip (id, keyword, data, flags) VALUES (?, ?, ?, ?)');
        
        $endpointSettings = [
            ['type', 'endpoint', 0],
            ['aors', $extension, 1], 
            ['auth', $extension, 2],
            ['context', 'from-internal', 3],
            ['disallow', 'all', 4],
            ['allow', 'ulaw,alaw', 5],
            ['webrtc', 'yes', 6],
            ['media_encryption', 'dtls', 7],
            ['icesupport', 'yes', 8],
            ['bundle', 'yes', 9],
            ['rtcp_mux', 'yes', 10],
            ['rtp_symmetric', 'yes', 11],
            ['rewrite_contact', 'yes', 12],
            ['force_rport', 'yes', 13],
            ['direct_media', 'no', 14],
            ['use_avpf', 'yes', 15]
        ];

        foreach ($endpointSettings as $setting) {
            $stmt->execute([$extension, $setting[0], $setting[1], $setting[2]]);
        }

        // AOR settings (different ID for AOR)
        $aorId = $extension . '_aor';
        $aorSettings = [
            ['type', 'aor', 100],
            ['max_contacts', '1', 101],
            ['remove_existing', 'yes', 102]
        ];

        foreach ($aorSettings as $setting) {
            $stmt->execute([$aorId, $setting[0], $setting[1], $setting[2]]);
        }

        // Auth settings (different ID for auth)
        $authId = $extension . '_auth';
        $authSettings = [
            ['type', 'auth', 200],
            ['auth_type', 'userpass', 201],
            ['username', $extension, 202],
            ['password', $password, 203]
        ];

        foreach ($authSettings as $setting) {
            $stmt->execute([$authId, $setting[0], $setting[1], $setting[2]]);
        }

        // Commit all changes
        $pdo->commit();

        // Reload Asterisk
        exec('fwconsole reload 2>&1', $reloadOutput);
        exec('asterisk -rx "module reload res_pjsip.so" 2>&1', $pjsipOutput);
        exec('asterisk -rx "pjsip show endpoint ' . escapeshellarg($extension) . '" 2>&1', $endpointOutput);

        respond([
            'status' => 'success',
            'message' => "Extension {$extension} created successfully",
            'extension' => $extension,
            'remote_result' => [
                'reload_output' => implode("\n", $reloadOutput),
                'pjsip_output' => implode("\n", $pjsipOutput),
                'endpoint_check' => implode("\n", $endpointOutput)
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }

} catch (Throwable $e) {
    respond([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 1);
}