#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PBX-side helper script.
 *
 * Deploy this file to the FreePBX host:
 *   /var/lib/asterisk/bin/create_freepbx_extension.php
 *
 * Usage:
 *   php create_freepbx_extension.php <base64-json-payload>
 */

define('DTLS_CERT_FILE',    '/etc/asterisk/keys/default.crt');
define('DTLS_KEY_FILE',     '/etc/asterisk/keys/default.key');
define('CUSTOM_POST_CONF',  '/etc/asterisk/pjsip.endpoint_custom_post.conf');

function respond(array $payload, int $exitCode = 0): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function invokeCoreMethod(object $core, string $method, array $baseArgs, array $optionalArgs = [])
{
    $reflection    = new ReflectionMethod($core, $method);
    $requiredCount = $reflection->getNumberOfRequiredParameters();
    $maxCount      = $reflection->getNumberOfParameters();

    $args = $baseArgs;
    foreach ($optionalArgs as $arg) {
        if (count($args) >= $maxCount) break;
        $args[] = $arg;
    }

    if (count($args) < $requiredCount) {
        throw new RuntimeException(sprintf(
            '%s expects at least %d arguments, only %d prepared',
            $method, $requiredCount, count($args)
        ));
    }

    return $reflection->invokeArgs($core, $args);
}

function methodSignature(object $core, string $method): string
{
    $reflection = new ReflectionMethod($core, $method);
    $names = [];
    foreach ($reflection->getParameters() as $p) {
        $names[] = '$' . $p->getName();
    }
    return sprintf('%s(%s)', $method, implode(', ', $names));
}

function normalizeDeviceSettings(string $extension, string $tech, string $name, string $password, array $settings): array
{
    $base = [
        'account'       => $extension,
        'devicetype'    => 'fixed',
        'user'          => $extension,
        'description'   => $name,
        'emergency_cid' => '',
        'hint_override' => '',
        'dial'          => 'PJSIP/' . $extension,
        'defaultuser'   => $extension,
        'secret'        => $password,
        'callerid'      => '"' . $name . '" <' . $extension . '>',
        'dtmfmode'      => 'rfc4733',
        'disallow'      => 'all',
        'allow'         => 'ulaw,alaw,vp8,h264',
        'context'       => 'from-internal',
        'mailbox'       => $extension . '@device',
        'sipdriver'     => 'chan_pjsip',
    ];

    $merged     = array_merge($base, $settings);
    $normalized = [];
    $flag       = 0;

    foreach ($merged as $key => $value) {
        if (is_array($value) && array_key_exists('value', $value)) {
            $normalized[$key] = $value;
            if (!array_key_exists('flag', $normalized[$key])) {
                $normalized[$key]['flag'] = $flag++;
            }
            continue;
        }
        $normalized[$key] = ['value' => $value, 'flag' => $flag++];
    }

    if ($tech === 'pjsip') {
        // Force-override always — addDevice() sets its own defaults
        // that would win if we only set when empty.
        $pjsipForced = [
            'max_contacts'      => '1',
            'max_video_streams' => '2',
            'timers'            => 'no',
            'media_encryption'  => 'dtls',
            'webrtc'            => 'yes',
            'icesupport'        => 'yes',
            'bundle'            => 'yes',
            'rtcp_mux'          => 'yes',
            'rtp_symmetric'     => 'yes',
            'rewrite_contact'   => 'yes',
            'force_rport'       => 'yes',
            'direct_media'      => 'no',
            'use_avpf'          => 'yes',
            'trust_id_inbound'  => 'yes',
            'send_pai'          => 'yes',
            'rtp_timeout'       => '0',
            'rtp_timeout_hold'  => '0',
            'rtp_keepalive'     => '0',
        ];

        foreach ($pjsipForced as $key => $val) {
            $normalized[$key] = ['value' => $val, 'flag' => $flag++];
        }
    }

    return $normalized;
}

/**
 * Patch PJSIP fields in the DB using PDO prepared statements.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE — avoids ADODB isError().
 */
function patchPjsipDb(object $db, string $extension): void
{
    $fields = [
        'timers'              => 'no',
        'timers_min_se'       => '90',
        'timers_sess_expires' => '1800',
        'media_encryption'    => 'dtls',
        'webrtc'              => 'yes',
        'max_video_streams'   => '2',
        'icesupport'          => 'yes',
        'bundle'              => 'yes',
        'rtcp_mux'            => 'yes',
        'rtp_symmetric'       => 'yes',
        'rewrite_contact'     => 'yes',
        'force_rport'         => 'yes',
        'direct_media'        => 'no',
        'use_avpf'            => 'yes',
        'trust_id_inbound'    => 'yes',
        'send_pai'            => 'yes',
    ];

    foreach ($fields as $keyword => $value) {
        try {
            $stmt = $db->prepare("
                INSERT INTO pjsip (id, keyword, data, flags)
                VALUES (:id, :keyword, :data, 0)
                ON DUPLICATE KEY UPDATE data = :data2
            ");
            $stmt->execute([
                ':id'      => $extension,
                ':keyword' => $keyword,
                ':data'    => $value,
                ':data2'   => $value,
            ]);
        } catch (Throwable $e) {
            fwrite(STDERR, "Warning: DB patch failed for {$keyword}: " . $e->getMessage() . PHP_EOL);
        }
    }
}

/**
 * Write DTLS cert/key block to pjsip.endpoint_custom_post.conf.
 *
 * This is where FreePBX stores dtls_cert_file and dtls_private_key —
 * NOT in the pjsip DB table. Without this block the DTLS toggle in
 * the GUI shows "No" and Asterisk cannot complete DTLS negotiation.
 */
function patchDtlsCustomPostConf(string $extension): void
{
    $confFile = CUSTOM_POST_CONF;
    $certFile = DTLS_CERT_FILE;
    $keyFile  = DTLS_KEY_FILE;

    // Read existing file or start fresh
    $existing = file_exists($confFile) ? file_get_contents($confFile) : '';

    // Remove any existing block for this extension to avoid duplicates
    $existing = preg_replace(
        '/\[' . preg_quote($extension, '/') . '\][^\[]*/',
        '',
        $existing
    );

    // Build the DTLS block — matches exactly what FreePBX GUI writes
    $block = "\n[{$extension}]\n"
           . "webrtc=yes\n"
           . "dtls_cert_file={$certFile}\n"
           . "dtls_private_key={$keyFile}\n"
           . "dtls_verify=fingerprint\n"
           . "dtls_setup=actpass\n"
           . "dtls_rekey=0\n";

    $result = file_put_contents($confFile, trim($existing) . "\n" . $block);

    if ($result === false) {
        throw new RuntimeException("Could not write to {$confFile}");
    }

    // Ensure asterisk can read the file
    chmod($confFile, 0664);
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

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
$tech      = (string) ($payload['tech']     ?? 'pjsip');
$settings  = is_array($payload['settings']  ?? null) ? $payload['settings'] : [];

if ($extension === '' || $password === '') {
    respond(['status' => 'error', 'message' => 'Extension and password are required'], 1);
}

try {
    require_once '/etc/freepbx.conf';

    if (!class_exists('FreePBX')) {
        throw new RuntimeException('FreePBX bootstrap did not load correctly');
    }

    $core = \FreePBX::Core();

    if (!$core || !method_exists($core, 'addDevice') || !method_exists($core, 'addUser')) {
        throw new RuntimeException('FreePBX Core addDevice/addUser methods are unavailable');
    }

    if (method_exists($core, 'getDevice') && $core->getDevice($extension)) {
        throw new RuntimeException("Extension {$extension} already exists");
    }

    $deviceSettings = normalizeDeviceSettings($extension, $tech, $name, $password, $settings);

    $userSettings = [
        'extension'              => $extension,
        'name'                   => $name,
        'ringtimer'              => 0,
        'voicemail'              => 'default',
        'noanswer'               => 0,
        'recording'              => 'dontcare',
        'outboundcid'            => '"' . $name . '" <' . $extension . '>',
        'sipname'                => '',
        'mohclass'               => 'default',
        'tech'                   => $tech,
        'cid_masquerade'         => '',
        'callwaiting'            => 'enabled',
        'pinless'                => 'disabled',
        'cfringtimer'            => 0,
        'concurrency_limit'      => '',
        'dictate'                => 'disabled',
        'intercom'               => 'enabled',
        'recording_in_external'  => 'dontcare',
        'recording_in_internal'  => 'dontcare',
        'recording_out_external' => 'dontcare',
        'recording_out_internal' => 'dontcare',
        'answermode'             => 'disabled',
    ];

    try {
        invokeCoreMethod($core, 'addDevice', [$extension, $tech, $deviceSettings], [false]);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'addDevice failed via ' . methodSignature($core, 'addDevice') . ': ' . $e->getMessage(), 0, $e
        );
    }

    try {
        invokeCoreMethod($core, 'addUser', [$extension, $userSettings], [false]);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'addUser failed via ' . methodSignature($core, 'addUser') . ': ' . $e->getMessage(), 0, $e
        );
    }

    if (method_exists('\FreePBX', 'Config')) {
        $config = \FreePBX::Config();
        if (is_object($config) && method_exists($config, 'commit')) {
            $config->commit();
        }
    }

    // Step 1: Write DTLS block to pjsip.endpoint_custom_post.conf
    // This is what drives the Enable DTLS toggle in the FreePBX GUI
    patchDtlsCustomPostConf($extension);

    // Step 2: Flush FreePBX — generates pjsip.endpoint.conf with full settings
    if (function_exists('needreload')) {
        needreload();
    }

    // Step 3: Settle time before DB patch
    usleep(300000); // 300ms

    // Step 4: Patch remaining PJSIP fields in DB
    $db = \FreePBX::Database();
    patchPjsipDb($db, $extension);

    respond([
        'status'    => 'success',
        'message'   => "Extension {$extension} created with DTLS enabled",
        'extension' => $extension,
    ]);

} catch (Throwable $e) {
    respond([
        'status'  => 'error',
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ], 1);
}
