<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Token di autenticazione, tentativi di accesso e contatori di rate limiting.
 *
 * Tutti i token sono salvati come hash SHA-256: chi legge il database non può
 * accettare un invito o completare un reset password al posto di qualcun altro.
 * La ricerca avviene per hash, quindi resta una lookup indicizzata.
 */
final class AuthTokenRepository extends BaseRepository
{
    protected string $table = 'admin_invites';

    // -----------------------------------------------------------------------
    //  Inviti amministratore
    // -----------------------------------------------------------------------

    public function createInvite(
        string $email,
        string $name,
        string $role,
        string $tokenHash,
        int $userId,
        ?int $invitedBy,
        int $ttlMinutes,
    ): int {
        return $this->db->insertInto('admin_invites', [
            'email' => mb_strtolower(trim($email)),
            'name' => $name,
            'role' => $role,
            'token_hash' => $tokenHash,
            'user_id' => $userId,
            'invited_by' => $invitedBy,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlMinutes * 60),
            'created_at' => $this->now(),
        ]);
    }

    /** @return array<string, mixed>|null Invito valido: non scaduto, non accettato, non revocato. */
    public function findValidInvite(string $tokenHash): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM admin_invites
             WHERE token_hash = :hash
               AND accepted_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > NOW()',
            ['hash' => $tokenHash],
        );
    }

    public function markInviteAccepted(int $inviteId): void
    {
        $this->db->updateWhereId('admin_invites', $inviteId, ['accepted_at' => $this->now()]);
    }

    public function revokeInvitesForUser(int $userId): void
    {
        $this->db->statement(
            'UPDATE admin_invites SET revoked_at = :now
             WHERE user_id = :user AND accepted_at IS NULL AND revoked_at IS NULL',
            ['now' => $this->now(), 'user' => $userId],
        );
    }

    /** @return list<array<string, mixed>> Inviti ancora aperti, mostrati nel pannello. */
    public function pendingInvites(): array
    {
        return $this->db->select(
            'SELECT i.*, u.name AS inviter_name
             FROM admin_invites i
             LEFT JOIN users u ON u.id = i.invited_by
             WHERE i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > NOW()
             ORDER BY i.created_at DESC'
        );
    }

    // -----------------------------------------------------------------------
    //  Reset password
    // -----------------------------------------------------------------------

    public function createPasswordReset(int $userId, string $tokenHash, string $ip, int $ttlMinutes): int
    {
        // Un solo reset valido per volta: le richieste precedenti decadono.
        $this->invalidatePasswordResets($userId);

        return $this->db->insertInto('password_reset_tokens', [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'requested_ip' => $ip,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlMinutes * 60),
            'created_at' => $this->now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findValidPasswordReset(string $tokenHash): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM password_reset_tokens
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()',
            ['hash' => $tokenHash],
        );
    }

    public function markPasswordResetUsed(int $tokenId): void
    {
        $this->db->updateWhereId('password_reset_tokens', $tokenId, ['used_at' => $this->now()]);
    }

    public function invalidatePasswordResets(int $userId): void
    {
        $this->db->statement(
            'UPDATE password_reset_tokens SET used_at = :now WHERE user_id = :user AND used_at IS NULL',
            ['now' => $this->now(), 'user' => $userId],
        );
    }

    // -----------------------------------------------------------------------
    //  Tentativi di accesso
    // -----------------------------------------------------------------------

    public function recordLoginAttempt(string $email, string $ip, bool $successful, string $userAgent): void
    {
        $this->db->insertInto('login_attempts', [
            'email' => mb_substr(mb_strtolower(trim($email)), 0, 190),
            'ip' => $ip,
            'successful' => $successful ? 1 : 0,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'attempted_at' => $this->now(),
        ]);
    }

    /**
     * Tentativi falliti recenti.
     *
     * Il conteggio e per coppia email + IP: bloccare solo per email
     * consentirebbe a un estraneo di tenere fuori un amministratore legittimo
     * sbagliando la password di proposito.
     */
    public function countRecentFailures(string $email, string $ip, int $minutes): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE successful = 0
               AND (email = :email OR ip = :ip)
               AND attempted_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)',
            ['email' => mb_strtolower(trim($email)), 'ip' => $ip, 'minutes' => max(1, $minutes)],
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentAttempts(int $limit = 20): array
    {
        return $this->db->select(
            'SELECT * FROM login_attempts ORDER BY attempted_at DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    // -----------------------------------------------------------------------
    //  Rate limiting generico
    // -----------------------------------------------------------------------

    /** @return array{attempts: int, expires_at: string}|null */
    public function findRateLimit(string $bucketKey): ?array
    {
        $row = $this->db->selectOne(
            'SELECT attempts, expires_at FROM rate_limits WHERE bucket_key = :key AND expires_at > NOW()',
            ['key' => $bucketKey],
        );

        if ($row === null) {
            return null;
        }

        return ['attempts' => (int) $row['attempts'], 'expires_at' => (string) $row['expires_at']];
    }

    /**
     * Incrementa il contatore, creando la finestra se assente.
     *
     * L'operazione e atomica (INSERT ... ON DUPLICATE KEY UPDATE): due richieste
     * simultanee non possono azzerarsi a vicenda il contatore.
     */
    public function hitRateLimit(string $bucketKey, int $decayMinutes): int
    {
        $now = $this->now();
        $expires = date('Y-m-d H:i:s', time() + $decayMinutes * 60);

        $this->db->statement(
            'INSERT INTO rate_limits (bucket_key, attempts, expires_at, created_at)
             VALUES (:key, 1, :expires, :now)
             ON DUPLICATE KEY UPDATE
                attempts = IF(expires_at > NOW(), attempts + 1, 1),
                expires_at = IF(expires_at > NOW(), expires_at, :expires2)',
            ['key' => $bucketKey, 'expires' => $expires, 'now' => $now, 'expires2' => $expires],
        );

        return (int) $this->db->scalar(
            'SELECT attempts FROM rate_limits WHERE bucket_key = :key',
            ['key' => $bucketKey],
        );
    }

    public function clearRateLimit(string $bucketKey): void
    {
        $this->db->statement('DELETE FROM rate_limits WHERE bucket_key = :key', ['key' => $bucketKey]);
    }

    // -----------------------------------------------------------------------
    //  Pulizia (cron)
    // -----------------------------------------------------------------------

    /** @return array{invites: int, resets: int, attempts: int, rate_limits: int} */
    public function purgeExpired(int $attemptRetentionDays = 60): array
    {
        return [
            'invites' => $this->db->statement(
                'DELETE FROM admin_invites WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
            ),
            'resets' => $this->db->statement(
                'DELETE FROM password_reset_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
            ),
            'attempts' => $this->db->statement(
                'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL :days DAY)',
                ['days' => max(7, $attemptRetentionDays)],
            ),
            'rate_limits' => $this->db->statement('DELETE FROM rate_limits WHERE expires_at < NOW()'),
        ];
    }
}
