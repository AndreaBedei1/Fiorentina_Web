<?php

declare(strict_types=1);

/**
 * Crea il primo amministratore.
 *
 *     php scripts/create-admin.php
 *
 * E la strada da usare in produzione, dove il seed di sviluppo non funziona.
 * La password viene chiesta in modo interattivo e non compare mai fra i
 * parametri della riga di comando: altrimenti resterebbe nella cronologia
 * della shell e, su alcuni sistemi, visibile nella lista dei processi.
 *
 * Puo anche promuovere un account esistente a super amministratore, opzione
 * utile quando si e persa la password dell unico account con i privilegi.
 */

use App\Console\Console;
use App\Core\Application;
use App\Core\Security\Hash;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Validation\PasswordPolicy;

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

/** Legge una riga da tastiera. */
function ask(string $question, bool $required = true): string
{
    while (true) {
        fwrite(STDOUT, $question);
        $answer = trim((string) fgets(STDIN));

        if ($answer !== '' || ! $required) {
            return $answer;
        }

        Console::warn('Il campo e obbligatorio.');
    }
}

/**
 * Legge una password nascondendo cio che viene digitato.
 *
 * Su sistemi POSIX si usa `stty -echo`. Su Windows la shell non offre un
 * equivalente affidabile, quindi avvisiamo chiaramente che l input sara
 * visibile invece di far credere il contrario.
 */
function askPassword(string $question): string
{
    $isWindows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');

    if ($isWindows) {
        Console::warn('Su Windows la password digitata resta visibile a schermo.');

        return ask($question);
    }

    fwrite(STDOUT, $question);
    shell_exec('stty -echo');
    $password = trim((string) fgets(STDIN));
    shell_exec('stty echo');
    fwrite(STDOUT, PHP_EOL);

    return $password;
}

Console::title('Creazione amministratore');

$users = $app->get(UserRepository::class);
$hash = $app->get(Hash::class);

Console::info(sprintf('Ambiente: %s | Algoritmo password: %s', $app->environment(), $hash->algorithmName()));
Console::line();

$email = mb_strtolower(ask('Email:            '));

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    Console::error('Indirizzo email non valido.');
    exit(1);
}

$existing = $users->findByEmail($email);

if ($existing !== null) {
    Console::warn(sprintf('Esiste già un account con questa email (%s, %s).', $existing->name, $existing->statusLabel()));

    $answer = mb_strtolower(ask('Vuoi promuoverlo a super amministratore e reimpostarne la password? [s/N]: ', required: false));

    if ($answer !== 's' && $answer !== 'si') {
        Console::line();
        Console::info('Nessuna modifica effettuata.');
        exit(0);
    }

    $password = askPassword('Nuova password:   ');
    $confirm = askPassword('Ripeti password:  ');

    if ($password !== $confirm) {
        Console::error('Le due password non coincidono.');
        exit(1);
    }

    $problem = PasswordPolicy::check($password);

    if ($problem !== null) {
        Console::error($problem);
        exit(1);
    }

    $users->updatePassword($existing->id, $hash->make($password));
    $users->update($existing->id, ['role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);

    Console::line();
    Console::success(sprintf('Account %s aggiornato: super amministratore, attivo, nuova password impostata.', $email));
    exit(0);
}

$name = ask('Nome e cognome:   ');
$password = askPassword('Password:         ');
$confirm = askPassword('Ripeti password:  ');

if ($password !== $confirm) {
    Console::error('Le due password non coincidono.');
    exit(1);
}

$problem = PasswordPolicy::check($password);

if ($problem !== null) {
    Console::error($problem);
    exit(1);
}

$id = $users->create([
    'name' => $name,
    'email' => $email,
    'role' => User::ROLE_SUPER_ADMIN,
    'status' => User::STATUS_ACTIVE,
    'password_hash' => $hash->make($password),
]);

$users->update($id, ['password_changed_at' => date('Y-m-d H:i:s')]);

Console::line();
Console::success('Super amministratore creato.');
Console::bullet('Email:    ' . $email);
Console::bullet('Accesso:  ' . rtrim((string) config('app.url'), '/') . '/admin');
Console::line();
Console::info('Da qui in avanti gli altri amministratori si invitano dal pannello, alla voce Amministratori.');
