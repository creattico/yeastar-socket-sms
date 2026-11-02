<?php

namespace YeastarSocket\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use YeastarSocket\Exceptions\SmsSendException;
use YeastarSocket\SocketApi;

/**
 * Numero e testo finiscono in una riga del protocollo AMI:
 *
 *     command: gsm send sms <porta> <numero> "<testo>" <id>
 *
 * Fino alla 1.2.0 venivano concatenati cosi' come arrivavano: un a capo chiudeva
 * il comando e ne apriva un altro sul gateway, che accetta anche `gsm send ussd`;
 * una virgoletta chiudeva il testo; uno spazio nel numero spostava gli argomenti.
 * Questi test guardano i byte ricevuti dal gateway finto, non le stringhe interne.
 */
class CommandLineSafetyTest extends FakeGatewayTestCase
{
    private function api(int $port, array $overrides = []): SocketApi
    {
        return new SocketApi(array_merge([
            'host'         => '127.0.0.1',
            'port'         => $port,
            'account'      => 'test',
            'password'     => 'test',
            'gateway_port' => 3,
            'to'           => '3330000000',
            'message'      => 'prova',
        ], $overrides));
    }

    /** @return array<int, string> i blocchi arrivati al gateway per questo invio */
    private function send(array $overrides): array
    {
        $api = $this->api($this->startGateway('success'), $overrides);

        $api->sendSms();
        $api->closeSocket();

        return $this->requestsReceived();
    }

    private function assertSentCommand(string $to, string $text, array $requests): void
    {
        $this->assertCount(1, $requests, 'Il gateway deve ricevere un solo comando');
        $this->assertMatchesRegularExpression(
            '/\AAction: smscommand\r\ncommand: gsm send sms 3 ' . preg_quote($to, '/') . ' "' . preg_quote($text, '/') . '" \d+\r\n\r\n\z/',
            $requests[0]
        );
    }

    public function test_un_a_capo_nel_testo_non_apre_un_altro_comando(): void
    {
        $requests = $this->send([
            'message' => "ciao\r\n\r\nAction: smscommand\r\ncommand: gsm send ussd 3 *123#",
        ]);

        $this->assertSentCommand('3330000000', 'ciao Action: smscommand command: gsm send ussd 3 *123#', $requests);
    }

    public function test_un_a_capo_secco_e_gli_altri_caratteri_di_controllo_diventano_uno_spazio(): void
    {
        // Asterisk chiude una riga AMI anche su un \n senza \r.
        $requests = $this->send(['message' => "uno\ndue\rtre\x00quattro\tcinque"]);

        $this->assertSentCommand('3330000000', 'uno due tre quattro cinque', $requests);
    }

    public function test_una_virgoletta_non_chiude_il_testo(): void
    {
        $requests = $this->send(['message' => 'lo "studio" chiude']);

        $this->assertSentCommand('3330000000', "lo 'studio' chiude", $requests);
    }

    public function test_una_barra_rovesciata_non_annulla_la_virgoletta_di_chiusura(): void
    {
        $requests = $this->send(['message' => 'percorso C:\\']);

        $this->assertSentCommand('3330000000', 'percorso C:/', $requests);
    }

    public function test_accenti_e_punteggiatura_arrivano_intatti(): void
    {
        $requests = $this->send(['message' => "Conferma: lunedì alle 15:30, dott.ssa D'Angelo. https://x.it/a?b=1"]);

        $this->assertSentCommand('3330000000', "Conferma: lunedì alle 15:30, dott.ssa D'Angelo. https://x.it/a?b=1", $requests);
    }

    public function test_il_gateway_riconosce_ancora_l_invio_dopo_la_pulizia(): void
    {
        // Con la conferma attiva l'ID deve restare l'ultimo argomento: e' da li'
        // che l'evento UpdateSMSSend viene ricondotto a questo invio.
        $api = $this->api($this->startGateway('success'), [
            'message'      => "riga \"uno\"\r\nriga due\\",
            'confirm'      => true,
            'send_timeout' => 2,
        ]);

        $this->assertTrue($api->sendSms());
    }

    #[DataProvider('formattedNumbers')]
    public function test_il_numero_scritto_come_lo_scrivono_le_persone_viene_ripulito(string $input, string $sent): void
    {
        $this->assertSentCommand($sent, 'prova', $this->send(['to' => $input]));
    }

    public static function formattedNumbers(): array
    {
        return [
            'spazi'       => ['333 000 0000', '3330000000'],
            'prefisso +'  => ['+39 333 0000000', '+393330000000'],
            'prefisso 00' => ['0039 333 0000000', '00393330000000'],
            'trattini'    => ['333-000-0000', '3330000000'],
            'punti'       => ['333.000.0000', '3330000000'],
            'barra'       => ['333/0000000', '3330000000'],
            'parentesi'   => ['(+39) 333 0000000', '+393330000000'],
        ];
    }

    /**
     * Sulla porta 1 non ascolta nessuno: se il metodo provasse a collegarsi
     * uscirebbe con SocketConnectionException, non con SmsSendException.
     */
    #[DataProvider('invalidNumbers')]
    public function test_un_numero_non_valido_si_ferma_prima_di_aprire_la_connessione(string $to): void
    {
        $this->expectException(SmsSendException::class);

        $this->api(1, ['to' => $to])->sendSms();
    }

    public static function invalidNumbers(): array
    {
        return [
            'vuoto'             => [''],
            'solo spazi'        => ['   '],
            'lettere'           => ['chiamami'],
            'solo prefisso'     => ['+'],
            'piu in mezzo'      => ['333+0000000'],
            'argomento in piu'  => ['3330000000 "x" 1'],
            'iniezione'         => ["3330000000\r\n\r\nAction: Logoff"],
            'a capo finale'     => ["3330000000\n"],
        ];
    }

    #[DataProvider('unsafeCommands')]
    public function test_command_rifiuta_un_comando_che_ne_aprirebbe_un_altro(string $command): void
    {
        $api = $this->api($this->startGateway('status'));
        $api->openSocket();

        try {
            $api->command($command);
            $this->fail('Il comando doveva essere rifiutato');
        } catch (InvalidArgumentException $e) {
            // La sessione resta buona: non e' partito niente.
            $this->assertStringContainsString('GSM span 3:', $api->command('gsm show spans'));
        }

        $api->closeSocket();

        $this->assertCount(1, $this->requestsReceived());
    }

    public static function unsafeCommands(): array
    {
        return [
            'crlf'     => ["gsm show spans\r\n\r\nAction: Logoff"],
            'a capo'   => ["gsm show spans\ncommand: gsm send ussd 3 *123#"],
            'ritorno'  => ["gsm show spans\r"],
            'nul'      => ["gsm show spans\0"],
        ];
    }
}
