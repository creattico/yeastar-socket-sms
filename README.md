# Yeastar Socket SMS

PHP library to send SMS and read gateway status via the socket API (AMI) of Yeastar TGxxxx gateways.

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
    'gateway_port' => 1,                  // GSM span — see the note below
    'account'      => 'username',
    'password'     => 'password',
    'to'           => '0039123456789',    // recipient number with country code
    'message'      => 'Your message here',
    'timeout'      => 5,                  // connection timeout in seconds (default: 5)
    'confirm'      => true,               // wait for delivery confirmation (default: false)
    'send_timeout' => 20,                 // seconds to wait for it (default: 20)
    'debug'        => true,               // optional: enable debug logging
]);

try {
    $sms->sendSms();
} catch (SocketConnectionException $e) {
    // connection or authentication failed
    echo $e->getMessage();
} catch (SmsSendException $e) {
    // the message did not go out
    echo $e->getMessage();
} finally {
    $sms->closeSocket();
}
```

## Delivery confirmation (`confirm`)

**Without `confirm`, a successful return does not mean the message was sent.**

The gateway acknowledges the send command immediately with `Response: Follows`, and that
acknowledgement is byte-for-byte identical whether the message goes out or not. A send from a
port with no SIM, or with a SIM that lost network registration, is acknowledged exactly like a
successful one. What actually tells them apart is an asynchronous `UpdateSMSSend` event that
arrives three to four seconds later, carrying the send ID and a status.

With `'confirm' => true`, `sendSms()` waits for that event and matches it against the ID of
this specific send — the connection carries events for other sends too. It returns `true` only
on `Status: 1`, and throws `SmsSendException` on any other status or when no event arrives
within `send_timeout`.

The trade-off is that `sendSms()` blocks for a few seconds, so it belongs in a queued job
rather than inside a web request.

`confirm` is **off by default** for backward compatibility: before v1.2 `sendSms()` never threw
on a swallowed message, and existing callers are not catching that case yet. New code should
turn it on.

## Reading gateway status

The same connection can run read commands, which is how you find out which ports are usable:

```php
$api = new SocketApi(['host' => '...', 'account' => '...', 'password' => '...']);

$api->openSocket();
$spans  = $api->command('gsm show spans');
$detail = $api->command('gsm show span 3');
$api->closeSocket();
```

`command()` returns the raw response block and does not interpret it: what counts as a healthy
port is a question for your application, not for the protocol.

Known quirks of the TG400 firmware, worth designing around:

- `gsm show span all` is **broken** — it returns a single span with its fields collapsed into
  one another. Ask for one span at a time.
- Payload lines end with a bare `\n` while AMI headers end with `\r\n`, and in
  `gsm show span <n>` the `--END COMMAND--` terminator is glued to the last payload line with
  no newline before it.
- An empty slot has two different signatures: `Undetected SIM Card` if it was always empty, or
  `Unregistered Network` with `State: SIM READY REQ` if the SIM was pulled while the module
  was running.
- The AMI surface is nine actions, and only `SMSCommand` does anything useful. It accepts
  `gsm show spans`, `gsm show span <n>|all`, `gsm send sms` and `gsm send ussd`. There is no
  hardware management: no power cycling, no reset, no forced re-registration.

## Port numbering

`gateway_port` is the **span**, which is the physical port **plus one**: physical port 2 is
span 3. Yeastar's HTTP SMS API numbers the same ports differently — there the value is the
physical port. Reusing the same number across the two channels sends from the wrong SIM, and it
fails silently: the message is delivered, just from another number.

## Debug

Set `debug` to `true` to collect log messages:

```php
print_r($sms->log);
```

## Exceptions

| Exception | When |
|---|---|
| `SocketConnectionException` | Cannot connect to the gateway, authentication fails, or a command is issued on a closed session |
| `SmsSendException` | Recipient missing, or — with `confirm` on — the gateway never confirmed the message left the device |

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite talks to a scripted fake gateway started as a separate process, replaying responses
captured from a real TG400. No test touches a device.

## License

MIT
