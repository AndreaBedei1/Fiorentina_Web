<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Regole di robustezza delle password, in un unico posto.
 *
 * Sono usate sia dal validatore dei form sia dallo script di creazione del
 * primo amministratore: senza una fonte unica, prima o poi le due strade
 * finirebbero per divergere, e l'unica cosa peggiore di una regola debole e
 * una regola che vale solo in meta dei casi.
 *
 * La regola e: almeno otto caratteri, e dentro ci devono essere una minuscola,
 * una maiuscola e una cifra. Sono tre requisiti espliciti invece di un
 * conteggio - "tre categorie su quattro" e vero ma non dice a chi sta
 * scrivendo cosa gli manca, mentre "manca una maiuscola" si corregge subito.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 200;

    /**
     * Verifica una password.
     *
     * @return string|null Messaggio di errore, oppure null se la password va bene.
     */
    public static function check(string $password, ?int $minLength = null): ?string
    {
        $minLength ??= self::MIN_LENGTH;

        if ($password === '') {
            return 'La password è obbligatoria.';
        }

        if (mb_strlen($password) < $minLength) {
            return sprintf('La password deve contenere almeno %d caratteri.', $minLength);
        }

        if (mb_strlen($password) > self::MAX_LENGTH) {
            return sprintf('La password non può superare %d caratteri.', self::MAX_LENGTH);
        }

        $mancanti = self::mancanti($password);

        if ($mancanti !== []) {
            return sprintf('Alla password manca %s.', self::elenco($mancanti));
        }

        return null;
    }

    public static function isValid(string $password, ?int $minLength = null): bool
    {
        return self::check($password, $minLength) === null;
    }

    /**
     * Che cosa manca alla password, con le parole che si userebbero a voce.
     *
     * @return list<string>
     */
    public static function mancanti(string $password): array
    {
        $mancanti = [];

        if (preg_match('/\p{Ll}/u', $password) !== 1) {
            $mancanti[] = 'una lettera minuscola';
        }

        if (preg_match('/\p{Lu}/u', $password) !== 1) {
            $mancanti[] = 'una lettera maiuscola';
        }

        if (preg_match('/\d/', $password) !== 1) {
            $mancanti[] = 'un numero';
        }

        return $mancanti;
    }

    /** Descrizione da mostrare accanto ai campi password. */
    public static function description(?int $minLength = null): string
    {
        return sprintf(
            'Almeno %d caratteri, con una lettera maiuscola, una minuscola e un numero.',
            $minLength ?? self::MIN_LENGTH,
        );
    }

    /** "una maiuscola e un numero" invece di "una maiuscola, un numero". */
    private static function elenco(array $voci): string
    {
        if (count($voci) === 1) {
            return $voci[0];
        }

        $ultima = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultima;
    }
}
