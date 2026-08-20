<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Console;
use App\Core\Security\Hash;
use App\Models\User;
use App\Repositories\UserRepository;

/**
 * Primo super amministratore per l'ambiente di sviluppo.
 *
 * Vincoli deliberati:
 *  - viene creato SOLO con APP_ENV=local;
 *  - la password arriva da DEV_ADMIN_PASSWORD, non e scritta nel codice;
 *  - senza quella variabile il seeder si ferma e lo dice.
 *
 * In produzione il primo account si crea con `php scripts/create-admin.php`,
 * che chiede la password in modo interattivo.
 */
final class AdminSeeder extends Seeder
{
    public function name(): string
    {
        return 'Amministratore di sviluppo';
    }

    public function run(): int
    {
        if (! $this->app->isLocal()) {
            Console::warn('Ambiente non locale: nessun amministratore demo creato.');
            Console::bullet('Usa: php scripts/create-admin.php');

            return 0;
        }

        $email = mb_strtolower(trim((string) env('DEV_ADMIN_EMAIL', 'admin@baraondafiorentina.test')));
        $password = (string) env('DEV_ADMIN_PASSWORD', '');
        $name = (string) env('DEV_ADMIN_NAME', 'Super Admin Demo');

        if ($password === '') {
            Console::warn('DEV_ADMIN_PASSWORD non impostata nel file .env: amministratore non creato.');
            Console::bullet('Imposta la variabile e rilancia, oppure usa php scripts/create-admin.php');

            return 0;
        }

        if (mb_strlen($password) < 12) {
            Console::warn('DEV_ADMIN_PASSWORD e troppo corta (minimo 12 caratteri): amministratore non creato.');

            return 0;
        }

        $users = $this->app->get(UserRepository::class);

        if ($users->findByEmail($email) !== null) {
            $this->say(sprintf('Amministratore già presente: %s', $email));

            return 0;
        }

        $hash = $this->app->get(Hash::class);

        $id = $users->create([
            'name' => $name,
            'email' => $email,
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'password_hash' => $hash->make($password),
        ]);

        $users->update($id, ['password_changed_at' => $this->now()]);

        $this->say(sprintf('Super amministratore creato: %s (algoritmo: %s)', $email, $hash->algorithmName()));

        return 1;
    }
}
