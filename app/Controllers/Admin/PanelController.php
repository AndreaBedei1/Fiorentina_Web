<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * La porta d'ingresso del pannello.
 *
 * Non mostra niente, ed e voluto. Prima qui c'era una dashboard con contatori,
 * avvisi e ultime attivita: una pagina che si guarda una volta e poi si
 * attraversa soltanto, perche il lavoro vero sta nelle sezioni del menu. Ogni
 * suo riquadro era anche una query in piu a ogni accesso.
 *
 * Adesso si arriva qui, si sceglie una voce a sinistra e si lavora.
 */
final class PanelController extends Controller
{
    public function home(Request $request): Response
    {
        return $this->render('admin/home.twig', [
            'seo' => $this->seo('Pannello')->withNoindex(),
        ]);
    }
}
