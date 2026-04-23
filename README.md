# php-ksip-telnet

A PHP library for managing FreePBX/Asterisk PJSIP extensions remotely via SSH.

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
