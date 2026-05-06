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

function invokeCoreMethod(object $core, string $method, array $baseArgs, array $optionalArgs = [])
{
    $reflection    = new ReflectionMethod($core, $method);
    $requiredCount = $reflection->getNumberOfRequiredParameters();
    $maxCount      = $reflection->getNumberOfParameters();

    $args = $baseArgs;
    foreach ($optionalArgs as $arg) {
        if (count($args) >= $maxCount) {
            break;
        }
        $args[] = $arg;
    }

    if (count($args) < $requiredCount) {
        throw new RuntimeException(sprintf(
            '%s expects at least %d arguments, only %d prepared',
            $method,
            $requiredCount,
            count($args)
        ));
    }

    return $reflection->invokeArgs($core, $args);
}

function methodSignature(object $core, string $method): string
{
    $reflection = new ReflectionMethod($core, $method);
    $names      = [];
    foreach ($reflection->getParameters() as $parameter) {
        $names[] = '$' . $parameter->getName();
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
        'allow'         => 'ulaw,alaw',
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

        $normalized[$key] = [
            'value' => $value,
            'flag'  => $flag++,
        ];
    }

    if ($tech === 'pjsip') {
        // Force-override these fields always — do NOT use empty() check.
        // FreePBX's addDevice() sets its own defaults (e.g. timers=yes)
        // which would win if we only set when empty.
        $pjsipForced = [
            'max_contacts'      => '1',
            'max_video_streams' => '2',
            'timers'            => 'no',    // ← must be forced, not conditional
            'media_encryption'  => 'dtls',
            'webrtc'            => 'yes',
            'icesupport'        => 'yes',
            'bundle'            => 'yes',
            'rtcp_mux'          => 'yes',
            'rtp_symmetric'     => 'yes',
            'rewrite_contact'   => 'yes',
            'force_rport'       => 'yes',
            'direct_media'      => 'no',
        ];

        foreach ($pjsipForced as $key => $val) {
            $normalized[$key] = ['value' => $val, 'flag' => $flag++];
        }
    }

    return $normalized;
}

/**
 * Patch PJSIP fields directly in the DB using PDO prepared statements.
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE to guarantee the value is set
 * regardless of whether the row already exists — avoids ADODB isError().
 *
 * Note: timers_min_se minimum is 90 per RFC 4028; Asterisk ignores lower
 * values. It is harmless when timers = no, which disables session timers.
 */
function patchPjsipFields(object $db, string $extension): void
{
    $fields = [
        'timers'              => 'no',    // ← most important: disable session timers
        'timers_min_se'       => '0',    // RFC minimum; irrelevant when timers=no
        'timers_sess_expires' => '0',  // same
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
            fwrite(STDERR, "Warning: could not patch {$keyword} for {$extension}: " . $e->getMessage() . PHP_EOL);
        }
    }
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
        throw new RuntimeException('FreePBX Core addDevice/addUser methods are unavailable on this system');
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
            'addDevice failed via ' . methodSignature($core, 'addDevice') . ': ' . $e->getMessage(),
            0,
            $e
        );
    }

    try {
        invokeCoreMethod($core, 'addUser', [$extension, $userSettings], [false]);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'addUser failed via ' . methodSignature($core, 'addUser') . ': ' . $e->getMessage(),
            0,
            $e
        );
    }

    if (method_exists('\FreePBX', 'Config')) {
        $config = \FreePBX::Config();
        if (is_object($config) && method_exists($config, 'commit')) {
            $config->commit();
        }
    }

    // Flush pending writes before patching
    if (function_exists('needreload')) {
        needreload();
    }

    // Settle time to let addDevice/addUser flush rows to DB
    usleep(300000); // 300ms

    // Patch PJSIP fields using safe PDO INSERT ... ON DUPLICATE KEY UPDATE
    $db = \FreePBX::Database();
    patchPjsipFields($db, $extension);

    respond([
        'status'    => 'success',
        'message'   => "Extension {$extension} created via FreePBX Core",
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
