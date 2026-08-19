/**
 * Entrypoint dell'area amministrativa.
 *
 * Interfaccia separata da quella pubblica: il pannello ha bisogno di upload
 * multiplo, riordino per trascinamento e conferme per le azioni distruttive,
 * mentre il sito pubblico non deve pagare il peso di questo codice.
 *
 * Come il sito, usa la build CSP di Alpine: nessun 'unsafe-eval' nella policy.
 */

import Alpine from '@alpinejs/csp';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

import '../css/admin.css';
import { adminShell } from './modules/admin-shell';
import { photoUploader } from './modules/photo-uploader';
import { confirmAction } from './modules/confirm';
import { slugField } from './modules/slug-field';
import { sortableList } from './modules/sortable';
import { variantEditor } from './modules/variant-editor';

declare global {
    interface Window {
        Alpine: typeof Alpine;
    }
}

document.documentElement.classList.add('js');

Alpine.plugin(collapse);
Alpine.plugin(focus);

Alpine.data('adminShell', adminShell);
Alpine.data('photoUploader', photoUploader);
Alpine.data('confirmAction', confirmAction);
Alpine.data('slugField', slugField);
Alpine.data('sortableList', sortableList);
Alpine.data('variantEditor', variantEditor);

window.Alpine = Alpine;
Alpine.start();
