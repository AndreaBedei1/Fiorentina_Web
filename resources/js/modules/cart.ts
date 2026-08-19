/**
 * Selettore di quantita del carrello.
 *
 * I pulsanti aggiornano un campo numerico reale: il modulo resta inviabile
 * senza JavaScript, e chi usa la tastiera puo digitare direttamente il valore.
 *
 * Compatibile con la build CSP di Alpine: nel template si usano solo nomi di
 * metodi, e i parametri arrivano da x-data tramite gli attributi data-*.
 */

import { component } from './alpine';
export function cartQuantity() {
    return component({
        quantity: 1,
        max: 20,

        init(): void {
            const element = this.$el as HTMLElement;

            this.quantity = Number(element.dataset.quantity ?? '1') || 1;
            this.max = Number(element.dataset.max ?? '20') || 20;
        },

        increase(): void {
            if (this.quantity < this.max) {
                this.quantity += 1;
                this.sync();
            }
        },

        decrease(): void {
            if (this.quantity > 1) {
                this.quantity -= 1;
                this.sync();
            }
        },

        /** Normalizza cio che l'utente digita a mano. */
        normalise(): void {
            const value = Number(this.quantity);

            if (!Number.isFinite(value) || value < 1) {
                this.quantity = 1;
            } else if (value > this.max) {
                this.quantity = this.max;
            } else {
                this.quantity = Math.floor(value);
            }

            this.sync();
        },

        /**
         * Invia il modulo della riga, se e marcato come auto-inviabile.
         * Nel carrello ogni riga e un modulo autonomo: cambiando la quantita
         * il totale si aggiorna senza premere un pulsante "salva".
         */
        sync(): void {
            const form = (this.$el as HTMLElement).closest('form');

            if (form instanceof HTMLFormElement && form.dataset.autosubmit === 'true') {
                form.requestSubmit();
            }
        },
    });
}
