/**
 * Dichiarazioni di tipo per i pacchetti Alpine privi di tipi propri.
 *
 * I plugin (csp, collapse, focus) sono distribuiti senza file .d.ts: senza
 * queste dichiarazioni TypeScript rifiuta gli import in modalita strict.
 */

declare module '@alpinejs/csp' {
    /** Superficie effettivamente usata dal progetto. */
    interface AlpineCsp {
        data(name: string, callback: () => object): void;
        plugin(plugin: unknown): void;
        start(): void;
        store(name: string, value?: unknown): unknown;
        magic(name: string, callback: (element: HTMLElement) => unknown): void;
    }

    const Alpine: AlpineCsp;

    export default Alpine;
}

declare module '@alpinejs/collapse' {
    const plugin: unknown;

    export default plugin;
}

declare module '@alpinejs/focus' {
    const plugin: unknown;

    export default plugin;
}
