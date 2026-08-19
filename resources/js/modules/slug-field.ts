/**
 * Generazione automatica dello slug dal titolo.
 *
 * Sui contenuti nuovi lo slug segue il titolo; su quelli gia pubblicati resta
 * fermo, perche cambiarlo romperebbe i link condivisi e azzererebbe il
 * posizionamento acquisito. Per modificarlo serve una spunta esplicita.
 *
 * Parametri iniziali via attributi data-* sull elemento con x-data: la build
 * CSP di Alpine non permette di passarli come argomenti nel template.
 */

import { component } from './alpine';
export function slugField() {
    return component({
        slug: '',
        follow: true,

        init(): void {
            const element = this.$el as HTMLElement;

            this.slug = element.dataset.slug ?? '';
            this.follow = element.dataset.isNew === '1';
        },

        /** Richiamato all input sul campo titolo. */
        fromTitle(event: Event): void {
            if (!this.follow) {
                return;
            }

            const input = event.target as HTMLInputElement | null;

            if (input) {
                this.slug = slugify(input.value);
            }
        },

        /** L utente modifica lo slug a mano: smettiamo di seguirlo. */
        manual(): void {
            this.follow = false;
            this.slug = slugify(this.slug);
        },

        get isFollowing(): boolean {
            return this.follow;
        },
    });
}

/** Traslitterazione essenziale, allineata a quella lato server. */
function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        // Rimuove i segni diacritici lasciati dalla normalizzazione.
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 190);
}
