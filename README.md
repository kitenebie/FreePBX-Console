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
