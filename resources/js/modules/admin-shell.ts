/**
 * Struttura del pannello: barra laterale e menu mobile.
 *
 * La preferenza di apertura viene ricordata in localStorage: chi lavora al
 * pannello lo apre decine di volte al giorno e non deve richiudere la stessa
 * cosa ogni volta.
 */

import { component } from './alpine';
const STORAGE_KEY = 'baraonda.admin.sidebar';

export function adminShell() {
    return component({
        sidebarOpen: false,

        init(): void {
            try {
                if (window.localStorage.getItem(STORAGE_KEY) === '1' && window.innerWidth >= 1024) {
                    this.sidebarOpen = false;
                }
            } catch {
                // localStorage puo essere disabilitato: manteniamo il default.
            }

            window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
                if (event.matches) {
                    this.sidebarOpen = false;
                }
            });
        },

        get expanded(): string {
            return this.sidebarOpen ? 'true' : 'false';
        },

        /**
         * Stato della barra laterale come attributo.
         * Su desktop la regola CSS la mostra comunque, qualunque sia il valore.
         */
        get openAttr(): string {
            return this.sidebarOpen ? 'true' : 'false';
        },

        get isOpen(): boolean {
            return this.sidebarOpen;
        },

        get isClosed(): boolean {
            return !this.sidebarOpen;
        },

        toggleSidebar(): void {
            this.sidebarOpen = !this.sidebarOpen;
        },

        closeSidebar(): void {
            this.sidebarOpen = false;
        },
    });
}
