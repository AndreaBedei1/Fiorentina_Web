<?php

declare(strict_types=1);

use App\Middleware\AdminAuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\ForceHttpsMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\RequireSuperAdminMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\StartSessionGuardMiddleware;

return [
    /*
     * Eseguiti su ogni richiesta, in ordine. I primi due riguardano il
     * trasporto e gli header, quindi devono avvolgere tutto il resto.
     */
    'global' => [
        ForceHttpsMiddleware::class,
        SecurityHeadersMiddleware::class,
        StartSessionGuardMiddleware::class,
        CsrfMiddleware::class,
        MaintenanceMiddleware::class,
    ],

    /* Applicabili per rotta o per gruppo tramite alias. */
    'aliases' => [
        'admin' => AdminAuthMiddleware::class,
        'superadmin' => RequireSuperAdminMiddleware::class,
    ],
];
