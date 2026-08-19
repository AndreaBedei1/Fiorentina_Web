/**
 * Caricamento multiplo di fotografie con trascinamento e barra di avanzamento.
 *
 * I file vengono inviati a lotti anziche tutti insieme: con trenta immagini da
 * 8 megapixel una sola richiesta supererebbe i limiti di `post_max_size` di un
 * hosting condiviso e, in caso di errore, farebbe perdere tutto il lavoro. A
 * lotti, ogni gruppo che riesce e salvato per sempre.
 *
 * XMLHttpRequest e non fetch: e l'unico modo per avere l'avanzamento reale del
 * caricamento, che con file pesanti e su rete mobile fa la differenza fra
 * un'attesa comprensibile e una pagina che sembra bloccata.
 */

import { component } from './alpine';

interface UploadResponse {
    ok: boolean;
    uploaded?: number;
    message?: string;
    errors?: string[];
}

export function photoUploader() {
    return component({
        endpoint: '',
        csrfToken: '',
        maxBytes: 16 * 1024 * 1024,

        /** File in coda, gia filtrati. */
        queue: [] as File[],
        dragging: false,
        uploading: false,
        progress: 0,
        uploadedCount: 0,
        errors: [] as string[],
        done: false,

        /** Numero di file per lotto: compromesso fra velocita e robustezza. */
        batchSize: 4,

        init(): void {
            // Con la build CSP di Alpine i parametri non passano dal template:
            // arrivano dagli attributi data-* dell elemento con x-data.
            const element = this.$el as HTMLElement;

            this.endpoint = element.dataset.endpoint ?? '';
            this.csrfToken = element.dataset.token ?? '';
            this.maxBytes = Number(element.dataset.maxBytes ?? '') || 16 * 1024 * 1024;
        },

        get hasQueue(): boolean {
            return this.queue.length > 0;
        },

        get hasErrors(): boolean {
            return this.errors.length > 0;
        },

        get isIdle(): boolean {
            return !this.uploading;
        },

        get progressStyle(): string {
            return 'width: ' + this.progress + '%';
        },

        get progressLabel(): string {
            return this.progress + '%';
        },

        /** Classi dell area di trascinamento quando un file la sorvola. */
        get dropZoneClass(): string {
            return this.dragging ? 'border-viola-500 bg-viola-50' : '';
        },

        get queueLabel(): string {
            return this.queue.length === 1
                ? '1 file pronto (' + this.totalSize() + ')'
                : this.queue.length + ' file pronti (' + this.totalSize() + ')';
        },

        onDragOver(event: DragEvent): void {
            event.preventDefault();
            this.dragging = true;
        },

        onDragLeave(): void {
            this.dragging = false;
        },

        onDrop(event: DragEvent): void {
            event.preventDefault();
            this.dragging = false;

            const files = event.dataTransfer?.files;

            if (files) {
                this.addFiles(Array.from(files));
            }
        },

        onSelect(event: Event): void {
            const input = event.target as HTMLInputElement;

            if (input.files) {
                this.addFiles(Array.from(input.files));
            }

            // Azzera il campo: senza, riselezionare lo stesso file non
            // scatenerebbe un nuovo evento change.
            input.value = '';
        },

        /**
         * Filtra i file scartati subito, senza consumare banda.
         * La validazione vera resta comunque lato server.
         */
        addFiles(files: File[]): void {
            this.done = false;

            files.forEach((file) => {
                if (!file.type.startsWith('image/')) {
                    this.errors.push(`"${file.name}" non e un'immagine.`);

                    return;
                }

                if (file.size > this.maxBytes) {
                    this.errors.push(
                        `"${file.name}" pesa ${formatBytes(file.size)}: il limite e ${formatBytes(this.maxBytes)}.`,
                    );

                    return;
                }

                const duplicate = this.queue.some(
                    (queued) => queued.name === file.name && queued.size === file.size,
                );

                if (!duplicate) {
                    this.queue.push(file);
                }
            });
        },

        removeFile(event: Event): void {
            const index = Number((event.currentTarget as HTMLElement).dataset.index ?? '-1');

            if (index >= 0) {
                this.queue.splice(index, 1);
            }
        },

        clear(): void {
            this.queue = [];
            this.errors = [];
            this.uploadedCount = 0;
            this.progress = 0;
            this.done = false;
        },

        totalSize(): string {
            return formatBytes(this.queue.reduce((total, file) => total + file.size, 0));
        },

        async upload(): Promise<void> {
            if (this.uploading || this.queue.length === 0) {
                return;
            }

            this.uploading = true;
            this.errors = [];
            this.uploadedCount = 0;
            this.progress = 0;
            this.done = false;

            const batches: File[][] = [];

            for (let i = 0; i < this.queue.length; i += this.batchSize) {
                batches.push(this.queue.slice(i, i + this.batchSize));
            }

            const totalFiles = this.queue.length;
            let completedFiles = 0;

            for (const batch of batches) {
                try {
                    const result = await this.sendBatch(batch, (batchProgress) => {
                        // Avanzamento complessivo: lotti gia conclusi piu la
                        // frazione in corso.
                        this.progress = Math.round(
                            ((completedFiles + batch.length * batchProgress) / totalFiles) * 100,
                        );
                    });

                    this.uploadedCount += result.uploaded ?? 0;

                    if (result.errors && result.errors.length > 0) {
                        this.errors.push(...result.errors);
                    }
                } catch (error) {
                    this.errors.push(
                        error instanceof Error
                            ? error.message
                            : 'Caricamento non riuscito: controlla la connessione.',
                    );
                }

                completedFiles += batch.length;
                this.progress = Math.round((completedFiles / totalFiles) * 100);
            }

            this.uploading = false;
            this.queue = [];
            this.done = true;

            // Ricarichiamo la pagina solo se qualcosa e stato salvato: cosi le
            // nuove fotografie compaiono nell'elenco, ma gli eventuali errori
            // restano leggibili quando non c'e nulla da mostrare.
            if (this.uploadedCount > 0 && this.errors.length === 0) {
                window.setTimeout(() => window.location.reload(), 900);
            }
        },

        sendBatch(files: File[], onProgress: (ratio: number) => void): Promise<UploadResponse> {
            return new Promise((resolve, reject) => {
                const body = new FormData();
                body.append('_token', this.csrfToken);
                files.forEach((file) => body.append('photos[]', file, file.name));

                const request = new XMLHttpRequest();
                request.open('POST', this.endpoint, true);
                request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                request.withCredentials = true;

                request.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        onProgress(event.loaded / event.total);
                    }
                });

                request.addEventListener('load', () => {
                    try {
                        const payload = JSON.parse(request.responseText) as UploadResponse;
                        resolve(payload);
                    } catch {
                        reject(new Error('Risposta del server non leggibile.'));
                    }
                });

                request.addEventListener('error', () => reject(new Error('Errore di rete durante il caricamento.')));
                request.addEventListener('abort', () => reject(new Error('Caricamento interrotto.')));

                request.send(body);
            });
        },
    });
}

function formatBytes(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
    }

    return `${Math.round(bytes / 1024)} KB`;
}
