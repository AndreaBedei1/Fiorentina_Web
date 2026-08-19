/**
 * Conferma per le operazioni distruttive.
 *
 * Il pannello e usato da persone non tecniche: eliminare un album di trecento
 * fotografie non deve poter succedere con un clic distratto. La conferma e in
 * linea, non un window.confirm del browser, cosi puo spiegare esattamente cosa
 * sta per accadere.
 */

import { component } from './alpine';
export function confirmAction() {
    return component({
        confirming: false,
        timeout: 0 as unknown as ReturnType<typeof setTimeout>,

        get isConfirming(): boolean {
            return this.confirming;
        },

        get isIdle(): boolean {
            return !this.confirming;
        },

        ask(): void {
            this.confirming = true;

            // Dopo qualche secondo torna allo stato iniziale: un pulsante
            // lasciato "armato" e un incidente in attesa di accadere.
            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => {
                this.confirming = false;
            }, 6000);
        },

        cancel(): void {
            clearTimeout(this.timeout);
            this.confirming = false;
        },

        destroy(): void {
            clearTimeout(this.timeout);
        },
    });
}
