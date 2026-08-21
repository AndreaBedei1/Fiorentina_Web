/**
 * Editor delle scelte di un prodotto: le taglie, oppure i colori.
 *
 * Righe aggiungibili e rimovibili senza ricaricare la pagina. I campi restano
 * input HTML normali con lo stesso nome: il modulo funziona anche senza
 * JavaScript, semplicemente con le righe gia presenti.
 */

import { component } from './alpine';
interface VariantRow {
    label: string;
    quantity: string;
    available: boolean;
}

export function variantEditor() {
    return component({
        rows: [] as VariantRow[],

        init(): void {
            const element = this.$el as HTMLElement;
            const raw = element.dataset.variants ?? '[]';

            try {
                const parsed = JSON.parse(raw);
                this.rows = Array.isArray(parsed) && parsed.length > 0 ? parsed : [emptyRow()];
            } catch {
                this.rows = [emptyRow()];
            }
        },

        get isEmpty(): boolean {
            return this.rows.length === 0;
        },

        add(): void {
            this.rows.push(emptyRow());

            // Il fuoco va sul campo appena creato: chi inserisce dieci taglie
            // non deve cercare col mouse dove scrivere.
            this.$nextTick(() => {
                const container = this.$refs.rows as HTMLElement | undefined;
                const inputs = container?.querySelectorAll<HTMLInputElement>('input[name="variant_label[]"]');

                inputs?.[inputs.length - 1]?.focus();
            });
        },

        remove(event: Event): void {
            const index = this.indexOf(event);

            if (index >= 0) {
                this.rows.splice(index, 1);
            }

            if (this.rows.length === 0) {
                this.rows.push(emptyRow());
            }
        },

        indexOf(event: Event): number {
            const row = (event.currentTarget as HTMLElement).closest('[data-variant-row]');
            const container = this.$refs.rows as HTMLElement | undefined;

            if (!row || !container) {
                return -1;
            }

            return Array.from(container.querySelectorAll('[data-variant-row]')).indexOf(row);
        },
    });
}

function emptyRow(): VariantRow {
    return {
        label: '',
        quantity: '',
        available: true,
    };
}
