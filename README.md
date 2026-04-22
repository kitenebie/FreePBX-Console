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
$client->connect('your-server-ip', 'root', 'your-password');
```

### 2. Create a PJSIP Extension

```php
$result = $client->createExtension(
    '1001',          // extension number
    'secret123',     // extension password
    'freepbxuser',   // MySQL username
    'dbpassword'     // MySQL password
);

echo $result['sql_output'];
echo $result['reload_output'];
```

### 3. Run a Custom SSH Command

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
```

```php
$client->connect(
    getenv('SSH_HOST'),
    getenv('SSH_USER'),
    getenv('SSH_PASS')
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
            config('services.freepbx.pass')
        );
    }

    public function createExtension(string $ext, string $password): array
    {
        return $this->client->createExtension(
            $ext,
            $password,
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

        $result = $this->freepbx->createExtension(
            $request->extension,
            $request->password
        );

        return response()->json($result);
    }
}
```

### 7. Add route in `routes/api.php`

```php
use App\Http\Controllers\ExtensionController;

Route::post('/extensions', [ExtensionController::class, 'store']);
```

### 8. Test via API

```bash
curl -X POST http://your-app.com/api/extensions \
  -H "Content-Type: application/json" \
  -d '{"extension": "1001", "password": "secret123"}'
```
