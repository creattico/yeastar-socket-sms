# Yeastar Socket SMS

PHP library to send SMS via socket API through Yeastar TGxxxx gateways.

## Requirements

- PHP >= 7.4

## Install

```bash
composer require creattico/yeastar-socket-sms
```

## Usage

```php
use YeastarSocket\SocketApi;
use YeastarSocket\Exceptions\SocketConnectionException;
use YeastarSocket\Exceptions\SmsSendException;

$sms = new SocketApi([
    'host'         => 'domain.ext',       // Yeastar gateway host or IP
    'port'         => 5038,               // AMI port (default: 5038)
    'gateway_port' => 1,                  // trunk port (default: 1)
    'account'      => 'username',
    'password'     => 'password',
    'to'           => '0039123456789',    // recipient number with country code
    'message'      => 'Your message here',
    'timeout'      => 5,                  // socket timeout in seconds (default: 5)
    'debug'        => true,               // optional: enable debug logging
]);

try {
    $sms->sendSms();
} catch (SocketConnectionException $e) {
    // connection or authentication failed
    echo $e->getMessage();
} catch (SmsSendException $e) {
    // SMS command failed
    echo $e->getMessage();
} finally {
    $sms->closeSocket();
}
```

## Debug

Set `debug` to `true` to collect log messages:

```php
print_r($sms->log);
```

## Exceptions

| Exception | When |
|---|---|
| `SocketConnectionException` | Cannot connect to the gateway or authentication fails |
| `SmsSendException` | SMS command fails or recipient is missing |

## License

MIT
