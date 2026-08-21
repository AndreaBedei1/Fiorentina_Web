<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;

/**
 * Validazione lato server.
 *
 * La validazione JavaScript esiste solo per comodita dell'utente: qualunque
 * richiesta può aggirarla. Ogni form del sito passa da qui prima che un dato
 * raggiunga il database.
 *
 * Uso:
 *     $data = Validator::make($request->all())
 *         ->required('titolo', 'Il titolo')->max('titolo', 200)
 *         ->email('email', 'L indirizzo email')
 *         ->validate();
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param array<string, mixed> $data */
    public static function make(array $data): self
    {
        return new self($data);
    }

    // -----------------------------------------------------------------------
    //  Regole
    // -----------------------------------------------------------------------

    public function required(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
            return $this->addError($field, $this->messaggioObbligatorio($label));
        }

        return $this->keep($field, is_string($value) ? trim($value) : $value);
    }

    /**
     * "Il nome è obbligatorio", ma "La città è obbligatoria".
     *
     * Le etichette di questo progetto cominciano sempre con l'articolo, ed e
     * l'unica cosa da guardare per far concordare l'aggettivo: senza, i campi
     * femminili producevano messaggi sgrammaticati.
     */
    private function messaggioObbligatorio(string $label): string
    {
        $femminile = preg_match('/^(La|Le)\s/u', $label) === 1;

        return sprintf('%s è %s.', $label, $femminile ? 'obbligatoria' : 'obbligatorio');
    }

    /** Campo facoltativo: se assente resta null, se presente viene ripulito. */
    public function optional(string $field): self
    {
        $value = $this->raw($field);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $this->keep($field, null);
        }

        return $this->keep($field, is_string($value) ? trim($value) : $value);
    }

    public function min(string $field, int $length, string $label): self
    {
        $value = $this->current($field);

        if (is_string($value) && mb_strlen($value) < $length) {
            return $this->addError($field, sprintf('%s deve contenere almeno %d caratteri.', $label, $length));
        }

        return $this;
    }

    public function max(string $field, int $length, string $label): self
    {
        $value = $this->current($field);

        if (is_string($value) && mb_strlen($value) > $length) {
            return $this->addError($field, sprintf('%s non può superare %d caratteri.', $label, $length));
        }

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = $this->current($field);

        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->addError($field, sprintf('%s non è un indirizzo email valido.', $label));
        }

        if (is_string($value) && $value !== '') {
            $this->keep($field, mb_strtolower($value));
        }

        return $this;
    }

    public function url(string $field, string $label): self
    {
        $value = $this->current($field);

        if (! is_string($value) || $value === '') {
            return $this;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)
        ) {
            return $this->addError($field, sprintf('%s deve essere un indirizzo web valido (http o https).', $label));
        }

        return $this;
    }

    /** Numero di telefono: accettiamo cifre, spazi, punti, trattini, parentesi e prefisso. */
    public function phone(string $field, string $label): self
    {
        $value = $this->current($field);

        if (! is_string($value) || $value === '') {
            return $this;
        }

        if (preg_match('/^\+?[0-9 ().\/-]{6,25}$/', $value) !== 1) {
            return $this->addError($field, sprintf('%s non è un numero di telefono valido.', $label));
        }

        return $this;
    }

    public function integer(string $field, string $label, ?int $min = null, ?int $max = null): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this->keep($field, null);
        }

        if (! is_numeric($value)) {
            return $this->addError($field, sprintf('%s deve essere un numero intero.', $label));
        }

        $number = (int) $value;

        if ($min !== null && $number < $min) {
            return $this->addError($field, sprintf('%s non può essere inferiore a %d.', $label, $min));
        }

        if ($max !== null && $number > $max) {
            return $this->addError($field, sprintf('%s non può essere superiore a %d.', $label, $max));
        }

        return $this->keep($field, $number);
    }

    public function decimal(string $field, string $label, ?float $min = null, ?float $max = null): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this->keep($field, null);
        }

        if (is_string($value)) {
            // In Italia i prezzi si scrivono con la virgola: la accettiamo.
            $value = str_replace([' ', ','], ['', '.'], $value);
        }

        if (! is_numeric($value)) {
            return $this->addError($field, sprintf('%s deve essere un importo valido.', $label));
        }

        $number = round((float) $value, 2);

        if ($min !== null && $number < $min) {
            return $this->addError($field, sprintf('%s non può essere inferiore a %s.', $label, number_format($min, 2, ',', '.')));
        }

        if ($max !== null && $number > $max) {
            return $this->addError($field, sprintf('%s non può superare %s.', $label, number_format($max, 2, ',', '.')));
        }

        return $this->keep($field, $number);
    }

    /** @param list<string> $allowed */
    /**
     * Il valore, se ammesso, finisce fra i dati validati: le altre regole fanno
     * lo stesso e i controller leggono da li. Limitarsi a controllare senza
     * conservare farebbe ricadere ogni campo sul proprio valore predefinito,
     * in silenzio.
     */
    public function in(string $field, array $allowed, string $label): self
    {
        $value = $this->current($field);

        if ($value === null || $value === '') {
            return $this->keep($field, null);
        }

        if (! in_array((string) $value, $allowed, true)) {
            return $this->addError($field, sprintf('%s contiene un valore non ammesso.', $label));
        }

        return $this->keep($field, (string) $value);
    }

    public function boolean(string $field): self
    {
        return $this->keep($field, filter_var($this->raw($field) ?? false, FILTER_VALIDATE_BOOL));
    }

    /** Data nel formato dei campi HTML (`YYYY-MM-DD`). */
    public function date(string $field, string $label, bool $required = false): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            if ($required) {
                return $this->addError($field, $this->messaggioObbligatorio($label));
            }

            return $this->keep($field, null);
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== (string) $value) {
            return $this->addError($field, sprintf('%s non è una data valida.', $label));
        }

        return $this->keep($field, (string) $value);
    }

    /** Orario nel formato dei campi HTML (`HH:MM`). */
    public function time(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this->keep($field, null);
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value) !== 1) {
            return $this->addError($field, sprintf('%s non è un orario valido.', $label));
        }

        return $this->keep($field, (string) $value);
    }

    public function postalCode(string $field, string $label): self
    {
        $value = $this->current($field);

        if (is_string($value) && $value !== '' && preg_match('/^\d{5}$/', $value) !== 1) {
            return $this->addError($field, sprintf('%s deve essere composto da 5 cifre.', $label));
        }

        return $this;
    }

    public function province(string $field, string $label): self
    {
        $value = $this->current($field);

        if (is_string($value) && $value !== '') {
            $value = mb_strtoupper($value);
            $this->keep($field, $value);

            if (preg_match('/^[A-Z]{2}$/', $value) !== 1) {
                return $this->addError($field, sprintf('%s deve essere la sigla di due lettere (es. FI).', $label));
            }
        }

        return $this;
    }

    public function matches(string $field, string $otherField, string $label): self
    {
        if ($this->raw($field) !== $this->raw($otherField)) {
            return $this->addError($field, sprintf('%s non coincide.', $label));
        }

        return $this;
    }

    /**
     * Robustezza minima della password.
     *
     * Nessun requisito barocco di simboli obbligatori: la lunghezza e la
     * varieta contano più di regole che spingono a password prevedibili.
     */
    public function password(string $field, string $label, int $minLength = PasswordPolicy::MIN_LENGTH): self
    {
        $value = $this->raw($field);

        if (! is_string($value) || $value === '') {
            return $this->addError($field, $this->messaggioObbligatorio($label));
        }

        // Le regole vivono in PasswordPolicy: le condivide con lo script di
        // creazione del primo amministratore, così non possono divergere.
        $problem = PasswordPolicy::check($value, $minLength);

        if ($problem !== null) {
            return $this->addError($field, $problem);
        }

        return $this->keep($field, $value);
    }

    /** Trappola anti-spam: i bot compilano il campo nascosto, le persone no. */
    public function honeypot(string $field): self
    {
        $value = $this->raw($field);

        if (is_string($value) && trim($value) !== '') {
            return $this->addError($field, 'Richiesta non valida.');
        }

        return $this;
    }

    /** Regola personalizzata per i casi specifici di un singolo form. */
    public function rule(string $field, callable $check, string $message): self
    {
        if (! $check($this->current($field))) {
            return $this->addError($field, $message);
        }

        return $this;
    }

    /** Aggiunge un errore deciso altrove (per esempio dopo una query di unicita). */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field][] = $message;

        return $this;
    }

    // -----------------------------------------------------------------------
    //  Esito
    // -----------------------------------------------------------------------

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Restituisce i dati validati o interrompe con ValidationException.
     *
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    /** @return array<string, mixed> Dati validati, senza sollevare eccezioni. */
    public function validatedData(): array
    {
        return $this->validated;
    }

    private function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    private function current(string $field): mixed
    {
        return $this->validated[$field] ?? $this->raw($field);
    }

    private function keep(string $field, mixed $value): self
    {
        $this->validated[$field] = $value;

        return $this;
    }
}
