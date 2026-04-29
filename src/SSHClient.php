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
        try {
            $result = $ssh->createExtensionKsip($ext, $ext, $ext, $dbUser, $dbPass);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'extension' => $ext, 'message' => $e->getMessage()];
        }

        return ['status' => 'assigned', 'extension' => $ext, 'result' => $result];
    }

    /**
     * Create a FreePBX-managed extension via a PBX-side PHP script.
     *
     * The DB parameters are kept for backward compatibility with existing call sites,
     * but the creation flow no longer performs direct SQL writes.
     */
    public function createExtensionKsip($ext, $extName, $password, $dbUser, $dbPass, $remoteScript = '/var/lib/asterisk/bin/create_freepbx_extension.php')
    {
        if (!$this->ssh) {
            throw new \Exception("Not connected to SSH");
        }

        // Sanitize inputs
        $ext      = preg_replace('/[^0-9a-zA-Z]/', '', $ext);
        if ($ext === '') {
            throw new \InvalidArgumentException("Extension cannot be empty");
        }

        $payload = [
            'extension' => $ext,
            'name' => $extName,
            'password' => $password,
            'tech' => 'pjsip',
            'settings' => [
                'dtmfmode' => 'rfc4733',
                'disallow' => 'all',
                'allow' => 'ulaw,alaw,vp8,h264',
                'direct_media' => 'no',
                'max_contacts' => '1',
                'rtp_symmetric' => 'yes',
                'rewrite_contact' => 'yes',
                'force_rport' => 'yes',
                'media_encryption' => 'dtls',
                'webrtc' => 'yes',
                'icesupport' => 'yes',
                'avpf' => 'yes',
                'bundle' => 'yes',
                'rtcp_mux' => 'yes',
            ],
        ];

        $command = sprintf(
            'php %s %s 2>&1',
            escapeshellarg($remoteScript),
            escapeshellarg(base64_encode(json_encode($payload)))
        );

        $output = trim($this->ssh->exec($command));

        if ($output === '') {
            throw new \RuntimeException('Remote extension creation script returned no output');
        }

        $result = json_decode($output, true);
        if (!is_array($result)) {
            throw new \RuntimeException("Remote script did not return valid JSON: {$output}");
        }

        if (($result['status'] ?? null) !== 'success') {
            throw new \RuntimeException($result['message'] ?? 'Unknown FreePBX provisioning failure');
        }

        return [
            'script' => $remoteScript,
            'extension' => $ext,
            'remote_result' => $result,
        ];
    }
}
