<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Errore di risoluzione delle dipendenze: sempre un bug di configurazione. */
final class ContainerException extends \RuntimeException
{
}
