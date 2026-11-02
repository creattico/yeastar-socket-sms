<?php

/**
 * YeastarSocket - PHP sms class for Yeastar TGxxxx devices.
 * PHP Version 7.4+.
 *
 * @see https://github.com/creattico/yeastar-socket-sms A composer library to send sms via socket through Yeastar Gateway
 *
 * @author    Domenico Carbone (creattico) <dev@creattica.it>
 * @copyright 2024 Domenico Carbone
 * @license   http://opensource.org/licenses/MIT MIT License
 */

namespace YeastarSocket;

use YeastarSocket\Exceptions\SmsSendException;
use YeastarSocket\Exceptions\SocketConnectionException;

class SocketApi
{
    /**
     * Log array
     *
     * @var array<string>
     */
    public array $log = [];

    /**
     * Host for socket connection
     *
     * @var string
     */
    protected string $host = 'localhost';

    /**
     * Port for socket connection
     *
     * @var int
     */
    protected int $port = 5038;

    /**
     * Server IP address (resolved from host)
     *
     * @var string
     */
    protected string $ip_address = '127.0.0.1';

    /**
     * Account username
     *
     * @var string
     */
    protected string $account = '';

    /**
     * Account password
     *
     * @var string
     */
    protected string $password = '';

    /**
     * Recipient phone number
     *
     * @var string
     */
    protected string $to = '';

    /**
     * SMS message content
     *
     * @var string
     */
    protected string $message = '';

    /**
     * Gateway port (trunk port + 1). Default: 1
     *
     * @var int
     */
    protected int $gateway_port = 1;

    /**
     * Socket connection timeout in seconds. Default: 5
     *
     * @var int
     */
    protected int $timeout = 5;

    /**
     * Enable debug logging
     *
     * @var bool
     */
    protected bool $debug = false;

    /**
     * Wait for the gateway to confirm the message actually went out.
     *
     * Off by default: until v1.1 `sendSms()` never threw, and turning this on
     * gives it a failure mode existing callers are not catching yet.
     *
     * @var bool
     */
    protected bool $confirm = false;

    /**
     * Seconds to wait for the `UpdateSMSSend` event when $confirm is on.
     *
     * On a real TG400 the event lands three to four seconds after the command.
     *
     * @var int
     */
    protected int $send_timeout = 20;

    /**
     * Seconds to wait for a read command to return its block.
     *
     * @var int
     */
    protected int $command_timeout = 10;

    /**
     * Socket file pointer
     *
     * @var resource|false
     */
    protected $fp = false;

    /**
     * Unique SMS identifier
     *
     * @var string
     */
    protected string $smsId = '';

    /**
     * Class constructor
     *
     * @param array<string, mixed> $data Initial property values
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Open socket and authenticate with the Yeastar gateway.
     *
     * @throws SocketConnectionException if the connection or authentication fails
     */
    public function openSocket(): void
    {
        $this->ip_address = gethostbyname($this->host);

        try {
            $this->fp = fsockopen($this->ip_address, $this->port, $errno, $errstr, $this->timeout);
        } catch (\ErrorException $e) {
            $this->log("Error opening socket with {$this->host}: {$e->getMessage()}");
            throw new SocketConnectionException("Unable to connect to {$this->host}:{$this->port} — {$e->getMessage()}", 0, $e);
        }

        if (!$this->fp) {
            $this->log("Error opening socket with {$this->host}: [{$errno}] {$errstr}");
            throw new SocketConnectionException("Unable to connect to {$this->host}:{$this->port} — [{$errno}] {$errstr}");
        }

        $this->log("Socket with {$this->host} opened");

        // Never add `Events: off` to this login. It is tempting — it quietens the
        // socket — but it also suppresses `UpdateSMSSend`, the one event that
        // tells a delivered message from a swallowed one. With it on, $confirm
        // would silently degrade back to the pre-1.2 behaviour.
        $written = fwrite($this->fp, "Action: Login\r\nUsername: " . urlencode($this->account) . "\r\nSecret: " . urlencode($this->password) . "\r\n\r\n");

        if (!$written) {
            $this->log("Error writing login action to socket");
            throw new SocketConnectionException("Failed to send login action to {$this->host}");
        }

        $this->log("Login action sent to {$this->host}");

        $expectations = [
            'Asterisk Call Manager/1.1',
            'Response: Success',
            'Message: Authentication accepted',
        ];

        foreach ($expectations as $index => $expected) {
            $row = fgets($this->fp);
            if ($row === false || trim($row) !== $expected) {
                $this->fp = false;
                $actual = $row === false ? '(no response)' : trim($row);
                $this->log("Authentication failed at row " . ($index + 1) . ": expected '{$expected}', got '{$actual}'");
                throw new SocketConnectionException("Authentication failed: expected '{$expected}', got '{$actual}'");
            }
            $this->log("Auth row " . ($index + 1) . " OK: {$expected}");
        }

        // fourth row must be blank (end of response block)
        $blank = fgets($this->fp);
        if ($blank === false) {
            $this->fp = false;
            $this->log("Authentication failed: missing blank line after auth response");
            throw new SocketConnectionException("Authentication failed: missing blank line after auth response");
        }

        $this->log("Authentication successful");
    }

    /**
     * Send SMS message via socket.
     *
     * @throws SocketConnectionException if the connection fails
     * @throws SmsSendException if the SMS cannot be sent or the recipient is missing or invalid
     * @return bool True if the message was sent successfully
     */
    public function sendSms(): bool
    {
        // Before the connection: a number that cannot be sent has no business
        // opening a session on the device.
        $to = self::commandLineNumber($this->to);

        $this->openSocket();

        if (!$this->fp) {
            throw new SocketConnectionException("Socket is not open");
        }

        $this->smsId = (string) (time() . rand());

        $written = fwrite($this->fp, "Action: smscommand\r\ncommand: gsm send sms " . $this->gateway_port . " " . $to . " \"" . self::commandLineText($this->message) . "\" " . $this->smsId . "\r\n\r\n");

        if (!$written) {
            $this->log("Error writing SMS command to socket");
            throw new SmsSendException("Failed to write SMS command to socket");
        }

        $this->log("SMS command sent (id: {$this->smsId})");

        if (!$this->confirm) {
            // Pre-1.2 behaviour, kept for callers that are not catching
            // SmsSendException yet. It only proves the gateway took the
            // command, never that the message left the device.
            $response = '';
            for ($i = 0; $i < 4; $i++) {
                $line = fgets($this->fp);
                if ($line !== false) {
                    $response .= $line;
                }
            }

            if (empty(trim($response))) {
                $this->log("Empty response after SMS command");
                throw new SmsSendException("Empty response from gateway after SMS command");
            }

            $this->log("SMS command acknowledged, delivery not verified (id: {$this->smsId})");

            return true;
        }

        $status = $this->awaitSendConfirmation();

        if ($status === null) {
            $this->log("No UpdateSMSSend event within {$this->send_timeout}s (id: {$this->smsId})");
            throw new SmsSendException(
                "Gateway never confirmed the SMS (id: {$this->smsId}): no UpdateSMSSend event "
                . "within {$this->send_timeout} seconds. The message did not go out."
            );
        }

        if ($status !== 1) {
            $this->log("UpdateSMSSend reported status {$status} (id: {$this->smsId})");
            throw new SmsSendException("Gateway reported status {$status} for SMS {$this->smsId}");
        }

        $this->log("SMS confirmed sent (id: {$this->smsId})");

        return true;
    }

    /**
     * The recipient as the command line needs it: digits, with a leading + if any.
     *
     * Spaces, dashes, dots, slashes and brackets are how people write numbers, so
     * they go. Anything else is not a number, and in this position it would move
     * the arguments of the command or end the command altogether.
     *
     * @throws SmsSendException if nothing usable is left
     */
    protected static function commandLineNumber(string $to): string
    {
        $number = preg_replace('#[ .\-/()]#', '', $to);

        if ($number === '') {
            throw new SmsSendException("Recipient phone number is required");
        }

        // \z and not $: $ would let a trailing newline through.
        if (!preg_match('/^\+?\d+\z/', $number)) {
            throw new SmsSendException("Invalid recipient phone number: only digits and a leading + are allowed");
        }

        return $number;
    }

    /**
     * The message as it can sit between the quotes of the command line.
     *
     * A line break ends the command and starts another one on the device, which
     * also accepts `gsm send ussd`. A double quote ends the text, and a backslash
     * escapes what follows it in Asterisk's parser, closing quote included. These
     * are replaced, not refused: the recipient still reads the same thing.
     */
    protected static function commandLineText(string $message): string
    {
        return str_replace(['"', '\\'], ["'", '/'], preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));
    }

    /**
     * Read the socket until the gateway confirms this send, or the wait runs out.
     *
     * @return int|null The reported status, or null if no matching event arrived
     */
    protected function awaitSendConfirmation(): ?int
    {
        $raw      = '';
        $deadline = microtime(true) + $this->send_timeout;

        stream_set_blocking($this->fp, false);

        while (microtime(true) < $deadline) {
            $chunk = fread($this->fp, 8192);

            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;

                $status = self::parseSendConfirmation($raw, $this->smsId);
                if ($status !== null) {
                    stream_set_blocking($this->fp, true);
                    return $status;
                }

                continue;
            }

            if (feof($this->fp)) {
                break;
            }

            usleep(100000);
        }

        stream_set_blocking($this->fp, true);

        return null;
    }

    /**
     * Run a read command on an already open session and return its raw block.
     *
     * The gateway answers with `Response: Follows`, the payload, and the
     * `--END COMMAND--` terminator followed by a blank line. The whole block is
     * consumed — trailing blank line included — so that a second command on the
     * same connection does not pick up the tail of the first.
     *
     * Nothing here interprets the payload: what counts as a healthy port is a
     * question for the caller, not for the protocol.
     *
     * @throws SocketConnectionException if the session is not open or the write fails
     * @throws \InvalidArgumentException if the command contains control characters,
     *         which would end it and start another one on the device
     */
    public function command(string $command): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $command)) {
            throw new \InvalidArgumentException("A command must fit on a single line");
        }

        if (!$this->fp) {
            throw new SocketConnectionException("Socket is not open — call openSocket() first");
        }

        $written = fwrite($this->fp, "Action: smscommand\r\ncommand: {$command}\r\n\r\n");

        if (!$written) {
            $this->log("Error writing command to socket: {$command}");
            throw new SocketConnectionException("Failed to write command to socket");
        }

        $this->log("Command sent: {$command}");

        $raw      = '';
        $deadline = microtime(true) + $this->command_timeout;

        stream_set_blocking($this->fp, false);

        while (microtime(true) < $deadline) {
            $chunk = fread($this->fp, 8192);

            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;

                if (preg_match('/--END COMMAND--\r?\n\r?\n/', $raw)) {
                    break;
                }

                continue;
            }

            if (feof($this->fp)) {
                break;
            }

            usleep(50000);
        }

        stream_set_blocking($this->fp, true);

        $this->log("Command response read (" . strlen($raw) . " bytes)");

        return $raw;
    }

    /**
     * Extract the delivery status of a sent SMS from a raw gateway response.
     *
     * The gateway acknowledges the command immediately with `Response: Follows`,
     * identically whether the message goes out or not. What tells the two apart
     * is an asynchronous `UpdateSMSSend` event emitted a few seconds later.
     *
     * @param  string $raw   Raw text read from the socket after the send command
     * @param  string $smsId The identifier passed to `gsm send sms`
     * @return int|null      The reported status, or null when no matching event is present
     */
    public static function parseSendConfirmation(string $raw, string $smsId): ?int
    {
        $insideEvent = false;
        $id          = null;
        $status      = null;

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);

            if ($line === 'Event: UpdateSMSSend') {
                $insideEvent = true;
                $id          = null;
                $status      = null;
                continue;
            }

            if (!$insideEvent) {
                continue;
            }

            if ($line === '--END SMS EVENT--') {
                if ($id === $smsId && $status !== null) {
                    return $status;
                }
                $insideEvent = false;
                continue;
            }

            if (strncmp($line, 'ID:', 3) === 0) {
                $id = trim(substr($line, 3));
            } elseif (strncmp($line, 'Status:', 7) === 0) {
                $status = (int) trim(substr($line, 7));
            }
        }

        return null;
    }

    /**
     * Close the socket connection.
     */
    public function closeSocket(): void
    {
        if ($this->fp) {
            fclose($this->fp);
            $this->fp = false;
        }

        $this->log("Socket connection closed");
    }

    /**
     * Write a message to the log array (only when debug is enabled).
     */
    protected function log(string $message): void
    {
        if ($this->debug) {
            $this->log[] = $message;
        }
    }
}
