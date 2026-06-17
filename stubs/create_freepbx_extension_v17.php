#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FreePBX 17.0.28 Compatible Extension Creation Script
 * 
 * Deploy this file to the FreePBX host:
 *   /var/lib/asterisk/bin/create_freepbx_extension.php
 */

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
    require_once '/etc/freepbx.conf';

    if (!class_exists('FreePBX')) {
        throw new RuntimeException('FreePBX bootstrap did not load correctly');
    }

    // Use FreePBX 17 Core module
    $core = FreePBX::Core();
    
    if (!$core) {
        throw new RuntimeException('FreePBX Core module not available');
    }

    // Check if extension exists
    if (method_exists($core, 'getDevice') && $core->getDevice($extension)) {
        throw new RuntimeException("Extension {$extension} already exists");
    }

    // Create extension using FreePBX 17 API
    $deviceData = [
        'account'     => $extension,
        'tech'        => 'pjsip',
        'dial'        => "PJSIP/{$extension}",
        'devicetype'  => 'fixed',
        'user'        => $extension,
        'description' => $name,
        'secret'      => $password,
        'callerid'    => "\"{$name}\" <{$extension}>",
        'context'     => 'from-internal'
    ];

    $userData = [
        'extension'   => $extension,
        'name'        => $name,
        'voicemail'   => 'enabled',
        'ringtimer'   => 0,
        'noanswer'    => 0,
        'recording'   => 'dontcare',
        'outboundcid' => "\"{$name}\" <{$extension}>",
        'sipname'     => '',
        'mohclass'    => 'default'
    ];

    // Add device and user
    $core->addDevice($extension, 'pjsip', $deviceData);
    $core->addUser($extension, $userData);

    // Get database connection
    $db = FreePBX::Database();
    
    // Add PJSIP specific settings for WebRTC
    $pjsipSettings = [
        'aors'                => $extension,
        'max_contacts'        => '1',
        'remove_existing'     => 'yes',
        'media_encryption'    => 'dtls',
        'webrtc'              => 'yes',
        'icesupport'          => 'yes',
        'bundle'              => 'yes',
        'rtcp_mux'            => 'yes',
        'rtp_symmetric'       => 'yes',
        'rewrite_contact'     => 'yes',
        'force_rport'         => 'yes',
        'direct_media'        => 'no',
        'use_avpf'            => 'yes'
    ];
    
    foreach ($pjsipSettings as $keyword => $value) {
        try {
            $stmt = $db->prepare("
                INSERT INTO pjsip (id, keyword, data, flags) 
                VALUES (?, ?, ?, 0) 
                ON DUPLICATE KEY UPDATE data = ?
            ");
            $stmt->execute([$extension, $keyword, $value, $value]);
        } catch (Exception $e) {
            // Continue on error
        }
    }

    // Trigger FreePBX reload
    if (function_exists('needreload')) {
        needreload();
    }

    // Execute system reload commands
    exec('fwconsole reload 2>&1', $reloadOutput, $reloadCode);
    usleep(1000000); // Wait 1 second
    exec('asterisk -rx "module reload res_pjsip.so" 2>&1', $pjsipOutput, $pjsipCode);
    usleep(500000); // Wait 500ms
    exec('asterisk -rx "pjsip show endpoint ' . escapeshellarg($extension) . '" 2>&1', $endpointOutput);

    respond([
        'status'         => 'success',
        'message'        => "Extension {$extension} created successfully for FreePBX 17",
        'extension'      => $extension,
        'reload_output'  => implode("\n", $reloadOutput),
        'pjsip_output'   => implode("\n", $pjsipOutput),
        'endpoint_check' => implode("\n", $endpointOutput)
    ]);

} catch (Throwable $e) {
    respond([
        'status'  => 'error',
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ], 1);
}