/**
 * Comportamento dell'intestazione: menu mobile e stato "pagina scorsa".
 *
 * Scritto per la build CSP di Alpine: i template possono riferirsi solo a
 * proprieta e metodi, quindi tutto cio che nella build standard sarebbe una
 * espressione in linea (`scrolled ? 'a' : 'b'`) qui e un getter.
 *
 * Il pannello mobile usa x-trap: finche e aperto il fuoco resta al suo interno
 * ed Esc lo chiude. Senza queste due cose un menu a scomparsa e inutilizzabile
 * da tastiera.
 */

import { component } from './alpine';
export function siteHeader() {
    return component({
        open: false,
        scrolled: false,

        init(): void {
            const update = (): void => {
                this.scrolled = window.scrollY > 8;
            };

            update();
            // passive: lo scorrimento non deve mai attendere il nostro codice.
            window.addEventListener('scroll', update, { passive: true });

            /*
             * Il pannello mobile non ha senso oltre il punto di rottura lg: se
             * la finestra viene allargata mentre e aperto va chiuso, altrimenti
             * resterebbe un blocco fuori posto nel layout desktop.
             */
            window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
                if (event.matches) {
                    this.open = false;
                }
            });
        },

        /** Classi aggiuntive quando la pagina e stata scorsa. */
        get scrolledClass(): string {
            return this.scrolled ? 'shadow-lg ring-1 ring-white/10' : '';
        },

        /** aria-expanded vuole una stringa, non un booleano. */
        get expanded(): string {
            return this.open ? 'true' : 'false';
        },

        get isOpen(): boolean {
            return this.open;
        },

        get isClosed(): boolean {
            return !this.open;
        },

        toggle(): void {
            this.open = !this.open;
        },

        close(): void {
            this.open = false;
        },
    });
}
