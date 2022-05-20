<?php

/**
 * YeastarSocket - PHP sms class for Yeastar TGxxxx devices.
 * PHP Version 7.
 *
 * @see https://github.com/creattico/yeastar-socket-sms A composer library to send sms via socket through Yeastar Gateway
 *
 * @author    Domenico Carbone (creattico) <dev@creattica.it>
 * @copyright 2022 Domenico Carbone
 * @license   http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 * @note      This program is distributed in the hope that it will be useful - WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.
 */

namespace YeastarSocket;

class SocketApi
{
	/**
	 * Log array
	 * 
	 * @return array
	 */
	public $log = [];

	/**
	 * Http protocol for calls
	 * @var string
	 */
	protected $protocol = 'https';

	/**
	 * Host for calls
	 * @var string
	 */
	protected $host = 'localhost';

	/**
	 * Http protocol for calls
	 * @var string
	 */
	protected $port = 5038;

	/**
	 * Server IP address
	 * @var string
	 */
	protected $ip_address = '127.0.0.1';

	/**
	 * Account
	 * @var string
	 */
	protected $account = '';


	/**
	 * Password
	 * @var string
	 */
	protected $password = '';


	/**
	 * To: the recipient phone number
	 * @var string
	 */
	protected $to = '';


	/**
	 * Message: the message to be sent
	 * @var string
	 */
	protected $message = '';


	/**
	 * Gateway port: the yeastar port to use. Default 1
	 * @var string
	 */
	protected $gateway_port = 1;


	/**
	 * Debug
	 * @var bool
	 */
	protected $debug = false;


	/**
	 * Class constructor
	 * 
	 * @param array $data Initial property value
	 * 
	 * @return void
	 */
	public function __construct($data = [])
	{
		foreach ($data as $key => $value) {
			$this->$key = $value;
		}
	}

	/**
	 * Open socket
	 * 
	 * @return mixed
	 */
	public function openSocket() {
		$this->ip_address = gethostbyname($this->host);
		$this->fp = fsockopen($this->ip_address, $this->port, $errno, $errstr, 5);

		if (!$this->fp) {
			if ($this->debug) {
				$this->log[] = "Error opening socket with " . $this->host;
			}
		} else {
			if ($this->debug) {
				$this->log[] = "Socket with " . $this->host . " opened";
			}
			// send the auth
			$ok = fwrite($this->fp, "Action: Login\r\nUsername: ".urlencode($this->account)."\r\nSecret: ".urlencode($this->password)."\r\n\r\n"); 

			if(!$ok) {
				if ($this->debug) {
					$this->log[] = "Errore socket NeoGateManager";
				}
				return false;
			} else {
				if ($this->debug) {
					$this->log[] = "Connection established with " . $this->host;
				}
			}

			// check the first row
			$result = fgets($this->fp);
			if(!$result or trim($result) != 'Asterisk Call Manager/1.1') {
				// auth failed
				$this->fp = false;
				if ($this->debug) {
					$this->log[] = "Authentication failed: this response row (1) must contains 'Asterisk Call Manager/1.1'";
				}
				return false;
			} else {
				if ($this->debug) {
					$this->log[] = "Authentication accepted: this response row (2) contains 'Asterisk Call Manager/1.1'";
				}
			}

			// check the second row
			$result = fgets($this->fp);
			if(!$result or trim($result) != 'Response: Success') {
				// auth failed
				$this->fp = false;
				if ($this->debug) {
					$this->log[] = "Authentication failed: this response row (2) must contains 'Response: Success'";
				}
				return false;
			} else {
				if ($this->debug) {
					$this->log[] = "Authentication accepted: this response row (2) contains 'Response: Success'";
				}
			}

			// check the third row
			$result = fgets($this->fp);
			if(!$result or trim($result) != 'Message: Authentication accepted') {
				// auth failed
				$this->fp = false;
				if ($this->debug) {
					$this->log[] = "Authentication failed: this response row (3) must contains 'Message: Authentication accepted'";
				}
				return false;
			} else {
				if ($this->debug) {
					$this->log[] = "Authentication accepted: this response row (3) contains 'Message: Authentication accepted'";
				}
			}

			// check the fourth row (void)
			$result = fgets($this->fp);
			if( $result === false ) {
				// auth failed
				$this->fp = false;
				if ($this->debug) {
					$this->log[] = "Authentication failed: this response row (4) could be void";
				}
				return false;
			} else {
				$this->log[] = "Authentication success: this response row (4) is void";
			}
		}
	}

	/**
	 * Send sms message via socket
	 * 
	 * @return bool True if message has been sent, false otherwise
	 */
	public function sendSms() {
		$this->openSocket();
		if ($this->fp) {
			if(isset($this->to) && $this->to) {
				
				$this->smsId = time().rand();
	
				$message = urlencode($this->message);
				
				// send the smm
				$ok = fwrite($this->fp, "Action: smscommand\r\ncommand: gsm send sms " . $this->gateway_port . " ". $this->to . " \"" . $message . "\" " . $this->smsId . "\r\n\r\n");

				if (!$ok) {
					if ($this->debug) {
						$this->log[] = "Something wrong with the socket";
					}
					return false;
				} else {
					if ($this->debug) {
						$this->log[] = "Send sms command sent successfully";
					}
				}

				// read lines
				$result = fgets($this->fp);
				$result .= fgets($this->fp);
				$result .= fgets($this->fp);
				$result .= fgets($this->fp);
				
				// if success
				if ($result) {
					// sms succeeded update the command status to sent 
					if ($this->debug) {
						$this->log[] = "Sms message sent successfully";
					}
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Close Socket connection
	 * 
	 * Perform a socket disconnection
	 * 
	 * @return void
	 */
	public function closeSocket() {
		if ($this->fp) {
			fclose($this->fp);
			$this->fp = false;
		}
		if ($this->debug) {
			$this->log[] = "Socket connection closed successfully";
		}
	}
}