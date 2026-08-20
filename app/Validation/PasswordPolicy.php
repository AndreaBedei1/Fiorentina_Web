<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Regole di robustezza delle password, in un unico posto.
 *
 * Sono usate sia dal validatore dei form sia dallo script di creazione del
 * primo amministratore: senza una fonte unica, prima o poi le due strade
 * finirebbero per divergere, e l unica cosa peggiore di una regola debole e
 * una regola che vale solo in met&agrave; dei casi.
 *
 * Nessun requisito barocco di simboli obbligatori: lunghezza e varieta contano
 * più di regole che spingono le persone verso password prevedibili del tipo
 * "Password1!".
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 200;

    /** Numero minimo di categorie di caratteri diverse richieste. */
    public const MIN_CHARACTER_CLASSES = 3;

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

        if (self::countCharacterClasses($password) < self::MIN_CHARACTER_CLASSES) {
            return sprintf(
                'La password deve combinare almeno %d fra: minuscole, maiuscole, numeri e simboli.',
                self::MIN_CHARACTER_CLASSES,
            );
        }

        return null;
    }

    public static function isValid(string $password, ?int $minLength = null): bool
    {
        return self::check($password, $minLength) === null;
    }

    /** Quante categorie di caratteri diverse compaiono nella password. */
    public static function countCharacterClasses(string $password): int
    {
        $classes = 0;
        $classes += preg_match('/\p{Ll}/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\p{Lu}/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\d/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^\p{L}\d]/u', $password) === 1 ? 1 : 0;

        return $classes;
    }

    /** Descrizione da mostrare accanto ai campi password. */
    public static function description(?int $minLength = null): string
    {
        return sprintf(
            'Almeno %d caratteri, combinando almeno %d fra: minuscole, maiuscole, numeri e simboli.',
            $minLength ?? self::MIN_LENGTH,
            self::MIN_CHARACTER_CLASSES,
        );
    }
}
