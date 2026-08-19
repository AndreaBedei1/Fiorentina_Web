import { defineConfig } from 'vite';
import { resolve } from 'node:path';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';

const HOT_FILE = resolve(process.cwd(), 'public/hot');

/**
 * Scrive public/hot quando il dev server e attivo, cosi il layer PHP
 * (App\Core\View\Vite) sa che deve puntare al dev server invece che al
 * manifest compilato. Il file viene rimosso alla chiusura.
 */
function hotFilePlugin() {
    return {
        name: 'baraonda-hot-file',
        apply: 'serve',
        configureServer(server) {
            const cleanup = () => { if (existsSync(HOT_FILE)) rmSync(HOT_FILE); };
            server.httpServer?.once('listening', () => {
                const address = server.httpServer.address();
                const host = typeof address === 'object' && address ? '127.0.0.1' : 'localhost';
                const port = typeof address === 'object' && address ? address.port : 5173;
                mkdirSync(resolve(process.cwd(), 'public'), { recursive: true });
                writeFileSync(HOT_FILE, `http://${host}:${port}`);
            });
            for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
                process.on(signal, () => { cleanup(); process.exit(); });
            }
            process.on('exit', cleanup);
        },
    };
}

export default defineConfig(({ mode }) => ({
    base: '/assets/',
    plugins: [hotFilePlugin()],
    // I file in resources/static vengono copiati cosi come sono in public/assets:
    // logo, segnaposto e favicon devono restare a un indirizzo stabile, senza
    // hash nel nome, perche vengono referenziati dai template e dal manifest.
    publicDir: resolve(process.cwd(), 'resources/static'),
    resolve: {
        alias: {
            '@': resolve(process.cwd(), 'resources/js'),
            '@css': resolve(process.cwd(), 'resources/css'),
        },
    },
    build: {
        outDir: 'public/assets',
        emptyOutDir: true,
        manifest: true,
        sourcemap: mode === 'development',
        cssCodeSplit: true,
        // Target allineato ai browser supportati (iOS 14+ / Safari 14+).
        target: ['es2020', 'safari14'],
        rollupOptions: {
            input: {
                site: resolve(process.cwd(), 'resources/js/site.ts'),
                admin: resolve(process.cwd(), 'resources/js/admin.ts'),
            },
            output: {
                assetFileNames: 'static/[name]-[hash][extname]',
                chunkFileNames: 'static/[name]-[hash].js',
                entryFileNames: 'static/[name]-[hash].js',
            },
        },
    },
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: false,
        cors: true,
    },
}));
