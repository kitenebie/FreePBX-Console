#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PBX-side helper script.
 *
 * Deploy this file to the FreePBX host, for example:
 *   /var/lib/asterisk/bin/create_freepbx_extension.php
 *
 * Usage:
 *   php create_freepbx_extension.php <base64-json-payload>
 */

function respond(array $payload, int $exitCode = 0): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

if ($argc < 2) {
    respond([
        'status' => 'error',
        'message' => 'Missing payload argument',
    ], 1);
}

$decoded = base64_decode($argv[1], true);
if ($decoded === false) {
    respond([
        'status' => 'error',
        'message' => 'Payload is not valid base64',
    ], 1);
}

$payload = json_decode($decoded, true);
if (!is_array($payload)) {
    respond([
        'status' => 'error',
        'message' => 'Payload is not valid JSON',
    ], 1);
}

$extension = preg_replace('/[^0-9A-Za-z]/', '', (string) ($payload['extension'] ?? ''));
$name = trim((string) ($payload['name'] ?? $extension));
$password = (string) ($payload['password'] ?? '');
$tech = (string) ($payload['tech'] ?? 'pjsip');
$settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];

if ($extension === '' || $password === '') {
    respond([
        'status' => 'error',
        'message' => 'Extension and password are required',
    ], 1);
}

try {
    require_once '/etc/freepbx.conf';

    if (!class_exists('FreePBX')) {
        throw new RuntimeException('FreePBX bootstrap did not load correctly');
    }

    $freepbx = \FreePBX::Create();
    $core = $freepbx->Core;

    if (!$core || !method_exists($core, 'addDevice') || !method_exists($core, 'addUser')) {
        throw new RuntimeException('FreePBX Core addDevice/addUser methods are unavailable on this system');
    }

    if (method_exists($core, 'getDevice') && $core->getDevice($extension)) {
        throw new RuntimeException("Extension {$extension} already exists");
    }

    $deviceSettings = array_merge([
        'devicetype' => 'fixed',
        'secret' => $password,
        'dtmfmode' => 'rfc4733',
        'disallow' => 'all',
        'allow' => 'ulaw,alaw',
        'context' => 'from-internal',
        'dial' => 'PJSIP/' . $extension,
        'mailbox' => $extension . '@device',
        'sipdriver' => 'chan_pjsip',
    ], $settings);

    $userSettings = [
        'extension' => $extension,
        'name' => $name,
        'voicemail' => 'default',
        'noanswer' => '',
        'recording' => '',
        'outboundcid' => $name,
        'sipname' => '',
        'mohclass' => 'default',
        'tech' => $tech,
    ];

    $core->addDevice($extension, $tech, $deviceSettings);
    $core->addUser($extension, $userSettings);

    if (method_exists($freepbx, 'Config')) {
        $freepbx->Config->commit();
    }

    if (function_exists('needreload')) {
        needreload();
    }

    respond([
        'status' => 'success',
        'message' => "Extension {$extension} created via FreePBX Core",
        'extension' => $extension,
    ]);
} catch (Throwable $e) {
    respond([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], 1);
}
