/**
 * Riordino per trascinamento, con alternativa da tastiera.
 *
 * Usa l API nativa di drag and drop (nessuna libreria) e affianca due pulsanti
 * su/giu per ogni elemento: il trascinamento da solo escluderebbe chi naviga da
 * tastiera o usa uno screen reader.
 *
 * Endpoint e token arrivano dagli attributi data-* dell elemento con x-data.
 */

import { component } from './alpine';
export function sortableList() {
    return component({
        endpoint: '',
        token: '',
        dragIndex: null as number | null,
        saving: false,
        message: '',

        init(): void {
            const element = this.$el as HTMLElement;

            this.endpoint = element.dataset.endpoint ?? '';
            this.token = element.dataset.token ?? '';
        },

        get hasMessage(): boolean {
            return this.message !== '';
        },

        get isSaving(): boolean {
            return this.saving;
        },

        onDragStart(event: DragEvent): void {
            const item = (event.target as HTMLElement).closest('[data-sortable-item]');

            this.dragIndex = item ? this.items().indexOf(item as HTMLElement) : null;

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(this.dragIndex));
            }
        },

        onDragOver(event: DragEvent): void {
            event.preventDefault();

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
        },

        onDrop(event: DragEvent): void {
            event.preventDefault();

            const item = (event.target as HTMLElement).closest('[data-sortable-item]');
            const target = item ? this.items().indexOf(item as HTMLElement) : -1;

            if (this.dragIndex === null || target < 0 || this.dragIndex === target) {
                return;
            }

            this.move(this.dragIndex, target);
            this.dragIndex = null;
        },

        /** Spostamento da tastiera: stesso effetto del trascinamento. */
        moveUp(event: Event): void {
            const index = this.indexOfButton(event);

            if (index > 0) {
                this.move(index, index - 1);
            }
        },

        moveDown(event: Event): void {
            const index = this.indexOfButton(event);

            if (index >= 0 && index < this.items().length - 1) {
                this.move(index, index + 1);
            }
        },

        indexOfButton(event: Event): number {
            const item = (event.currentTarget as HTMLElement).closest('[data-sortable-item]');

            return item ? this.items().indexOf(item as HTMLElement) : -1;
        },

        items(): HTMLElement[] {
            const container = this.$refs.list as HTMLElement | undefined;

            return container
                ? Array.from(container.querySelectorAll<HTMLElement>('[data-sortable-item]'))
                : [];
        },

        move(from: number, to: number): void {
            const items = this.items();

            if (!items[from] || !items[to]) {
                return;
            }

            if (from < to) {
                items[to].after(items[from]);
            } else {
                items[to].before(items[from]);
            }

            void this.persist();
        },

        async persist(): Promise<void> {
            const order = this.items()
                .map((item) => item.dataset.id)
                .filter((id): id is string => typeof id === 'string');

            if (order.length === 0 || this.endpoint === '') {
                return;
            }

            this.saving = true;
            this.message = '';

            try {
                const body = new FormData();
                body.append('_token', this.token);
                order.forEach((id) => body.append('order[]', id));

                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                const payload = await response.json();
                this.message = payload.message ?? (response.ok ? 'Ordine salvato.' : 'Salvataggio non riuscito.');
            } catch {
                this.message = 'Salvataggio non riuscito: controlla la connessione.';
            } finally {
                this.saving = false;
            }
        },
    });
}
