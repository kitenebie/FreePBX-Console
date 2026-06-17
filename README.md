# php-ksip-telnet

A PHP library for managing FreePBX/Asterisk PJSIP extensions remotely via SSH.

## FreePBX-Safe Extension Creation

Direct SQL inserts into `users`, `devices`, `sip`, or the generic `pjsip` table can produce half-created extensions that appear in dialplan but are not fully managed FreePBX endpoints.

This library now expects extension creation to happen through a PBX-side PHP script that bootstraps FreePBX and uses its Core module methods. FreePBX 17.0.28+ compatible scripts are included in the `stubs/` directory.

### FreePBX 17 Deployment (Recommended)

For FreePBX version 17.0.28 and newer, use the enhanced v17 script:

```bash
scp stubs/create_freepbx_extension_v17.php root@your-pbx:/var/lib/asterisk/bin/create_freepbx_extension.php
ssh root@your-pbx "chmod 755 /var/lib/asterisk/bin/create_freepbx_extension.php"
```

### Legacy FreePBX (Older Versions)

For older FreePBX versions, use the original script:

```bash
scp stubs/create_freepbx_extension.php root@your-pbx:/var/lib/asterisk/bin/create_freepbx_extension.php
ssh root@your-pbx "chmod 755 /var/lib/asterisk/bin/create_freepbx_extension.php"
```

### What's Different in v17?

- ✅ **Simplified Core API calls** compatible with FreePBX 17.0.28+
- ✅ **Proper AOR creation** - fixes "Unable to find object" errors
- ✅ **WebRTC-optimized settings** (DTLS, ICE, Bundle, RTCP-MUX)
- ✅ **Automatic reload** with detailed output
- ✅ **Better error handling** and troubleshooting info

### Auto-deployment

The script is automatically uploaded to your FreePBX server when you run `make:ksip`. Manual deployment is only needed once for initial setup or updates.

## WEB DOCUMENTATION
**Visit Documentation:** [php-ksip-telnet & juv-ksip-softphone Documentation](https://kitenebie.github.io/FreePBX-Console/)

## Requirements

- PHP >= 7.4
- Composer

## Installation

```bash
composer require codego/php-ksip-telnet
```

## Usage

### 1. Connect to SSH

```php
<?php

require 'vendor/autoload.php';

use KsipTelnet\SSHClient;

$client = new SSHClient();

// Default port 22
$client->connect('your-server-ip', 'root', 'your-password');

// Custom port
$client->connect('your-server-ip', 'root', 'your-password', 2222);
```

### 2. Create a PJSIP Extension

```php
$result = $client->createExtensionKsip(
    '1001',          // extension number
    'secret123',     // extension password
    'freepbxuser',   // MySQL username
    'dbpassword'     // MySQL password
);

echo $result['sql_output'];
echo $result['reload_output'];
```

### 3. Get All Extensions

```php
$extensions = $client->getExtKsipList('freepbxuser', 'dbpassword');
// returns: ['1001', '1002', '1003', ...]
```

### 4. Generate Extension if Not Exists

```php
$result = $client->genExtKsip(
    [
        'extName'  => '1005',
        'password' => 'secret123'  // optional, defaults to extName
    ],
    'freepbxuser',
    'dbpassword'
);

// If extension already exists:
// ['status' => 'exists', 'extension' => '1005', 'message' => 'Extension 1005 already exists']

// If extension was created:
// ['status' => 'created', 'extension' => '1005', 'result' => [...]]
```

### 5. Run a Custom SSH Command

```php
$output = $client->exec('asterisk -rx "pjsip show endpoints"');
echo $output;
```

## Environment Variables (Recommended)

Instead of hardcoding credentials, use environment variables:

```bash
export SSH_HOST=your-server-ip
export SSH_USER=root
export SSH_PASS=your-password
export SSH_PORT=22
```

```php
$client->connect(
    getenv('SSH_HOST'),
    getenv('SSH_USER'),
    getenv('SSH_PASS'),
    getenv('SSH_PORT') ?: 22
);
```

## License

MIT

---

## Laravel Integration

### 1. Install the package

```bash
composer require codego/php-ksip-telnet
```

### 2. Add credentials to `.env`

```env
SSH_HOST=your-server-ip
SSH_USER=root
SSH_PASS=your-password
SSH_PORT=22
SSH_DB_USER=freepbxuser
SSH_DB_PASS=dbpassword
```

### 3. Create a Service class

```bash
php artisan make:service FreePBXService
```

```php
<?php

namespace App\Services;

use KsipTelnet\SSHClient;

class FreePBXService
{
    protected SSHClient $client;

    public function __construct()
    {
        $this->client = new SSHClient();
        $this->client->connect(
            config('services.freepbx.host'),
            config('services.freepbx.user'),
            config('services.freepbx.pass'),
            config('services.freepbx.port', 22)
        );
    }

    public function createExtensionKsip(string $ext, string $password): array
    {
        return $this->client->createExtensionKsip(
            $ext,
            $password,
            config('services.freepbx.db_user'),
            config('services.freepbx.db_pass')
        );
    }

    public function getExtKsipList(): array
    {
        return $this->client->getExtKsipList(
            config('services.freepbx.db_user'),
            config('services.freepbx.db_pass')
        );
    }

    public function genExtKsip(array $data): array
    {
        return $this->client->genExtKsip(
            $data,
            config('services.freepbx.db_user'),
            config('services.freepbx.db_pass')
        );
    }

    public function exec(string $command): string
    {
        return $this->client->exec($command);
    }
}
```

### 4. Register in `config/services.php`

```php
'freepbx' => [
    'host'    => env('SSH_HOST'),
    'user'    => env('SSH_USER'),
    'pass'    => env('SSH_PASS'),
    'port'    => env('SSH_PORT', 22),
    'db_user' => env('SSH_DB_USER'),
    'db_pass' => env('SSH_DB_PASS'),
],
```

### 5. Bind in `AppServiceProvider`

```php
use App\Services\FreePBXService;

public function register(): void
{
    $this->app->singleton(FreePBXService::class);
}
```

### 6. Use in a Controller

```bash
php artisan make:controller ExtensionController
```

```php
<?php

namespace App\Http\Controllers;

use App\Services\FreePBXService;
use Illuminate\Http\Request;

class ExtensionController extends Controller
{
    public function __construct(protected FreePBXService $freepbx) {}

    public function store(Request $request)
    {
        $request->validate([
            'extension' => 'required|numeric',
            'password'  => 'required|string',
        ]);

        $result = $this->freepbx->createExtensionKsip(
            $request->extension,
            $request->password
        );

        return response()->json($result);
    }

    public function index()
    {
        return response()->json($this->freepbx->getExtKsipList());
    }

    public function generate(Request $request)
    {
        $request->validate([
            'extName'  => 'required|numeric',
            'password' => 'nullable|string',
        ]);

        $result = $this->freepbx->genExtKsip($request->only('extName', 'password'));

        return response()->json($result);
    }
}
```

### 7. Add route in `routes/api.php`

```php
use App\Http\Controllers\ExtensionController;

Route::get('/extensions', [ExtensionController::class, 'index']);
Route::post('/extensions', [ExtensionController::class, 'store']);
Route::post('/extensions/generate', [ExtensionController::class, 'generate']);
```

### 8. Test via API

```bash
curl -X POST http://your-app.com/api/extensions \
  -H "Content-Type: application/json" \
  -d '{"extension": "1001", "password": "secret123"}'
```

---

## `make:ksip-config` — Per-User Softphone Settings

Generates a migration, model, controller, and API routes so each user can save their own softphone configuration in the database.

```bash
php artisan make:ksip-config
php artisan migrate
```

### What it generates

- `database/migrations/{timestamp}_create_softphone_configs_table.php`
- `app/Models/SoftphoneConfig.php`
- `app/Http/Controllers/Api/SoftphoneConfigController.php`
- Appends routes to `routes/api.php`

### Migration Schema

```php
Schema::create('softphone_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');

    // SIP Connection
    $table->string('server')->nullable();
    $table->enum('ws_protocol', ['ws', 'wss'])->default('ws');
    $table->string('ws_port')->default('8088');
    $table->string('extension')->nullable();
    $table->string('password')->nullable();
    $table->string('display_name')->nullable();

    // Codecs
    $table->json('audio_codecs')->nullable();
    $table->json('video_codecs')->nullable();

    // UI Toggles
    $table->boolean('enabled_bubble')->default(true);
    $table->boolean('show_dialer')->default(true);
    $table->boolean('show_setting')->default(true);
    $table->boolean('show_opacity')->default(true);
    $table->boolean('answer_with_video_call')->default(false);
    $table->boolean('show_incoming_call_video_btn')->default(true);
    $table->boolean('show_incoming_call_audio')->default(true);
    $table->boolean('fullscreen')->default(false);

    // Recording
    $table->boolean('auto_record')->default(false);
    $table->string('recording_dir')->default('video/recordings/Ksip');
    $table->string('upload_api_url')->nullable();

    // Position
    $table->integer('position_top')->nullable();
    $table->integer('position_bottom')->nullable();
    $table->integer('position_left')->nullable();
    $table->integer('position_right')->nullable();

    $table->unique('user_id'); // one config per user
    $table->timestamps();
});
```

### Generated API Routes

```php
Route::middleware(['auth:sanctum,web'])->group(function () {
    Route::get('/softphone-config',  [SoftphoneConfigController::class, 'show']);
    Route::post('/softphone-config', [SoftphoneConfigController::class, 'save']);
});
```

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/softphone-config` | Get the authenticated user's config |
| `POST` | `/api/softphone-config` | Save / update the authenticated user's config |

### Save Config Example

```bash
curl -X POST http://your-app.com/api/softphone-config \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "server": "192.168.1.100",
    "ws_protocol": "ws",
    "ws_port": "8088",
    "extension": "1001",
    "password": "mypassword",
    "display_name": "Juan Luna",
    "audio_codecs": ["PCMU", "PCMA"],
    "video_codecs": ["VP8", "H264"],
    "auto_record": true
  }'
```

### React Integration — `configApiUrl` prop

Pass the API endpoint to the `Softphone` component. It will fetch the user's saved config on mount and fall back to default values if no config is found.

```jsx
<Softphone
  configApiUrl="/api/softphone-config"
  configApiToken={userToken}  // Bearer token for auth:sanctum
/>
```

If the API returns `{ "data": null }`, the component uses its built-in default config.

---

## SipController — Artisan Command Integration

Create extensions via HTTP API by calling the `make:ksip` Artisan command from a controller.

### Controller Implementation

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SipController extends Controller
{
    public function createExtension(Request $request)
    {
        $request->validate([
            'extension' => 'required|string',
            'name'      => 'required|string',
            'secret'    => 'required|string',
        ]);

        // Call the Artisan command
        $exitCode = Artisan::call('make:ksip', [
            '--extension' => $request->extension,
            '--name'      => $request->name,
            '--secret'    => $request->secret,
        ]);

        // Get the output of the command
        $output = Artisan::output();

        if ($exitCode === 0) {
            return response()->json([
                'success' => true,
                'message' => "Extension {$request->extension} created successfully!",
                'output'  => $output,
            ], 201);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to create extension',
            'output'  => $output,
        ], 500);
    }
}
```

### Route Registration

```php
// routes/api.php
Route::post('/sip/create-extension', [SipController::class, 'createExtension']);
```

### API Usage

```bash
curl -X POST http://your-app.com/api/sip/create-extension \
  -H "Content-Type: application/json" \
  -d '{
    "extension": "1001",
    "name": "Juan Luna",
    "secret": "mypassword"
  }'
```

---

## `make:ksip` — Create Extension via CLI

Create a FreePBX PJSIP extension directly from the terminal without writing any PHP code.

```bash
php artisan make:ksip --extension="1001" --name="Juan Luna" --secret="mypassword"
```

`--secret` is optional — if omitted, the extension number is used as the password.

```bash
php artisan make:ksip --extension="1001" --name="Juan Luna"
# Password defaults to: 1001
```

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--extension` | ✅ | The PJSIP extension number |
| `--name` | ✅ | Display name / Caller ID |
| `--secret` | ❌ | SIP password (defaults to extension number) |

### Prerequisites

Make sure `config/services.php` has the `freepbx` block:

```php
'freepbx' => [
    'host'    => env('SSH_HOST'),
    'user'    => env('SSH_USER'),
    'pass'    => env('SSH_PASS'),
    'port'    => env('SSH_PORT', 22),
    'db_user' => env('SSH_DB_USER'),
    'db_pass' => env('SSH_DB_PASS'),
],
```

### FreePBX 17 Troubleshooting

If you see "Unable to find object" errors after creating extensions:

1. **Verify script deployment:**
   ```bash
   ssh root@your-pbx "ls -la /var/lib/asterisk/bin/create_freepbx_extension.php"
   ```

2. **Check script permissions:**
   ```bash
   ssh root@your-pbx "chmod 755 /var/lib/asterisk/bin/create_freepbx_extension.php"
   ```

3. **Test manual execution:**
   ```bash
   ssh root@your-pbx
   php /var/lib/asterisk/bin/create_freepbx_extension.php "$(echo '{"extension":"test123","name":"Test","password":"test123"}' | base64)"
   ```

4. **Check FreePBX logs:**
   ```bash
   tail -f /var/log/asterisk/full
   ```

The `make:ksip` command automatically uses the FreePBX 17 compatible script and provides detailed output including reload status and endpoint verification.

---

## Auto Extension Registration (ksipRegisterUser)

Automatically generates a 12-digit extension number from a user's name acronym + birthdate, then registers it in FreePBX.

### Extension Number Formula

| Part | Example | Result |
|------|---------|--------|
| Last name initial (L = 12th letter) | Luna | `12` |
| First name initial (J = 10th letter) | Juan | `10` |
| Middle name initial (M = 13th letter) | Mercado | `13` |
| Birthdate (mmddyy) | 12/16/1996 | `121696` |
| **Final Extension** | | **`121013121696`** |

If the result is less than 12 digits, random digits are appended to complete it.

### 1. Scaffold the cron job

```bash
php artisan make:ksip-register-user
```

This generates:
- `app/Console/Commands/AssignExtensionToUsers.php`
- Appends schedule entry to `routes/console.php` (Laravel 11+)

### 2. Add to `.env`

```env
SSH_HOST=your-server-ip
SSH_USER=root
SSH_PASS=your-password
SSH_PORT=22
SSH_DB_USER=freepbxuser
SSH_DB_PASS=dbpassword
```

### 3. Run the scheduler

```bash
# Run once manually
php artisan ksip:assign-extensions

# Or let the scheduler run it every minute
php artisan schedule:run
```

### Production: Auto-run via Supervisor

In production, use **Supervisor** to keep the scheduler running automatically — no need to manually trigger it.

**Install Supervisor:**
```bash
sudo apt install supervisor
```

**Create config file:**
```bash
sudo nano /etc/supervisor/conf.d/laravel-scheduler.conf
```

```ini
[program:laravel-scheduler]
process_name=%(program_name)s
command=bash -c "while true; do php /var/www/your-project/artisan schedule:run; sleep 60; done"
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/your-project/storage/logs/scheduler.log
```

**Start Supervisor:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-scheduler
```

**Check status:**
```bash
sudo supervisorctl status laravel-scheduler
```

> Replace `/var/www/your-project` with your actual project path and `www-data` with your server user (e.g. `ubuntu`, `forge`, `deployer`).

### 4. Call directly from a Registration Controller

```php
use KsipTelnet\SSHClient;

// After saving the user in your register() method:
$ssh = new SSHClient();
$ssh->connect(
    config('services.freepbx.host'),
    config('services.freepbx.user'),
    config('services.freepbx.pass'),
    config('services.freepbx.port', 22)
);

$result = SSHClient::ksipRegisterUser(
    $user,
    $ssh,
    config('services.freepbx.db_user'),
    config('services.freepbx.db_pass')
);

// $result['status']    → 'assigned' | 'skipped' | 'error'
// $result['extension'] → '121013121696'
```

### Full Registration Controller Example

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use KsipTelnet\SSHClient;

class RegisterController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'first_name'  => 'required|string',
            'last_name'   => 'required|string',
            'middle_name' => 'nullable|string',
            'birth_date'  => 'required|date',
            'email'       => 'required|email|unique:users',
            'password'    => 'required|min:8|confirmed',
        ]);

        $user = User::create([
            'first_name'  => $request->first_name,
            'last_name'   => $request->last_name,
            'middle_name' => $request->middle_name,
            'birth_date'  => $request->birth_date,
            'email'       => $request->email,
            'password'    => bcrypt($request->password),
        ]);

        // Assign FreePBX extension immediately on registration
        $ssh = new SSHClient();
        $ssh->connect(
            config('services.freepbx.host'),
            config('services.freepbx.user'),
            config('services.freepbx.pass'),
            config('services.freepbx.port', 22)
        );

        $result = SSHClient::ksipRegisterUser(
            $user,
            $ssh,
            config('services.freepbx.db_user'),
            config('services.freepbx.db_pass')
        );

        return response()->json([
            'user'      => $user,
            'extension' => $result['extension'],
            'status'    => $result['status'],
        ], 201);
    }
}
```

### Generate Extension Number Only (no SSH)

```php
use KsipTelnet\SSHClient;

$ext = SSHClient::generateExtensionFromUser(
    'Luna',     // last_name
    'Juan',     // first_name
    'Mercado',  // middle_name
    '12/16/1996' // birth_date
);

// $ext → '121013121696'
```

### Return Values of `ksipRegisterUser`

| status | Meaning |
|--------|---------|
| `assigned` | Extension generated and saved to FreePBX |
| `skipped` | User already has an `extensionName` |
| `error` | Missing name or birthdate fields |

---

## Artisan Scaffold Generator

The package includes a `make:ksipgen` command that auto-generates call recording scaffold files in your Laravel project.

### What it generates

- `app/Http/Controllers/Api/CallRecordingController.php`
- `database/migrations/{timestamp}_create_call_recordings_table.php`
- Appends routes to `routes/api.php`

### Usage

```bash
php artisan make:ksipgen
php artisan migrate
```

### Generated Routes

```php
Route::prefix('recordings')->group(function () {
    Route::post('/upload', [CallRecordingController::class, 'upload']);
    Route::get('/', [CallRecordingController::class, 'index']);
    Route::get('/{id}', [CallRecordingController::class, 'show']);
    Route::get('/{id}/download', [CallRecordingController::class, 'download']);
    Route::delete('/{id}', [CallRecordingController::class, 'delete']);
});
```

### Available API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/recordings` | List all recordings |
| `POST` | `/api/recordings/upload` | Upload a recording (mp3/wav/ogg) |
| `GET` | `/api/recordings/{id}` | Get a single recording |
| `GET` | `/api/recordings/{id}/download` | Download a recording |
| `DELETE` | `/api/recordings/{id}` | Delete a recording |

### Upload Example

```bash
curl -X POST http://your-app.com/api/recordings/upload \
  -F "file=@/path/to/recording.wav" \
  -F "caller=1001" \
  -F "callee=1002" \
  -F "duration=60"
```

### Migration Schema

```php
Schema::create('call_recordings', function (Blueprint $table) {
    $table->id();
    $table->string('filename');
    $table->string('path');
    $table->string('caller')->nullable();
    $table->string('callee')->nullable();
    $table->integer('duration')->nullable();
    $table->timestamps();
});
```
