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

        return $this->ssh->exec($command);
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
        ('{$ext}', 'rtp_timeout',      '0', 42),
        ('{$ext}', 'rtp_timeout_hold', '0', 43),
        ('{$ext}', 'timers',           'no', 32),
        ('{$ext}', 'timers_min_se',    '0', 33),
        ('{$ext}', 'refer_blind_progress',       'yes',                37),
        ('{$ext}', 'send_connected_line',        'yes',                 6),
        ('{$ext}', 'sendrpid',                   'pai',                 8),
        ('{$ext}', 'trustrpid',                  'yes',                 5),
        ('{$ext}', 'aggregate_mwi',              'yes',                27),
        ('{$ext}', 'mwi_subscription',           'auto',               26),
        ('{$ext}', 'qualifyfreq',                '60',                  9),
        ('{$ext}', 'device_state_busy_at',       '0',                  38),
        ('{$ext}', 'remove_existing',            'yes',                21),
        ('{$ext}', 'outbound_auth',              'yes',                45),
        ('{$ext}', 'outbound_proxy',             '',                   44),
        ('{$ext}', 'transport',                  '',                   10),
        ('{$ext}', 'namedcallgroup',             '',                   14),
        ('{$ext}', 'namedpickupgroup',           '',                   15),
        ('{$ext}', 'match',                      '',                   39),
        ('{$ext}', 'minimum_expiration',         '60',                 41),
        ('{$ext}', 'maximum_expiration',         '7200',               40),
        ('{$ext}', 'defaultuser',                '',                    4),
        ('{$ext}', 'media_address',              '',                   35),
        ('{$ext}', 'message_context',            '',                   46),
        ('{$ext}', 'user_eq_phone',              'no',                  7)",

            // 4. Insert pjsip endpoint
            "INSERT INTO pjsip (id, keyword, data, flags) VALUES
        ('{$ext}',       'type',         'endpoint', 0),
        ('{$ext}',       'aors',         '{$ext}',   0),
        ('{$ext}',       'auth',         '{$ext}-auth', 0)",

            // 5. Insert pjsip auth
            "INSERT INTO pjsip (id, keyword, data, flags) VALUES
        ('{$ext}-auth',  'type',         'auth',     0),
        ('{$ext}-auth',  'auth_type',    'userpass', 0),
        ('{$ext}-auth',  'username',     '{$ext}',   0),
        ('{$ext}-auth',  'password',     '{$password}', 0)",

            // 6. Insert pjsip aor
            "INSERT INTO pjsip (id, keyword, data, flags) VALUES
        ('{$ext}-aor',   'type',         'aor',      0),
        ('{$ext}-aor',   'max_contacts', '1',        0)",
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

        // Reload FreePBX
        $reloadOutput = $this->ssh->exec('fwconsole reload 2>&1');

        return [
            'sql_output'    => implode("\n", $sqlOutputs),
            'reload_output' => trim($reloadOutput)
        ];
    }
}
