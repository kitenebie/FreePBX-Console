<?php

namespace KsipTelnet;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KSIPRegisterUser extends Command
{
    protected $signature   = 'make:ksip-register-user';
    protected $description = 'Generate a scheduled command that auto-assigns FreePBX extensions to users with empty extensionName';

    public function handle()
    {
        $this->generateCommand();
        $this->appendSchedule();
        $this->info('KSIP register-user scaffold generated successfully.');
        $this->info('Add SSH/DB credentials to your .env and run: php artisan schedule:run');
    }

    protected function generateCommand()
    {
        $dir  = $this->laravel->make('path') . '/Console/Commands';
        $path = $dir . '/AssignExtensionToUsers.php';

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (File::exists($path)) {
            $this->warn('AssignExtensionToUsers already exists, skipping.');
            return;
        }

        File::put($path, $this->commandStub());
        $this->info('Created: app/Console/Commands/AssignExtensionToUsers.php');
    }

    protected function appendSchedule()
    {
        // Laravel 11+ → routes/console.php with Schedule facade
        $consolePath = $this->laravel->basePath('routes/console.php');
        // Laravel 10 → app/Console/Kernel.php with $schedule->command()
        $kernelPath  = $this->laravel->make('path') . '/Console/Kernel.php';

        if (File::exists($consolePath)) {
            $content = File::get($consolePath);
            if (str_contains($content, 'AssignExtensionToUsers')) {
                $this->warn('Schedule entry already exists in routes/console.php, skipping.');
                return;
            }
            File::append($consolePath, $this->scheduleStubLaravel11());
            $this->info('Appended schedule to routes/console.php');
        } elseif (File::exists($kernelPath)) {
            $content = File::get($kernelPath);
            if (str_contains($content, 'AssignExtensionToUsers')) {
                $this->warn('Schedule entry already exists in Kernel.php, skipping.');
                return;
            }
            // Inject inside the protected function schedule(Schedule $schedule) method body
            $inject  = "        \$schedule->command(\\App\\Console\\Commands\\AssignExtensionToUsers::class)->everyMinute();";
            $updated = preg_replace(
                '/(protected function schedule\s*\(.*?\)\s*\{)/',
                "$1\n{$inject}",
                $content
            );
            if ($updated && $updated !== $content) {
                File::put($kernelPath, $updated);
                $this->info('Injected schedule entry into app/Console/Kernel.php');
            } else {
                $this->warn('Could not auto-inject into Kernel.php. Add manually inside schedule():');
                $this->line("  \$schedule->command(\\App\\Console\\Commands\\AssignExtensionToUsers::class)->everyMinute();");
            }
        } else {
            $this->warn('Could not find routes/console.php or Kernel.php. Register the schedule manually.');
        }
    }

    protected function commandStub(): string
    {
        return <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use KsipTelnet\SSHClient;

class AssignExtensionToUsers extends Command
{
    protected $signature   = 'ksip:assign-extensions';
    protected $description = 'Auto-assign FreePBX extensions to users with empty extensionName';

    public function handle()
    {
        $usersWithExtension = \App\Models\UserSipAccount::pluck('user_id');

        $users = \App\Models\User::whereNotIn('id', $usersWithExtension)->get();

        if ($users->isEmpty()) {
            $this->info('No users need extension assignment.');
            return;
        }

        $ssh = new SSHClient();
        $ssh->connect(
            config('services.freepbx.host'),
            config('services.freepbx.user'),
            config('services.freepbx.pass'),
            config('services.freepbx.port', 22)
        );

        $dbUser = config('services.freepbx.db_user');
        $dbPass = config('services.freepbx.db_pass');

        foreach ($users as $user) 
        {
            $result = SSHClient::ksipRegisterUser($user, $ssh, $dbUser, $dbPass);

            if ($result['status'] === 'assigned') {
                \App\Models\UserSipAccount::create([
                    'user_id'   => $user->id,
                    'extension' => $result['extension'],
                    'extension_password' => $result['extension'],
                ]);
                $this->info("Assigned extension {$result['extension']} to user ID {$user->id}");
            } else {
                $this->warn("User ID {$user->id}: " . ($result['message'] ?? $result['status']));
            }
        }
    }
}
PHP;
    }

    protected function scheduleStubLaravel11(): string
    {
        return <<<'PHP'


use App\Console\Commands\AssignExtensionToUsers;
use Illuminate\Support\Facades\Schedule;

Schedule::command(AssignExtensionToUsers::class)->everyMinute();
PHP;
    }
}
