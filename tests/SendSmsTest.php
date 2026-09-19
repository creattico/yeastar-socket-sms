<?php

namespace YeastarSocket\Tests;

use YeastarSocket\Exceptions\SmsSendException;
use YeastarSocket\SocketApi;

/**
 * Il comportamento di sendSms() contro un gateway che risponde.
 *
 * Fino alla 1.1 il metodo leggeva quattro righe e dichiarava successo se non
 * erano vuote. Ma l'ack del comando e' identico che l'invio riesca o no: la
 * differenza arriva dopo, con l'evento `UpdateSMSSend`. Un invio da una porta
 * senza SIM risultava quindi riuscito, il chiamante segnava il promemoria come
 * inviato e non lo ritentava piu'.
 */
class SendSmsTest extends FakeGatewayTestCase
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
            'confirm'      => true,
            'send_timeout' => 2,
        ], $overrides));
    }

    public function test_solleva_quando_l_evento_di_conferma_non_arriva(): void
    {
        $port = $this->startGateway('no_event');

        $this->expectException(SmsSendException::class);

        $this->api($port)->sendSms();
    }

    public function test_restituisce_true_quando_il_gateway_conferma(): void
    {
        // Senza questo test un'implementazione che solleva sempre passerebbe
        // comunque quello sopra: qui si verifica che la conferma vera venga
        // riconosciuta, ID compreso.
        $port = $this->startGateway('success');

        $this->assertTrue($this->api($port)->sendSms());
    }

    public function test_solleva_quando_il_gateway_riporta_uno_stato_diverso_da_uno(): void
    {
        $port = $this->startGateway('status_0');

        $this->expectException(SmsSendException::class);

        $this->api($port)->sendSms();
    }

    public function test_attende_l_evento_che_arriva_in_ritardo(): void
    {
        // Sul TG400 reale l'evento arriva tre o quattro secondi dopo il
        // comando: l'attesa deve reggerlo senza scambiarlo per un fallimento.
        $port = $this->startGateway('slow');

        $this->assertTrue($this->api($port, ['send_timeout' => 10])->sendSms());
    }

    public function test_senza_confirm_il_comportamento_resta_quello_della_1_1(): void
    {
        // La promessa ai progetti che gia' usano il pacchetto con ^1.0: senza
        // chiedere la conferma, sendSms() non acquisisce il modo di fallire che
        // i loro chiamanti non stanno catturando.
        $port = $this->startGateway('no_event');

        $this->assertTrue($this->api($port, ['confirm' => false])->sendSms());
    }
}
