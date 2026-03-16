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
     * @throws SmsSendException if the SMS cannot be sent or the recipient is missing
     * @return bool True if the message was sent successfully
     */
    public function sendSms(): bool
    {
        $this->openSocket();

        if (!$this->fp) {
            throw new SocketConnectionException("Socket is not open");
        }

        if (empty($this->to)) {
            throw new SmsSendException("Recipient phone number is required");
        }

        $this->smsId = (string) (time() . rand());

        $written = fwrite($this->fp, "Action: smscommand\r\ncommand: gsm send sms " . $this->gateway_port . " " . $this->to . " \"" . $this->message . "\" " . $this->smsId . "\r\n\r\n");

        if (!$written) {
            $this->log("Error writing SMS command to socket");
            throw new SmsSendException("Failed to write SMS command to socket");
        }

        $this->log("SMS command sent (id: {$this->smsId})");

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

        $this->log("SMS sent successfully (id: {$this->smsId})");

        return true;
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
