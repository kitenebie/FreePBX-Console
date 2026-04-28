<?php

namespace KsipTelnet;

use phpseclib3\Net\SSH2;

class SSHClient
{
    protected $ssh;

    public function connect($host, $user, $pass, $port = 22)
    {
        $this->ssh = new SSH2($host, $port);

        if (!$this->ssh->login($user, $pass)) {
            throw new \Exception("SSH Login Failed");
        }

        return true;
    }

    public function exec($command)
    {
        if (!$this->ssh) {
            throw new \Exception("Not connected to SSH");
        }

        $result = $this->ssh->exec($command);
        $this->ssh->reset(); // ← idagdag
        return $result;
    }

    /**
     * Generate a numeric extension from user's name acronym + birthdate.
     *
     * Formula: acronym of (last_name, first_name, middle_name) first letters
     *          each letter → position number (A=01, B=02 … Z=26, padded to 2 digits)
     *          + birth_date digits only (mmddyy)
     *
     * Example: Luna Juan Mercado, 12/16/1996
     *   Acronym: L(12) J(10) M(13) → 121013
     *   Birthdate: 121696
     *   Extension: 121013121696
     */
    public static function generateExtensionFromUser($lastName, $firstName, $middleName, $birthDate): string
    {
        $initials = [
            strtoupper(substr(trim($lastName),   0, 1)),
            strtoupper(substr(trim($firstName),  0, 1)),
            strtoupper(substr(trim($middleName), 0, 1)),
        ];

        $acronymNum = '';
        foreach ($initials as $letter) {
            if ($letter === '') continue;
            $pos = ord($letter) - ord('A') + 1; // A=1 … Z=26
            $acronymNum .= str_pad($pos, 2, '0', STR_PAD_LEFT);
        }

        // Keep only digits from birthdate (handles any separator)
        $dateDigits = preg_replace('/\D/', '', $birthDate); // e.g. 12161996
        // Use mmddyy (6 digits)
        if (strlen($dateDigits) === 8) {
            // yyyymmdd or mmddyyyy — detect by checking if first 4 look like a year
            if ((int)substr($dateDigits, 0, 4) > 1900) {
                // yyyymmdd → mmddyy
                $dateDigits = substr($dateDigits, 4, 2) . substr($dateDigits, 6, 2) . substr($dateDigits, 2, 2);
            } else {
                // mmddyyyy → mmddyy
                $dateDigits = substr($dateDigits, 0, 4) . substr($dateDigits, 6, 2);
            }
        }

        $ext = $acronymNum . $dateDigits;

        // Pad with random digits until exactly 12 digits
        while (strlen($ext) < 12) {
            $ext .= random_int(0, 9);
        }

        return $ext;
    }

    /**
     * Assign a FreePBX extension to a single user.
     * Can be called from a cron job OR directly from a controller.
     *
     * @param  object      $user     Eloquent User model instance
     * @param  SSHClient   $ssh      Already-connected SSHClient instance
     * @param  string      $dbUser   MySQL username
     * @param  string      $dbPass   MySQL password
     * @return array       ['status' => 'assigned'|'skipped'|'error', 'extension' => ..., 'message' => ...]
     */
    public static function ksipRegisterUser($user, SSHClient $ssh, string $dbUser, string $dbPass): array
    {
        if (empty($user->mobile_number)) {
            return ['status' => 'error', 'extension' => null, 'message' => 'Could not assign extension: missing mobile number'];
        }

        $ext = $user->mobile_number;

        $result = $ssh->createExtensionKsip($ext, $ext, $ext, $dbUser, $dbPass);

        return ['status' => 'assigned', 'extension' => $ext, 'result' => $result];
    }

    /**
     * Create FreePBX Extension via MySQL
     */
    public function createExtensionKsip($ext, $extName, $password, $dbUser, $dbPass)
    {
        if (!$this->ssh) {
            throw new \Exception("Not connected to SSH");
        }

        // Sanitize inputs
        $ext      = preg_replace('/[^0-9a-zA-Z]/', '', $ext);
        $password = addslashes($password);

        $dbPassArg = !empty($dbPass) ? "-p'{$dbPass}'" : "";
        $mysql     = "mysql -u {$dbUser} {$dbPassArg} asterisk -e";

        $queries = [
            // 1. Insert user
            "INSERT INTO users (extension, name, voicemail, noanswer, recording, outboundcid, sipname, noanswer_cid, busy_cid, chanunavail_cid, noanswer_dest, busy_dest, chanunavail_dest, mohclass) VALUES ('{$ext}', '{$ext}', 'default', '', '', '{$extName}', '', '', '', '', '', '', '', 'default')",

            // 2. Insert device
            "INSERT INTO devices (id, tech, dial, devicetype, user, description) VALUES ('{$ext}', 'pjsip', 'PJSIP/{$ext}', 'fixed', '{$ext}', '{$ext}')",

            // 3. Insert sip settings
            "INSERT INTO sip (id, keyword, data, flags) VALUES
        ('{$ext}', 'account',                    '{$ext}',             50),
        ('{$ext}', 'accountcode',                '',                   19),
        ('{$ext}', 'secret',                     '{$password}',         2),
        ('{$ext}', 'secret_origional',           '{$password}',        48),
        ('{$ext}', 'callerid',                   '{$ext} <{$ext}>',    51),
        ('{$ext}', 'context',                    'from-internal',      47),
        ('{$ext}', 'dial',                       'PJSIP/{$ext}',       18),
        ('{$ext}', 'dtmfmode',                   'rfc4733',             3),
        ('{$ext}', 'sipdriver',                  'chan_pjsip',         49),
        ('{$ext}', 'disallow',                   'all',                16),
        ('{$ext}', 'allow',                      'ulaw&alaw&vp8&h264', 17),
        ('{$ext}', 'direct_media',               'no',                 34),
        ('{$ext}', 'avpf',                       'yes',                11),
        ('{$ext}', 'webrtc',                     'yes',                11),
        ('{$ext}', 'icesupport',                 'yes',                12),
        ('{$ext}', 'bundle',                     'yes',                28),
        ('{$ext}', 'rtcp_mux',                   'yes',                13),
        ('{$ext}', 'media_encryption',           'dtls',               31),
        ('{$ext}', 'media_encryption_optimistic','no',                 36),
        ('{$ext}', 'media_use_received_transport','no',                22),
        ('{$ext}', 'max_audio_streams',          '1',                  29),
        ('{$ext}', 'max_video_streams',          '2',                  30),
        ('{$ext}', 'max_contacts',               '1',                  20),
        ('{$ext}', 'force_rport',                'yes',                25),
        ('{$ext}', 'rewrite_contact',            'yes',                24),
        ('{$ext}', 'rtp_symmetric',              'yes',                23),
        ('{$ext}', 'rtp_timeout',                '0',                  42),
        ('{$ext}', 'rtp_timeout_hold',           '0',                  43),
        ('{$ext}', 'timers',                     'no',                 32),
        ('{$ext}', 'timers_min_se',              '0',                  33),
        ('{$ext}', 'refer_blind_progress',       'yes',                37),
        ('{$ext}', 'send_connected_line',        'yes',                  6),
        ('{$ext}', 'sendrpid',                   'pai',                  8),
        ('{$ext}', 'trustrpid',                  'yes',                  5),
        ('{$ext}', 'aggregate_mwi',              'yes',                 27),
        ('{$ext}', 'mwi_subscription',           'auto',                26),
        ('{$ext}', 'qualifyfreq',                '60',                   9),
        ('{$ext}', 'device_state_busy_at',       '0',                   38),
        ('{$ext}', 'remove_existing',            'yes',                 21),
        ('{$ext}', 'outbound_auth',              'yes',                 45),
        ('{$ext}', 'outbound_proxy',             '',                    44),
        ('{$ext}', 'transport',                  '',                    10),
        ('{$ext}', 'namedcallgroup',             '',                    14),
        ('{$ext}', 'namedpickupgroup',           '',                    15),
        ('{$ext}', 'match',                      '',                    39),
        ('{$ext}', 'minimum_expiration',         '60',                  41),
        ('{$ext}', 'maximum_expiration',         '7200',                40),
        ('{$ext}', 'defaultuser',                '',                     4),
        ('{$ext}', 'media_address',              '',                    35),
        ('{$ext}', 'message_context',            '',                    46),
        ('{$ext}', 'dtlsenable',                 'yes',                  0),
        ('{$ext}', 'user_eq_phone',              'no',                   7)",

            // 4. Insert pjsip endpoint
            "INSERT INTO pjsip (id, keyword, data, flags) VALUES
        ('{$ext}',       'type',         'endpoint',    0),
        ('{$ext}',       'aors',         '{$ext}',      0),
        ('{$ext}',       'auth',         '{$ext}-auth', 0)",

            // 5. Insert pjsip auth
            "INSERT INTO pjsip (id, keyword, data, flags) VALUES
        ('{$ext}-auth',  'type',         'auth',        0),
        ('{$ext}-auth',  'auth_type',    'userpass',    0),
        ('{$ext}-auth',  'username',     '{$ext}',      0),
        ('{$ext}-auth',  'password',     '{$password}', 0)",

            // 6. Insert pjsip aor
            "INSERT INTO pjsip (id, keyword, data, flags) VALUES
        ('{$ext}-aor',   'type',         'aor',         0),
        ('{$ext}-aor',   'max_contacts', '1',           0)",

            // 7. Insert certman mapping para sa DTLS
            "INSERT INTO certman_mapping (id, cid, verify, setup, rekey, auto_generate_cert)
        VALUES ('{$ext}', 2, 'fingerprint', 'actpass', 0, 0)",
        ];

        // Run each query separately
        $sqlOutputs = [];
        foreach ($queries as $index => $query) {
            $escapedQuery = str_replace("'", "'\\''", $query);
            $cmd          = "{$mysql} '{$escapedQuery}' 2>&1";
            $output       = $this->ssh->exec($cmd);
            if (!empty(trim($output))) {
                $sqlOutputs[] = "Query " . ($index + 1) . ": " . trim($output);
            }
        }

        // 8. I-append ang DTLS cert files sa pjsip.endpoint_custom_post.conf
        $dtlsConfig  = "\n[{$ext}]\n";
        $dtlsConfig .= "dtls_cert_file=/etc/asterisk/keys/default.crt\n";
        $dtlsConfig .= "dtls_private_key=/etc/asterisk/keys/default.key\n";
        $appendCmd   = "echo '{$dtlsConfig}' >> /etc/asterisk/pjsip.endpoint_custom_post.conf 2>&1";
        $appendOutput = $this->ssh->exec($appendCmd);
        if (!empty(trim($appendOutput))) {
            $sqlOutputs[] = "DTLS Config: " . trim($appendOutput);
        }

        return [
            'sql_output'    => implode("\n", $sqlOutputs)
        ];
    }
}
