/**
 * Editor del testo delle notizie.
 *
 * Chi scrive di una trasferta non deve sapere cosa sia un tag. Qui il testo si
 * compone in un riquadro normale e i pulsanti in alto fanno quello che dicono:
 * "Titolo" ingrandisce, "Grassetto" ingrassa, "Elenco" mette i puntini.
 *
 * Sotto, il riquadro e un elemento contenteditable e produce HTML; a ogni
 * modifica lo copiamo nella casella di testo che il modulo invia davvero. Il
 * filtro vero resta comunque a server: HtmlSanitizer tiene solo i tag ammessi,
 * quindi anche se il browser inventasse markup strano non arriverebbe intatto
 * a database.
 *
 * Senza JavaScript il riquadro non compare e resta la casella di testo
 * originale: si scrive in HTML come prima, e il modulo funziona lo stesso.
 */

import { component } from './alpine';

export function editorTesto() {
    return component({
        /** Ultima porzione di testo selezionata dentro il foglio. */
        intervallo: null as Range | null,

        init(): void {
            const foglio = this.foglio();
            const sorgente = this.sorgente();

            if (!foglio || !sorgente) {
                return;
            }

            // Il browser deve produrre <p> e <strong>, non <div> e <span
            // style>: il primo e leggibile, il secondo verrebbe scartato dal
            // sanitizzatore e il grassetto sparirebbe al salvataggio.
            try {
                document.execCommand('defaultParagraphSeparator', false, 'p');
                document.execCommand('styleWithCSS', false, 'false');
            } catch {
                // Browser che non lo consentono: si prosegue lo stesso.
            }

            foglio.innerHTML = sorgente.value.trim() === '' ? '<p><br></p>' : sorgente.value;
            sorgente.setAttribute('hidden', 'hidden');
            this.$refs.barra?.removeAttribute('hidden');

            // Premendo un pulsante il fuoco lascia il testo e la selezione
            // svanisce: teniamone una copia mentre c'e ancora.
            document.addEventListener('selectionchange', () => this.ricorda());

            this.aggiorna();
        },

        /**
         * Il riquadro e la casella si prendono da x-ref, non da $el.
         *
         * Dentro un gestore di evento Alpine assegna a $el l'elemento che ha
         * scatenato l'evento - il pulsante della barra - non la radice del
         * componente: cercando li dentro non si troverebbe nulla e il testo
         * scritto non arriverebbe mai al campo che viene inviato.
         */
        foglio(): HTMLElement | null {
            return this.$refs.foglio ?? null;
        },

        sorgente(): HTMLTextAreaElement | null {
            return (this.$refs.sorgente as HTMLTextAreaElement | undefined) ?? null;
        },

        /** Annota dove si trova il cursore, finche e ancora nel foglio. */
        ricorda(): void {
            const foglio = this.foglio();
            const selezione = window.getSelection();

            if (!foglio || !selezione || selezione.rangeCount === 0) {
                return;
            }

            const intervallo = selezione.getRangeAt(0);

            if (foglio.contains(intervallo.commonAncestorContainer)) {
                this.intervallo = intervallo.cloneRange();
            }
        },

        /** Rimette il cursore dove stava prima del clic sul pulsante. */
        ripristina(): void {
            const foglio = this.foglio();

            if (!foglio) {
                return;
            }

            foglio.focus();

            if (this.intervallo === null) {
                return;
            }

            const selezione = window.getSelection();

            selezione?.removeAllRanges();
            selezione?.addRange(this.intervallo);
        },

        /** Copia il contenuto nella casella che viene inviata. */
        aggiorna(): void {
            const foglio = this.foglio();
            const sorgente = this.sorgente();

            if (!foglio || !sorgente) {
                return;
            }

            const html = foglio.innerHTML.trim();

            sorgente.value = html === '<p><br></p>' || html === '<br>' ? '' : html;
        },

        /** Impedisce al pulsante di rubare il fuoco al testo. */
        trattieni(): void {
            // Il solo modificatore .prevent sull'evento fa il lavoro: qui non
            // serve altro, ma Alpine vuole comunque un metodo da chiamare.
        },

        /** Esegue il comando indicato dal pulsante premuto. */
        applica(event: Event): void {
            const pulsante = (event.currentTarget as HTMLElement | null)?.closest<HTMLElement>('[data-comando]');
            const comando = pulsante?.dataset.comando;

            if (comando === undefined) {
                return;
            }

            this.ripristina();

            switch (comando) {
                case 'titolo':
                    document.execCommand('formatBlock', false, 'h2');
                    break;
                case 'sottotitolo':
                    document.execCommand('formatBlock', false, 'h3');
                    break;
                case 'paragrafo':
                    document.execCommand('formatBlock', false, 'p');
                    break;
                case 'grassetto':
                    document.execCommand('bold');
                    break;
                case 'corsivo':
                    document.execCommand('italic');
                    break;
                case 'elencoPuntato':
                    document.execCommand('insertUnorderedList');
                    break;
                case 'elencoNumerato':
                    document.execCommand('insertOrderedList');
                    break;
            }

            this.ricorda();
            this.aggiorna();
        },

        /** Incollare da Word o da una pagina web porta con se formattazione inutile. */
        incolla(event: ClipboardEvent): void {
            event.preventDefault();

            const testo = event.clipboardData?.getData('text/plain') ?? '';

            document.execCommand('insertText', false, testo);
            this.aggiorna();
        },
    });
}
