/**
 * Lightbox accessibile per la galleria fotografica.
 *
 * Scritta a mano invece di usare una libreria: servono circa 150 righe, mentre
 * i pacchetti diffusi ne pesano decine di kilobyte e quasi nessuno gestisce
 * bene il fuoco da tastiera. Qui abbiamo il controllo su tutto:
 *
 *  - il fuoco entra nella finestra e non ne esce finche resta aperta;
 *  - Esc chiude, le frecce navigano, il fuoco torna alla miniatura di partenza;
 *  - lo sfondo non scorre mentre la finestra e aperta;
 *  - senza JavaScript i collegamenti restano validi e aprono l'immagine intera.
 */

interface LightboxItem {
    src: string;
    alt: string;
    caption: string;
}

export function initLightbox(): void {
    const triggers = Array.from(document.querySelectorAll<HTMLAnchorElement>('[data-lightbox]'));

    if (triggers.length === 0) {
        return;
    }

    const items: LightboxItem[] = triggers.map((trigger) => ({
        src: trigger.getAttribute('href') ?? '',
        alt: trigger.dataset.alt ?? '',
        caption: trigger.dataset.caption ?? '',
    }));

    let currentIndex = 0;
    let lastFocused: HTMLElement | null = null;

    // --- Costruzione della finestra (una sola volta, riutilizzata) ---------
    const overlay = document.createElement('div');
    overlay.className =
        'fixed inset-0 z-[200] hidden items-center justify-center bg-viola-950/95 p-4 backdrop-blur-sm';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Visualizzazione ingrandita della fotografia');

    overlay.innerHTML = `
        <button type="button" data-lb-close
                class="absolute right-3 top-3 flex h-11 w-11 items-center justify-center rounded-lg bg-white/10 text-white transition hover:bg-white/20 focus-ring-light"
                aria-label="Chiudi">
            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>

        <button type="button" data-lb-prev
                class="absolute left-2 top-1/2 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-lg bg-white/10 text-white transition hover:bg-white/20 focus-ring-light sm:left-4"
                aria-label="Fotografia precedente">
            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        </button>

        <button type="button" data-lb-next
                class="absolute right-2 top-1/2 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-lg bg-white/10 text-white transition hover:bg-white/20 focus-ring-light sm:right-4"
                aria-label="Fotografia successiva">
            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </button>

        <figure class="flex max-h-full max-w-5xl flex-col items-center gap-3">
            <!-- Pixel trasparente e non src="": con src vuoto il browser
                 richiederebbe di nuovo la pagina corrente a ogni caricamento. -->
            <img data-lb-image src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt=""
                 class="max-h-[75vh] w-auto max-w-full rounded-lg object-contain shadow-2xl">
            <figcaption class="text-center text-sm text-viola-100">
                <span data-lb-caption class="block"></span>
                <span data-lb-counter class="mt-1 block text-xs text-viola-300"></span>
            </figcaption>
        </figure>
    `;

    document.body.appendChild(overlay);

    const image = overlay.querySelector<HTMLImageElement>('[data-lb-image]')!;
    const caption = overlay.querySelector<HTMLElement>('[data-lb-caption]')!;
    const counter = overlay.querySelector<HTMLElement>('[data-lb-counter]')!;
    const closeButton = overlay.querySelector<HTMLButtonElement>('[data-lb-close]')!;
    const prevButton = overlay.querySelector<HTMLButtonElement>('[data-lb-prev]')!;
    const nextButton = overlay.querySelector<HTMLButtonElement>('[data-lb-next]')!;

    const singleItem = items.length < 2;
    prevButton.hidden = singleItem;
    nextButton.hidden = singleItem;

    function show(index: number): void {
        currentIndex = (index + items.length) % items.length;
        const item = items[currentIndex];

        image.src = item.src;
        image.alt = item.alt;
        caption.textContent = item.caption;
        counter.textContent = `${currentIndex + 1} di ${items.length}`;
    }

    function open(index: number): void {
        lastFocused = document.activeElement as HTMLElement | null;

        show(index);
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');

        // Blocca lo scorrimento della pagina sottostante.
        document.body.style.overflow = 'hidden';

        closeButton.focus();
        document.addEventListener('keydown', onKeydown);
    }

    function close(): void {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKeydown);

        // Il fuoco torna dove era: chi naviga da tastiera riprende dal punto
        // esatto in cui si trovava, senza ripartire dall'inizio della pagina.
        lastFocused?.focus();
    }

    function onKeydown(event: KeyboardEvent): void {
        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                close();
                break;
            case 'ArrowLeft':
                if (!singleItem) {
                    event.preventDefault();
                    show(currentIndex - 1);
                }
                break;
            case 'ArrowRight':
                if (!singleItem) {
                    event.preventDefault();
                    show(currentIndex + 1);
                }
                break;
            case 'Tab': {
                // Trappola del fuoco: senza, il tasto Tab porterebbe dietro la
                // finestra, su elementi che l'utente non puo vedere.
                const focusable = Array.from(
                    overlay.querySelectorAll<HTMLElement>('button:not([hidden])'),
                );

                if (focusable.length === 0) {
                    return;
                }

                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }

                break;
            }
            default:
                break;
        }
    }

    triggers.forEach((trigger, index) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            open(index);
        });
    });

    closeButton.addEventListener('click', close);
    prevButton.addEventListener('click', () => show(currentIndex - 1));
    nextButton.addEventListener('click', () => show(currentIndex + 1));

    // Un clic sullo sfondo chiude; un clic sull'immagine no.
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });
}
