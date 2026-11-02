<?php

/**
 * Gateway Yeastar finto, per i test. Gira come processo separato, cosi' i test
 * esercitano la vera fsockopen invece di una connessione iniettata.
 *
 * Riproduce il dialogo osservato su un TG400 reale il 2026-09-19: riga di
 * benvenuto, blocco di autenticazione, e poi per ogni comando la risposta
 * corrispondente. Per gli invii l'ack e' identico che il messaggio parta o no:
 * la differenza e' l'evento asincrono `UpdateSMSSend` che arriva dopo.
 *
 * Uso: php fake_gateway.php <porta> <scenario>
 *   success   ack, poi l'evento con Status: 1
 *   no_event  solo l'ack: e' il caso dello span senza SIM
 *   status_0  ack, poi l'evento con Status: 0
 *   slow      ack, poi l'evento dopo un ritardo
 *   status    per i comandi di lettura (gsm show spans / gsm show span N)
 */

$port     = (int) ($argv[1] ?? 0);
$scenario = $argv[2] ?? 'success';

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "fake gateway: impossibile ascoltare su {$port}: [{$errno}] {$errstr}\n");
    exit(1);
}

// La porta effettiva finisce su stdout: il test la legge per sapere dove connettersi.
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$client = @stream_socket_accept($server, 10);
if (!$client) {
    exit(0);
}

/** Legge dal client fino alla riga vuota che chiude un blocco AMI. */
function readBlock($client): string
{
    $buffer = '';
    while (!feof($client)) {
        $line = fgets($client, 4096);
        if ($line === false) {
            break;
        }
        $buffer .= $line;
        if (substr($buffer, -4) === "\r\n\r\n") {
            break;
        }
    }
    return $buffer;
}

fwrite($client, "Asterisk Call Manager/1.1\r\n");

// Blocco di autenticazione, nell'ordine esatto che il client si aspetta.
readBlock($client);
fwrite($client, "Response: Success\r\nMessage: Authentication accepted\r\n\r\n");

while (!feof($client)) {
    $request = readBlock($client);

    if (trim($request) === '' || stripos($request, 'Action: Logoff') !== false) {
        break;
    }

    // Ogni blocco ricevuto, cosi' i test vedono i byte arrivati al gateway.
    fwrite(STDOUT, base64_encode($request) . "\n");
    fflush(STDOUT);

    if (!preg_match('/^command:\s*(.+?)\s*$/mi', $request, $cm)) {
        continue;
    }
    $command = $cm[1];

    // --- comandi di lettura: la risposta E' il blocco, non un semplice ack ---

    if ($command === 'gsm show spans') {
        fwrite($client, "Response: Follows\r\nPrivilege: SMSCommand\r\n"
            . "GSM span 2: Power on, Provisioned, Unregistered Network, Active,Standard\n"
            . "GSM span 3: Power on, Provisioned, Up, Active,Standard\n"
            . "GSM span 4: Power on, Provisioned, Up, Active,Standard\n"
            . "GSM span 5: Power on, Provisioned, Undetected SIM Card, Active,Standard\n"
            . "--END COMMAND--\r\n\r\n");
        continue;
    }

    if (preg_match('/^gsm show span (\d+)$/', $command, $sm)) {
        // Le righe di payload finiscono con \n secco e il terminatore resta
        // incollato all'ultima riga, senza newline che lo preceda: e' cosi'
        // che risponde il dispositivo, ed e' la trappola per un parser ingenuo.
        fwrite($client, "Response: Follows\r\nPrivilege: SMSCommand\r\n"
            . "D-channel: " . ((int) $sm[1] - 1) . "\n"
            . "Status: Power on, Provisioned, Up, Active,Standard\n"
            . "Type: CPE\n"
            . "Manufacturer: SIMCOM_Ltd\n"
            . "Model Name: SIMCOM_SIM800\n"
            . "Model IMEI: 868004023522777\n"
            . "Model CBAND:  EGSM_MODE,ALL_BAND\n"
            . "Revision: 1308B05SIM800M32_20140625_1100\n"
            . "Network Name: TELECOM ITALIA MOBILE\n"
            . "Network Status: Registered (Home network)\n"
            . "Signal Quality (0,31): 13\n"
            . "SIM IMSI: 222015104988377\n"
            . "SIM SMS Center Number: +393359609600\n"
            . "Send SMS Center Number: Undefined\n"
            . "Last event: D-Channel Up\n"
            . "State: READY\n"
            . "Last send AT: AT+CREG?\\r\\n--END COMMAND--\r\n\r\n");
        continue;
    }

    // --- invio ---

    fwrite($client, "Response: Follows\r\nPrivilege: SMSCommand\r\n--END COMMAND--\r\n\r\n");

    if (!preg_match('/^gsm send sms\s+\S+\s+\S+\s+".*"\s+(\S+)$/i', $command, $m)) {
        continue;
    }
    $smsId = $m[1];

    if ($scenario === 'no_event') {
        continue;
    }

    if ($scenario === 'slow') {
        sleep(3);
    }

    $status = $scenario === 'status_0' ? 0 : 1;

    fwrite($client, "Event: UpdateSMSSend\r\n"
        . "Privilege: all,smscommand\r\n"
        . "ID: {$smsId}\r\n"
        . "Smsc: +393359609600\r\n"
        . "Status: {$status}\r\n"
        . "--END SMS EVENT--\r\n\r\n");
}

fclose($client);
fclose($server);
