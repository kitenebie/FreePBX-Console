<?php

namespace KsipTelnet;

use phpseclib3\Net\SSH2;

class SSHClient
{
    protected $ssh;

    public function connect($host, $user, $pass)
    {
        $this->ssh = new SSH2($host);

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
    public function createExtension($ext, $password, $dbUser, $dbPass)
    {
        if (!$this->ssh) {
            throw new \Exception("Not connected to SSH");
        }

        // SQL script
        $sql = "
        INSERT INTO users (extension, name, voicemail)
        VALUES ('$ext', '$ext', 'default');

        INSERT INTO devices (id, tech, dial, user)
        VALUES ('$ext', 'pjsip', 'PJSIP/$ext', '$ext');

        INSERT INTO pjsip (id, keyword, data, flags) VALUES
        ('$ext','type','endpoint',0),
        ('$ext','aors','$ext',0),
        ('$ext','auth','$ext-auth',0),
        ('$ext','secret','$password',0),
        ('$ext','call_waiting','1',0),
        ('$ext','tos_audio','ef',0),
        ('$ext','tos_video','af41',0),
        ('$ext','cos_audio','5',0),
        ('$ext','cos_video','4',0),
        ('$ext','disallow','all',0),
        ('$ext','allow','ulaw,alaw,vp8,h264',0),
        ('$ext','context','from-internal',0),
        ('$ext','callerid','$ext <$ext>',0),
        ('$ext','dtmf_mode','rfc4733',0),
        ('$ext','direct_media','no',0),
        ('$ext','mailboxes','$ext@default',0),
        ('$ext','mwi_subscribe_replaces_unsolicited','yes',0),
        ('$ext','aggregate_mwi','yes',0),
        ('$ext','use_avpf','yes',0),
        ('$ext','webrtc','yes',0),
        ('$ext','rtcp_mux','yes',0),
        ('$ext','max_audio_streams','1',0),
        ('$ext','max_video_streams','2',0),
        ('$ext','bundle','yes',0),
        ('$ext','ice_support','yes',0),
        ('$ext','media_use_received_transport','no',0),
        ('$ext','trust_id_inbound','yes',0),
        ('$ext','user_eq_phone','no',0),
        ('$ext','send_connected_line','yes',0),
        ('$ext','media_encryption','dtls',0),
        ('$ext','timers','no',0),
        ('$ext','media_encryption_optimistic','no',0),
        ('$ext','refer_blind_progress','yes',0),
        ('$ext','rtp_timeout','0',0),
        ('$ext','rtp_timeout_hold','0',0),
        ('$ext','rtp_keepalive','0',0),
        ('$ext','send_pai','yes',0),
        ('$ext','rtp_symmetric','yes',0),
        ('$ext','rewrite_contact','yes',0),
        ('$ext','force_rport','yes',0),
        ('$ext','language','en',0),
        ('$ext','one_touch_recording','on',0),
        ('$ext','record_on_feature','apprecord',0),
        ('$ext','record_off_feature','apprecord',0),
        ('$ext','dtls_verify','fingerprint',0),
        ('$ext','dtls_setup','actpass',0),
        ('$ext','dtls_rekey','0',0),
        ('$ext','dtls_cert_file','/etc/asterisk/keys/default.crt',0),
        ('$ext','dtls_private_key','/etc/asterisk/keys/default.key',0),

        ('$ext-auth','type','auth',0),
        ('$ext-auth','auth_type','userpass',0),
        ('$ext-auth','username','$ext',0),
        ('$ext-auth','password','$password',0),

        ('$ext','type','aor',0),
        ('$ext','max_contacts','1',0);
        ";

        // Escape quotes for shell
        $sql = str_replace('"', '\"', $sql);

        // Execute SQL + reload in a single channel
        $cmd = "mysql -u $dbUser -p'$dbPass' asterisk -e \"$sql\" 2>&1; echo '---RELOAD---'; fwconsole reload 2>&1";
        $output = $this->ssh->exec($cmd);

        $parts = explode('---RELOAD---', $output);

        return [
            'sql_output' => trim($parts[0] ?? ''),
            'reload_output' => trim($parts[1] ?? '')
        ];
    }
}