<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http\Request;
use App\Models\User;
use App\Repositories\AuditLogRepository;

/**
 * Registra le azioni amministrative rilevanti.
 *
 * Vincolo assoluto: qui non entrano mai password, hash o token. I metadati
 * servono a capire cosa e successo, non a ricostruire un segreto.
 */
final class AuditLogger
{
    // --- Autenticazione ---
    public const LOGIN = 'LOGIN';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';
    public const LOGOUT = 'LOGOUT';
    public const PASSWORD_RESET_REQUESTED = 'PASSWORD_RESET_REQUESTED';
    public const PASSWORD_RESET_COMPLETED = 'PASSWORD_RESET_COMPLETED';
    public const PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    // --- Amministratori ---
    public const ADMIN_INVITED = 'ADMIN_INVITED';
    public const ADMIN_ACTIVATED = 'ADMIN_ACTIVATED';
    public const ADMIN_BLOCKED = 'ADMIN_BLOCKED';
    public const ADMIN_UNBLOCKED = 'ADMIN_UNBLOCKED';
    public const ADMIN_DELETED = 'ADMIN_DELETED';
    public const ADMIN_ROLE_CHANGED = 'ADMIN_ROLE_CHANGED';
    public const ADMIN_UPDATED = 'ADMIN_UPDATED';

    // --- Contenuti ---
    public const CONTENT_CREATED = 'CONTENT_CREATED';
    public const CONTENT_UPDATED = 'CONTENT_UPDATED';
    public const CONTENT_PUBLISHED = 'CONTENT_PUBLISHED';
    public const CONTENT_UNPUBLISHED = 'CONTENT_UNPUBLISHED';
    public const CONTENT_ARCHIVED = 'CONTENT_ARCHIVED';
    public const CONTENT_DELETED = 'CONTENT_DELETED';

    // --- Media ---
    public const PHOTOS_UPLOADED = 'PHOTOS_UPLOADED';
    public const PHOTO_DELETED = 'PHOTO_DELETED';

    // --- Negozio ---
    public const ORDER_RECEIVED = 'ORDER_RECEIVED';
    public const ORDER_STATUS_CHANGED = 'ORDER_STATUS_CHANGED';
    public const ORDER_ARCHIVED = 'ORDER_ARCHIVED';

    // --- Sistema ---
    public const SETTINGS_UPDATED = 'SETTINGS_UPDATED';
    public const SYNC_RUN = 'SYNC_RUN';

    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * Scrive una voce nel registro.
     *
     * @param array<string, mixed> $metadata
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $actor ??= $this->auth->user();
        $request = $this->auth->request();

        try {
            $this->repository->create([
                'user_id' => $actor?->id,
                'user_email' => $actor?->email,
                'user_role' => $actor?->role,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description === null ? null : mb_substr($description, 0, 255),
                'metadata' => $metadata === [] ? null : $this->encodeMetadata($metadata),
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Throwable) {
            /*
             * Il registro non deve mai far fallire l'operazione che sta
             * tracciando: se la scrittura non riesce, l'azione dell'utente
             * prosegue comunque. L'errore resta visibile nel log applicativo.
             */
        }
    }

    /** Variante per gli eventi generati da CLI/cron, senza utente collegato. */
    public function logSystem(string $action, ?string $description = null, array $metadata = []): void
    {
        try {
            $this->repository->create([
                'user_id' => null,
                'user_email' => 'sistema',
                'user_role' => null,
                'action' => $action,
                'entity_type' => null,
                'entity_id' => null,
                'description' => $description === null ? null : mb_substr($description, 0, 255),
                'metadata' => $metadata === [] ? null : $this->encodeMetadata($metadata),
                'ip' => null,
                'user_agent' => 'cli',
            ]);
        } catch (\Throwable) {
            // Vale la stessa considerazione di log().
        }
    }

    /**
     * Rimuove dai metadati qualsiasi chiave che possa contenere un segreto,
     * anche se aggiunta per distrazione da chi scrivera codice in futuro.
     *
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(array $metadata): string
    {
        $forbidden = ['password', 'password_hash', 'token', 'token_hash', 'secret', 'api_key', 'authorization'];

        foreach (array_keys($metadata) as $key) {
            $normalized = strtolower((string) $key);

            foreach ($forbidden as $needle) {
                if (str_contains($normalized, $needle)) {
                    $metadata[$key] = '[rimosso]';
                }
            }
        }

        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            ?: '{}';
    }
}
