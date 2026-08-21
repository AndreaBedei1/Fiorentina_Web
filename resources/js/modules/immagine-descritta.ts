/**
 * Il testo alternativo diventa obbligatorio quando c'e una fotografia.
 *
 * Il controllo vero e a server, e resta li: questo serve solo ad accorgersene
 * prima di premere invio. Senza, chi carica una fotografia e dimentica la
 * descrizione riceve l'errore a pagina ricaricata e deve ripescare il file dal
 * disco, perche un campo di tipo file il browser non lo ripopola mai.
 *
 * L'evento si ascolta sul contenitore invece che sul singolo campo: "change"
 * risale, e cosi non serve appendere un riferimento a elementi che nascono
 * dentro un include condiviso con tutti gli altri moduli del pannello.
 */

import { component } from './alpine';

export function immagineDescritta() {
    return component({
        init(): void {
            this.applica(this.$el as HTMLElement);
        },

        controlla(event: Event): void {
            this.applica(event.currentTarget as HTMLElement);
        },

        applica(radice: HTMLElement | null): void {
            if (radice === null) {
                return;
            }

            const file = radice.querySelector<HTMLInputElement>('input[type="file"]');
            const testo = radice.querySelector<HTMLInputElement>('input[name="image_alt"]');

            if (!file || !testo) {
                return;
            }

            const serve = (file.files?.length ?? 0) > 0 || radice.dataset.giaPresente === '1';

            testo.toggleAttribute('required', serve);
            radice.querySelector(`label[for="${testo.id}"]`)?.toggleAttribute('data-required', serve);
        },
    });
}
