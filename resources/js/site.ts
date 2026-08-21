/**
 * Entrypoint del sito pubblico.
 *
 * Il sito e reso lato server: JavaScript aggiunge comodita (menu, filtri,
 * lightbox), non contenuto. Senza JavaScript tutte le pagine restano leggibili
 * e tutti i moduli restano inviabili.
 *
 * Nota su Alpine: usiamo la build "CSP" (@alpinejs/csp) invece di quella
 * standard. Quest ultima valuta le espressioni dei template come JavaScript,
 * e per farlo pretende 'unsafe-eval' nella Content-Security-Policy. Con la
 * build CSP i template contengono solo nomi di proprieta e metodi, e la policy
 * resta stretta. In cambio, nei template non si scrivono espressioni: tutta la
 * logica sta nei componenti qui sotto.
 */

import Alpine from '@alpinejs/csp';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

import '../css/site.css';
import { initReveal } from './modules/reveal';
import { initLightbox } from './modules/lightbox';
import { siteHeader } from './modules/header';
import { cartQuantity } from './modules/cart';

declare global {
    interface Window {
        Alpine: typeof Alpine;
    }
}

/*
 * Segnala che JavaScript e attivo. Gli stili di comparsa allo scorrimento
 * dipendono da questa classe: senza, il contenuto resterebbe nascosto per chi
 * ha JavaScript disattivato o non ancora caricato.
 */
document.documentElement.classList.add('js');

Alpine.plugin(collapse);
Alpine.plugin(focus);

// Componenti dichiarati nei template come x-data="nomeComponente" (senza parentesi).
Alpine.data('siteHeader', siteHeader);
Alpine.data('cartQuantity', cartQuantity);

window.Alpine = Alpine;
Alpine.start();

initReveal();
initLightbox();
