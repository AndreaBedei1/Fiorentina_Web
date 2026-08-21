<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\RedirectResponse;
use App\Core\Http\Response;

/**
 * Il vecchio indirizzo del calendario.
 *
 * Il calendario non ha piu una pagina propria: sta in fondo alla pagina degli
 * eventi, perche elenco e griglia sono la stessa cosa guardata in due modi e
 * tenerli separati costringeva a saltare da una pagina all'altra.
 *
 * L'indirizzo pero resta valido. Qualcuno lo ha condiviso, qualcuno lo ha nei
 * segnalibri, e i motori di ricerca lo hanno indicizzato: un 404 avrebbe
 * buttato via tutto questo. Con un reindirizzamento permanente il collegamento
 * continua a funzionare e la pagina viene trasferita invece che persa.
 *
 * I parametri del mese vengono conservati, cosi un collegamento a un mese
 * preciso porta ancora a quel mese.
 */
final class CalendarController extends Controller
{
    public function redirectToEvents(Request $request): Response
    {
        $parametri = [];

        foreach (['anno', 'mese'] as $chiave) {
            $valore = $request->nullableInt($chiave);

            if ($valore !== null) {
                $parametri[$chiave] = $valore;
            }
        }

        $destinazione = $this->url->route('events.index');

        if ($parametri !== []) {
            $destinazione .= '?' . http_build_query($parametri);
        }

        return RedirectResponse::to($destinazione . '#calendario', 301);
    }
}
