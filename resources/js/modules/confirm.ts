/**
 * Conferma per le operazioni distruttive.
 *
 * Il pannello e usato da persone non tecniche: eliminare un album di trecento
 * fotografie non deve poter succedere con un clic distratto.
 *
 * La domanda si apre in un <dialog> nativo, non in linea dentro la riga della
 * tabella. Nella riga non c'era spazio - la domanda e i due pulsanti finivano
 * schiacciati in fondo a una colonna - e soprattutto poteva passare
 * inosservata proprio nel momento in cui va letta. Il dialogo del browser
 * porta con se quello che servirebbe riscrivere a mano: sfondo oscurato, fuoco
 * trattenuto dentro la finestra, chiusura con Esc, ritorno del fuoco al
 * pulsante di partenza.
 *
 * Il pulsante che apre resta di tipo submit, cosi senza JavaScript elimina
 * direttamente invece di non fare niente: qui si intercetta il clic.
 */

import { component } from './alpine';

export function confirmAction() {
    return component({
        finestra(): HTMLDialogElement | null {
            return (this.$refs.finestra as HTMLDialogElement | undefined) ?? null;
        },

        apri(): void {
            const finestra = this.finestra();

            if (finestra === null) {
                return;
            }

            // showModal manca nei browser molto vecchi: meglio eliminare senza
            // conferma che avere un pulsante che non fa niente.
            if (typeof finestra.showModal !== 'function') {
                (this.$el as HTMLElement).closest('form')?.submit();

                return;
            }

            finestra.showModal();
        },

        annulla(): void {
            this.finestra()?.close();
        },

        chiusa(): void {
            // Il browser riporta da solo il fuoco al pulsante che ha aperto la
            // finestra: non c'e altro da fare, ma l'evento resta agganciato
            // perche in futuro potrebbe servire.
        },
    });
}
