/**
 * Supporto ai tipi dei componenti Alpine.
 *
 * Alpine inietta nei componenti alcune proprieta "magiche" ($el, $refs,
 * $nextTick...). Senza dichiararle, TypeScript in modalita strict le segnala
 * come inesistenti a ogni utilizzo.
 *
 * `component()` non fa nulla a runtime: serve solo a dire al compilatore che
 * dentro i metodi `this` comprende anche quelle proprieta.
 */

export interface AlpineMagics {
    /** Elemento su cui e dichiarato x-data (o quello che ha scatenato l evento). */
    $el: HTMLElement;

    /** Elementi marcati con x-ref all interno del componente. */
    $refs: Record<string, HTMLElement | undefined>;

    /** Esegue la callback dopo il prossimo aggiornamento del DOM. */
    $nextTick(callback: () => void): void;

    /** Emette un evento personalizzato che risale nel DOM. */
    $dispatch(event: string, detail?: unknown): void;

    /** Osserva una proprieta del componente. */
    $watch(property: string, callback: (value: unknown, previous: unknown) => void): void;
}

/**
 * Dichiara un componente Alpine tipizzato.
 *
 * @example
 *   export function contatore() {
 *       return component({
 *           valore: 0,
 *           incrementa() { this.valore += 1; },
 *       });
 *   }
 */
export function component<T extends object>(definition: T & ThisType<T & AlpineMagics>): T {
    return definition;
}
