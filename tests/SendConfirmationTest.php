<?php

namespace YeastarSocket\Tests;

use PHPUnit\Framework\TestCase;
use YeastarSocket\SocketApi;

/**
 * Come si riconosce un SMS davvero partito.
 *
 * Il gateway acquisisce il comando e risponde subito `Response: Follows`,
 * identica sia quando l'invio riesce sia quando fallisce. La differenza sta
 * in un evento asincrono che arriva qualche secondo dopo: `UpdateSMSSend`,
 * con l'ID dell'invio e uno `Status`. Se l'evento non arriva, l'SMS non e'
 * partito.
 *
 * Le risposte usate qui sono state catturate da un TG400 reale il 2026-09-19.
 */
class SendConfirmationTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/Fixtures/' . $name . '.txt');
    }

    public function test_riconosce_lo_stato_di_un_invio_riuscito(): void
    {
        $raw = $this->fixture('send_success');

        $this->assertSame(1, SocketApi::parseSendConfirmation($raw, 'probe31789832504'));
    }

    public function test_ignora_l_evento_di_un_altro_invio_in_volo(): void
    {
        // Il socket e' condiviso: sulla stessa connessione possono arrivare
        // eventi relativi a invii diversi. Senza il confronto sull'ID, un
        // invio fallito si prenderebbe la conferma di uno riuscito.
        $raw = $this->fixture('send_other_id');

        $this->assertNull(SocketApi::parseSendConfirmation($raw, 'probe31789832504'));
    }

    public function test_senza_evento_non_c_e_nessuna_conferma(): void
    {
        // Il caso del difetto: invio dallo span 5, che non ha SIM. Il gateway
        // acquisisce il comando e non emette mai l'evento. La v1.1 leggeva
        // quattro righe, le trovava non vuote e dichiarava successo.
        $raw = $this->fixture('send_no_event');

        $this->assertNull(SocketApi::parseSendConfirmation($raw, 'probe51789832520'));
    }
}
