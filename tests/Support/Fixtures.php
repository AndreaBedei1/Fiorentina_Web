<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Valori condivisi dai test.
 *
 * Le password che i test usano stanno qui e in nessun altro posto, per due
 * ragioni. La prima e ovvia: se cambiano le regole di robustezza, si aggiorna
 * un valore solo invece di trenta.
 *
 * La seconda e meno ovvia. Una stringa ripetuta trenta volte nel repository,
 * lunga e con maiuscole, cifre e simboli, e indistinguibile da una credenziale
 * vera per qualunque scanner di segreti: genera segnalazioni che vanno
 * esaminate, e a forza di falsi allarmi si smette di guardarle. Questi valori
 * dicono da soli cosa sono, non aprono nulla e non esistono da nessuna parte
 * fuori dai test.
 */
final class Fixtures
{
    /** Password usata dagli account creati nei test. */
    public const PASSWORD = 'valore-finto-per-i-test-2026';

    /** Seconda password, per i test che ne cambiano una. */
    public const PASSWORD_NUOVA = 'secondo-valore-finto-2026';

    private function __construct()
    {
    }
}
