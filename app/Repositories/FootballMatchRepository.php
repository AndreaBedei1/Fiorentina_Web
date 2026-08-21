<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FootballMatch;

/** Copia locale del calendario partite della Fiorentina. */
final class FootballMatchRepository extends BaseRepository
{
    protected string $table = 'football_matches';

    public function find(int $id): ?FootballMatch
    {
        $row = $this->db->selectOne('SELECT * FROM football_matches WHERE id = :id', ['id' => $id]);

        return $row === null ? null : FootballMatch::fromRow($row);
    }

    /**
     * Prossima partita in programma.
     *
     * La finestra di tre ore all'indietro tiene la partita in evidenza anche
     * mentre si sta giocando, che e esattamente quando il tifoso guarda il sito.
     */
    public function nextMatch(): ?FootballMatch
    {
        $row = $this->db->selectOne(
            "SELECT * FROM football_matches
             WHERE status IN ('scheduled', 'live')
               AND kickoff_at >= DATE_SUB(NOW(), INTERVAL 3 HOUR)
             ORDER BY kickoff_at ASC LIMIT 1"
        );

        return $row === null ? null : FootballMatch::fromRow($row);
    }

    /** @return list<FootballMatch> */
    public function upcoming(int $limit = 5): array
    {
        return array_map(
            FootballMatch::fromRow(...),
            $this->db->select(
                "SELECT * FROM football_matches
                 WHERE status IN ('scheduled', 'live', 'postponed')
                   AND kickoff_at >= DATE_SUB(NOW(), INTERVAL 3 HOUR)
                 ORDER BY kickoff_at ASC LIMIT " . max(1, min(50, $limit))
            ),
        );
    }

    /** @return list<FootballMatch> */
    public function recentResults(int $limit = 5): array
    {
        return array_map(
            FootballMatch::fromRow(...),
            $this->db->select(
                "SELECT * FROM football_matches
                 WHERE status = 'finished'
                 ORDER BY kickoff_at DESC LIMIT " . max(1, min(50, $limit))
            ),
        );
    }

    /** @return list<FootballMatch> */
    public function betweenDates(string $from, string $to): array
    {
        return array_map(
            FootballMatch::fromRow(...),
            $this->db->select(
                'SELECT * FROM football_matches
                 WHERE kickoff_at >= :from AND kickoff_at < :to
                 ORDER BY kickoff_at ASC',
                ['from' => $from, 'to' => $to],
            ),
        );
    }

    /**
     * Inserisce o aggiorna una partita in base alla coppia provider + id esterno.
     *
     * Le partite inserite a mano non vengono toccate dalla sincronizzazione:
     * se un amministratore ha corretto un dato, il cron non deve sovrascriverlo.
     *
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): void
    {
        $now = $this->now();

        $existing = $this->db->selectOne(
            'SELECT id, is_manual FROM football_matches WHERE provider = :provider AND external_id = :external',
            ['provider' => $data['provider'], 'external' => $data['external_id']],
        );

        if ($existing !== null) {
            if ((int) $existing['is_manual'] === 1) {
                return;
            }

            $data['synced_at'] = $now;
            $data['updated_at'] = $now;
            unset($data['provider'], $data['external_id']);

            $this->db->updateWhereId('football_matches', (int) $existing['id'], $data);

            return;
        }

        $data['synced_at'] = $now;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $this->db->insertInto('football_matches', $data);
    }

    /** @param array<string, mixed> $data */
    public function createManual(array $data): int
    {
        $now = $this->now();
        $data['provider'] = 'manual';
        $data['external_id'] = 'manual-' . bin2hex(random_bytes(8));
        $data['is_manual'] = 1;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->db->insertInto('football_matches', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = $this->now();

        return $this->db->updateWhereId('football_matches', $id, $data) >= 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->statement('DELETE FROM football_matches WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Elimina le partite lasciate da un fornitore che non e piu quello attivo.
     *
     * Passando dai dati dimostrativi a quelli veri, le partite inventate
     * restavano a database e comparivano in calendario accanto a quelle
     * autentiche: un elenco meta vero e meta finto, che e peggio di un elenco
     * dichiaratamente finto.
     *
     * Le partite inserite a mano non si toccano mai: le ha scritte una persona.
     *
     * @return int Quante ne sono state eliminate.
     */
    public function deleteFromOtherProviders(string $activeProvider): int
    {
        return $this->db->statement(
            'DELETE FROM football_matches
             WHERE is_manual = 0
               AND provider <> :attivo
               AND provider <> :manuale',
            ['attivo' => $activeProvider, 'manuale' => 'manual'],
        );
    }

    public function lastSyncedAt(): ?string
    {
        $value = $this->db->scalar('SELECT MAX(synced_at) FROM football_matches');

        return $value === null ? null : (string) $value;
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM football_matches');
    }
}
