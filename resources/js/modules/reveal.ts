/**
 * Comparsa progressiva degli elementi allo scorrimento.
 *
 * Usa IntersectionObserver (nessun listener sullo scorrimento, nessun calcolo
 * di posizione a ogni frame) e smette di osservare un elemento appena si e
 * mostrato: l'animazione avviene una volta sola.
 *
 * Con "movimento ridotto" attivo, o senza IntersectionObserver, tutto viene
 * mostrato subito: il contenuto non deve mai dipendere dall'animazione.
 */
export function initReveal(): void {
    const elements = Array.from(document.querySelectorAll<HTMLElement>('[data-reveal]'));

    if (elements.length === 0) {
        return;
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        elements.forEach((element) => element.classList.add('is-visible'));

        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        },
        {
            // Anticipa leggermente la comparsa: l'elemento e gia visibile
            // quando l'utente ci arriva, invece di animarsi sotto i suoi occhi.
            rootMargin: '0px 0px -10% 0px',
            threshold: 0.05,
        },
    );

    elements.forEach((element) => observer.observe(element));
}
