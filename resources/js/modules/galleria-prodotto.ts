/**
 * Le fotografie di un prodotto, sfogliabili.
 *
 * Le miniature sotto l'immagine grande erano solo immagini: si vedevano, non
 * si potevano aprire, e chi guardava una felpa vedeva una fotografia sola.
 *
 * Le immagini stanno tutte nel documento, una sopra l'altra, e si mostra
 * quella scelta. Non si riscrivono gli indirizzi in JavaScript: ogni
 * fotografia porta con se il proprio srcset, quindi il browser continua a
 * scegliere la misura giusta per lo schermo com'e sempre stato.
 *
 * Senza JavaScript restano visibili la prima immagine e le miniature, che e
 * esattamente quello che si vedeva prima.
 */

import { component } from './alpine';

export function galleriaProdotto() {
    return component({
        scelta: 0,

        /** Mostra la fotografia toccata. */
        mostra(event: Event): void {
            const pulsante = (event.currentTarget as HTMLElement | null)?.closest<HTMLElement>('[data-indice]');
            const indice = Number.parseInt(pulsante?.dataset.indice ?? '', 10);

            if (!Number.isNaN(indice)) {
                this.scelta = indice;
            }
        },

        /** Frecce sinistra e destra: sfogliare da tastiera senza cercare i pulsanti. */
        sfoglia(event: KeyboardEvent): void {
            const totale = Number.parseInt((this.$el as HTMLElement).dataset.quante ?? '0', 10);

            if (totale < 2) {
                return;
            }

            if (event.key === 'ArrowRight') {
                this.scelta = (this.scelta + 1) % totale;
            } else if (event.key === 'ArrowLeft') {
                this.scelta = (this.scelta - 1 + totale) % totale;
            }
        },
    });
}
