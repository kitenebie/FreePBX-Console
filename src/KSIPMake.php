<?php

namespace KsipTelnet;

use Illuminate\Console\Command;

class KSIPMake extends Command
{
    protected $signature = 'make:ksip
                            {--extension= : The extension number}
                            {--name=      : The display name / caller ID}
                            {--secret=    : The SIP password (defaults to extension number if omitted)}';

    protected $description = 'Create a FreePBX PJSIP extension via SSH';

    public function handle()
    {
        $ext    = trim($this->option('extension') ?? '');
        $name   = trim($this->option('name')      ?? '');
        $secret = trim($this->option('secret')    ?? '');

        // --- Validate required options ---
        if ($ext === '') {
            $this->error('--extension is required.');
            return 1;
        }

        if ($name === '') {
            $this->error('--name is required.');
            return 1;
        }

        if ($secret === '') {
            $secret = $ext;
            $this->line("No --secret provided, using extension number as password: <comment>{$secret}</comment>");
        }

        // --- Read SSH + DB config ---
        $host   = config('services.freepbx.host');
        $user   = config('services.freepbx.user');
        $pass   = config('services.freepbx.pass');
        $port   = config('services.freepbx.port', 22);
        $dbUser = config('services.freepbx.db_user');
        $dbPass = config('services.freepbx.db_pass');

        if (!$host || !$user) {
            $this->error('FreePBX SSH credentials are not configured in config/services.php (services.freepbx).');
            return 1;
        }

        $this->line("Connecting to <comment>{$host}:{$port}</comment> ...");

        try {
            $ssh = new SSHClient();
            $ssh->connect($host, $user, $pass, $port);
        } catch (\Exception $e) {
            $this->error('SSH connection failed: ' . $e->getMessage());
            return 1;
        }

        $this->line("Creating extension <comment>{$ext}</comment> (<comment>{$name}</comment>) ...");

        try {
            $result = $ssh->createExtensionKsip($ext, $name, $secret, $dbUser, $dbPass);
        } catch (\Exception $e) {
            $this->error('Failed to create extension: ' . $e->getMessage());
            return 1;
        }

        $this->info("Extension <comment>{$ext}</comment> created successfully.");

        if (!empty($result['sql_output'])) {
            $this->line('<fg=yellow>SQL output:</> ' . $result['sql_output']);
        }

        if (!empty($result['reload_output'])) {
            $this->line('<fg=cyan>Reload output:</> ' . $result['reload_output']);
        }

        return 0;
    }
}
