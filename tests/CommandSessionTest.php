<?php

namespace YeastarSocket\Tests;

use YeastarSocket\SocketApi;

/**
 * La sessione riusabile per i comandi di lettura.
 *
 * Fino alla 1.1 il pacchetto sapeva fare una cosa sola: aprire il socket,
 * inviare un SMS e chiudere. Interrogare lo stato del gateway richiede invece
 * piu' comandi sulla stessa connessione, perche' `gsm show span all` e' rotto
 * su questo firmware e il dettaglio va chiesto una porta alla volta.
 *
 * Il pacchetto restituisce il testo grezzo e non lo interpreta: cosa sia una
 * porta "sana" e' una domanda di chi lo usa, non del protocollo.
 */
class CommandSessionTest extends FakeGatewayTestCase
{
    private function api(int $port): SocketApi
    {
        return new SocketApi([
            'host'     => '127.0.0.1',
            'port'     => $port,
            'account'  => 'test',
            'password' => 'test',
        ]);
    }

    public function test_esegue_un_comando_di_lettura_e_ne_restituisce_la_risposta(): void
    {
        $port = $this->startGateway('status');
        $api  = $this->api($port);

        $api->openSocket();
        $raw = $api->command('gsm show spans');
        $api->closeSocket();

        $this->assertStringContainsString('GSM span 3: Power on, Provisioned, Up', $raw);
        $this->assertStringContainsString('--END COMMAND--', $raw);
    }

    public function test_piu_comandi_sulla_stessa_connessione_non_si_mescolano(): void
    {
        $port = $this->startGateway('status');
        $api  = $this->api($port);

        $api->openSocket();
        $spans  = $api->command('gsm show spans');
        $detail = $api->command('gsm show span 3');
        $api->closeSocket();

        // La risposta del secondo comando non deve contenere code del primo.
        $this->assertStringContainsString('GSM span 5:', $spans);
        $this->assertStringNotContainsString('GSM span 5:', $detail);
        $this->assertStringContainsString('Network Name: TELECOM ITALIA MOBILE', $detail);
    }
}
