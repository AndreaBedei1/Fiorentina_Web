import { component } from './alpine';

/**
 * Modulo di conferma ordine.
 *
 * Unica interazione: mostrare i campi dell indirizzo solo quando si sceglie la
 * spedizione, e aggiornare la voce "spedizione" nel riepilogo. Con il ritiro in
 * sede l indirizzo non serve, e chiederlo sarebbe un ostacolo inutile.
 *
 * Senza JavaScript i campi restano tutti visibili e il modulo funziona lo
 * stesso: la validazione dell indirizzo avviene comunque lato server, in base
 * al metodo di consegna scelto.
 */
export function checkoutForm() {
    return component({
        method: 'delivery',
        deliveryCost: '',
        pickupCost: 'gratuita',

        init(): void {
            const element = this.$el as HTMLElement;

            this.method = element.dataset.method ?? 'delivery';
            this.deliveryCost = element.dataset.deliveryCost ?? '';
            this.pickupCost = element.dataset.pickupCost ?? 'gratuita';
        },

        get isDelivery(): boolean {
            return this.method === 'delivery';
        },

        /** Etichetta della spedizione nel riepilogo. */
        get shippingLabel(): string {
            return this.isDelivery ? this.deliveryCost : this.pickupCost;
        },

        onMethodChange(event: Event): void {
            const input = event.target as HTMLInputElement | null;

            if (input) {
                this.method = input.value;
            }
        },
    });
}
