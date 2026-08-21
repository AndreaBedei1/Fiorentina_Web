# Architettura

Come e fatto il sito, perche e fatto cosi, e dove mettere le mani per
modificarlo.

---

## Indice

1. [Il quadro generale](#1-il-quadro-generale)
2. [Ciclo di una richiesta](#2-ciclo-di-una-richiesta)
3. [Moduli](#3-moduli)
4. [Database](#4-database)
5. [Autenticazione e autorizzazione](#5-autenticazione-e-autorizzazione)
6. [Sicurezza](#6-sicurezza)
7. [Immagini e filigrana](#7-immagini-e-filigrana)
8. [Merchandising e ordini](#8-merchandising-e-ordini)
9. [Posta elettronica](#9-posta-elettronica)
10. [Integrazioni esterne](#10-integrazioni-esterne)
11. [Frontend](#11-frontend)
12. [Deployment](#12-deployment)
13. [Scelte tecniche e alternative scartate](#13-scelte-tecniche-e-alternative-scartate)

---

## 1. Il quadro generale

**Monolite modulare** con rendering lato server. Un solo processo PHP, moduli
separati da confini netti, nessun servizio esterno obbligatorio.

```text
                    ┌──────────────────────────────────┐
   Visitatore ────► │  Apache + mod_rewrite            │
                    │  document root = public/         │
                    └───────────────┬──────────────────┘
                                    │
                         public/index.php (front controller)
                                    │
                    ┌───────────────▼──────────────────┐
                    │  Application (kernel)            │
                    │  env → config → container →      │
                    │  rotte → middleware              │
                    └───────────────┬──────────────────┘
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        │                           │                           │
   Controller                  Middleware                  ViewRenderer
   (Site / Admin)         HTTPS, header, CSRF,             (Twig)
        │                 sessione, autenticazione              │
        ▼                                                       ▼
    Service  ──────────────────────────────────────►  HTML in risposta
        │
        ▼
   Repository ────────────────► MySQL 8
        │
        ├──────────────────────► storage/   (originali privati)
        └──────────────────────► public/uploads/ (immagini pubbliche)


   Cron (Aruba)
        │
        ├── sync-football.php ──► FootballService ──► API calcio ──► database
        ├── sync-social.php   ──► SocialService   ──► API Meta   ──► database
        ├── cleanup-tokens.php
        └── cleanup-temp-files.php
```

**Regola di dipendenza.** Le frecce vanno in una sola direzione:

```text
Controller  →  Service  →  Repository  →  Database
```

Un controller non scrive SQL. Un repository non conosce la sessione. Un modello
non sa cosa sia una richiesta HTTP. E il confine che rende testabile la logica e
prevedibile il costo di ogni pagina.

---

## 2. Ciclo di una richiesta

1. **Apache** riscrive tutto su `public/index.php`, tranne i file esistenti
   (immagini, CSS, JavaScript), che serve direttamente.
2. **`Application::boot()`** carica `.env`, la configurazione, imposta fuso
   orario e gestione errori, registra i servizi nel container.
3. **`Request::capture()`** raccoglie le superglobali in un oggetto immutabile.
   Da qui in poi nessuno tocca piu `$_GET` o `$_POST`.
4. **Middleware globali**, in ordine:
   `ForceHttps` → `SecurityHeaders` → `StartSessionGuard` → `Csrf` → `Maintenance`.
5. **Router**: confronta metodo e percorso con le rotte di `routes/`, estrae i
   parametri, esegue gli eventuali middleware di rotta (`admin`, `superadmin`).
6. **Controller**: legge la richiesta, delega ai service, sceglie la risposta.
7. **Service**: la logica vera. Orchestra i repository, applica le regole.
8. **Repository**: l'unico strato che parla con il database.
9. **ViewRenderer**: rende il template Twig.
10. **`Response::send()`**: unico punto del progetto che chiama `header()` e
    stampa il corpo.

Ogni eccezione risale fino a **`ExceptionHandler`**, che decide cosa mostrare:
pagina curata in produzione, pagina diagnostica in sviluppo, JSON per le
richieste asincrone.

---

## 3. Moduli

| Modulo | Dove | Responsabilita |
|---|---|---|
| **Core** | `app/Core/` | container, configurazione, routing, HTTP, database, sessioni, sicurezza di base, view |
| **Contenuti** | `News`, `Event`, `Page` | notizie, eventi, pagine editoriali |
| **Galleria** | `Album`, `Photo`, `Services/Media` | album, upload multiplo, elaborazione, filigrana |
| **Merchandising** | `Product`, `Order`, `Services/Shop` | catalogo, varianti, carrello, ordini |
| **Organizzazione** | `OrganizationRole`, `OrganizationMember` | organigramma del direttivo |
| **Amministrazione** | `User`, `AdminUserService`, `AuditLogger` | account, inviti, permessi, registro |
| **Integrazioni** | `Services/Football`, `Services/Social` | dati esterni, sempre dietro interfaccia |
| **Posta** | `Services/Mail` | email transazionali |

### Il container

Container minimale (~150 righe) con **autowiring per type-hint**: le dipendenze
del costruttore vengono risolte automaticamente. Registrazioni esplicite solo
dove serve configurazione (database, sessione, logger, Twig).

Con circa quaranta servizi, l'autowiring evita centinaia di factory scritte a
mano. Una dipendenza circolare viene intercettata e segnalata con la catena
completa, invece di provocare un errore di memoria esaurita.

### Il routing

Rotte dichiarate in `routes/web.php` e `routes/admin.php`, con gruppi per
prefisso, middleware e prefisso di nome:

```php
$router->get('/notizie/{slug}', [NewsController::class, 'show'])->name('news.show');
$router->get('/notizie/{id:\d+}', [NewsController::class, 'edit']);   // con vincolo
```

Il match e un ciclo lineare, preceduto da una lookup diretta per le rotte senza
parametri (la maggioranza). Con 120 rotte il costo e trascurabile e il codice
resta leggibile.

Gli URL nei template si generano **sempre** per nome:

```twig
<a href="{{ route('news.show', { slug: article.slug }) }}">
```

Cambiare un percorso significa toccare un solo file, e i link restano coerenti.

---

## 4. Database

26 tabelle applicative piu `migrations` (la tracciatura di cosa e gia stato
applicato), 25 chiavi esterne, 101 indici. Schema in `database/migrations/`,
in SQL leggibile.

### Perche migrazioni in SQL e non in PHP

Su Aruba Easy non c'e SSH: l'unico modo pratico per creare lo schema e importare
un dump da phpMyAdmin. Tenendo le migrazioni in SQL, **lo stesso file** serve al
comando locale e all'import manuale. Con un DSL in PHP servirebbero due
rappresentazioni dello stesso schema, e prima o poi si disallineerebbero.

### Gruppi di tabelle

```text
AUTENTICAZIONE
  users, admin_invites, password_reset_tokens,
  login_attempts, rate_limits, audit_logs

CONTENUTI
  news, events, event_categories, pages, page_blocks

GALLERIA
  albums, photos

MERCHANDISING
  product_categories, products, product_images, product_variants,
  orders, order_items, order_status_history, order_sequences

ORGANIZZAZIONE E SISTEMA
  organization_roles, organization_members, site_settings,
  football_matches, social_posts
```

### Scelte ricorrenti

**Soft delete** su contenuti importanti (`users`, `news`, `events`, `albums`,
`products`, `orders`): la riga resta, con `deleted_at` valorizzato. Un ordine
non deve poter sparire per un clic sbagliato, e un amministratore rimosso deve
restare referenziabile dal registro attivita.

**Snapshot negli ordini**: `order_items` copia nome, variante e prezzo al
momento della conferma. Se domani il prodotto cambia prezzo o viene eliminato,
l'ordine storico resta fedele a com'era.

**Contatori denormalizzati**: `albums.photos_count` evita una `COUNT()`
correlata su ogni card di elenco. Viene riallineato a ogni caricamento o
eliminazione, quindi non si disallinea in silenzio.

**Anno duplicato**: `albums.year` affianca `event_date` perche il filtro per
anno diventi un confronto indicizzato invece di una funzione sulla colonna.

**DECIMAL per i prezzi**, mai FLOAT: l'aritmetica binaria produce differenze di
centesimi che si accumulano nei totali.

### Numerazione degli ordini

`BF-2026-000001`. Il contatore vive in `order_sequences`, una riga per anno,
aggiornata con `SELECT ... FOR UPDATE` dentro la transazione di creazione. Con
un semplice `MAX(id)+1`, due ordini inviati nello stesso istante otterrebbero lo
stesso numero.

### Segnaposto ripetuti

Con i prepared statement nativi, MySQL rifiuta lo stesso `:nome` usato due
volte. Scrivere `WHERE titolo LIKE :q OR sommario LIKE :q` e pero il modo
naturale di esprimere quella condizione: `Connection` rinomina automaticamente
le occorrenze successive e duplica il valore. Cosi chi scrivera query in futuro
non incontra una trappola che si manifesta solo quando qualcuno usa quel filtro.

---

## 5. Autenticazione e autorizzazione

**Il sito pubblico non ha utenti.** Nessuna registrazione, nessun profilo,
nessun commento. Gli unici account sono quelli degli amministratori.

### Due ruoli

| | ADMIN | SUPER_ADMIN |
|---|:---:|:---:|
| Contenuti, galleria, prodotti, ordini, pagine | si | si |
| Creare e bloccare amministratori | no | si |
| Impostazioni | no | si |
| Registro attivita | no | si |

Due ruoli e una mappa di permessi in `AuthService`: un sistema di ruoli
configurabili a database sarebbe sovradimensionato per un gruppo che avra tre o
quattro amministratori.

### Come nasce un account

```text
SUPER_ADMIN
    │  crea nome + email + ruolo
    ▼
account in stato "pending", senza password
    │  token casuale da 256 bit
    │  a database va SOLO l'hash SHA-256
    ▼
email con collegamento monouso
    │  la persona sceglie la propria password
    ▼
account attivo
```

Nessuno, nemmeno chi invita, conosce mai la password altrui. Il token e monouso,
scade, e dal database non e ricavabile.

### Come viene verificata una sessione

A **ogni richiesta**, `AuthService::user()` controlla in ordine:

1. presenza dell'identificativo in sessione;
2. coerenza dell'impronta del browser (user agent + `APP_KEY`);
3. timeout di inattivita (`SESSION_LIFETIME`);
4. **esistenza e stato dell'account a database**;
5. `sessions_valid_after`: le sessioni aperte prima di quel momento decadono.

Il punto 4 costa una query in piu per richiesta, ed e quello che rende
**immediato** il blocco di un account. Fidandosi della sola sessione, un
amministratore bloccato resterebbe operativo fino al logout.

Il punto 5 e il meccanismo che, al blocco o al cambio password, chiude tutte le
sessioni aperte altrove.

### Salvaguardie

- non si puo bloccare, declassare o eliminare **l'ultimo super amministratore
  attivo**: il sito resterebbe senza nessuno in grado di gestirlo;
- non si puo agire sul proprio stesso account (blocco ed eliminazione);
- un ADMIN non puo modificare i privilegi di nessuno;
- non esiste `/admin/register`.

L'impronta del browser usa **solo lo user agent, non l'IP**: sulle reti mobili
l'IP cambia di continuo, e includerlo scollegherebbe gli amministratori a meta
lavoro senza un guadagno di sicurezza paragonabile.

---

## 6. Sicurezza

| Rischio | Difesa | Dove |
|---|---|---|
| SQL injection | prepared statement nativi (`EMULATE_PREPARES = false`); identificatori validati | `Core/Database/Connection` |
| XSS | autoescape Twig attivo ovunque; HTML dell'editor sanificato **in scrittura** | `ViewRenderer`, `HtmlSanitizer` |
| CSRF | token di sessione verificato su ogni richiesta non di lettura, a livello globale | `CsrfMiddleware` |
| Session fixation | ID rigenerato a ogni cambio di privilegio | `AuthService::login()` |
| Brute force | limite di tentativi per coppia email+IP, con blocco temporaneo | `RateLimiter` |
| Path traversal | i percorsi si costruiscono solo da chiavi validate da regex | `MediaPaths` |
| Upload di file eseguibili | MIME letto dai magic bytes, decodifica verificata, nome generato dal server | `UploadValidator` |
| IDOR | ogni azione ricontrolla i permessi lato server | `Controller::authorize()` |
| Mass assignment | i repository compongono esplicitamente gli array da salvare | `Repositories/*` |
| Open redirect | i redirect accettano solo percorsi interni | `RedirectResponse::to()` |
| Clickjacking | `frame-ancestors 'self'` e `X-Frame-Options` | `SecurityHeadersMiddleware` |
| SSRF | le chiamate uscenti accettano solo http/https verso host pubblici | `HttpClient` |

### Content-Security-Policy

```text
default-src 'self'; base-uri 'self'; object-src 'none';
frame-ancestors 'self'; form-action 'self';
img-src 'self' data: blob:; font-src 'self' data:;
style-src 'self' 'unsafe-inline'; script-src 'self';
connect-src 'self'
```

Nessuna risorsa esterna e ammessa, perche il progetto non ne carica nessuna:
font di sistema, nessun CDN, miniature social archiviate in locale.

Questa e la policy che vale in produzione. Solo con `APP_ENV=local` vengono
aggiunte le origini del dev server Vite, altrimenti il ricaricamento a caldo
sarebbe bloccato dalla policy stessa.

**Nota su Alpine.** La build standard di Alpine valuta le espressioni dei
template come JavaScript e richiede `unsafe-eval` nella policy. Indebolire la
CSP per una libreria di interfaccia non e un compromesso accettabile: il
progetto usa la **build CSP** (`@alpinejs/csp`), dove i template contengono solo
nomi di proprieta e metodi e tutta la logica vive nei componenti JavaScript.

### Password

`password_hash()` con **Argon2id** quando il runtime lo espone, **bcrypt** come
fallback: Aruba non garantisce Argon2 su tutti i piani, quindi il fallback non e
teorico. `password_needs_rehash()` consente la migrazione trasparente al primo
accesso utile.

Regole in `PasswordPolicy`, condivise fra validatore dei form e script CLI:
almeno 12 caratteri e almeno tre categorie fra minuscole, maiuscole, numeri e
simboli. Nessun requisito barocco di simboli obbligatori, che spinge solo verso
password prevedibili.

### Registro attivita

`audit_logs` registra accessi, tentativi falliti, creazione e blocco di
amministratori, cambi di ruolo, operazioni sui contenuti, cambi di stato degli
ordini, modifiche alle impostazioni.

Email e ruolo dell'autore sono **duplicati nella riga**: il registro deve restare
leggibile anche dopo che l'account e stato rimosso. I metadati passano da un
filtro che sostituisce qualsiasi chiave contenente `password`, `token`, `secret`
o `api_key`, anche se aggiunta per distrazione in futuro.

---

## 7. Immagini e filigrana

### Pipeline

```text
file caricato
     │
     ├─ validazione     dimensione, MIME reale, decodificabilita, megapixel
     ├─ orientamento    normalizzazione EXIF
     ├─ archiviazione   storage/originals/{collezione}/{chiave}.{est}   ← privato
     │
     └─ per ogni misura (thumb 400 / medium 1200 / large 2000):
            ├─ ridimensionamento proporzionale
            ├─ filigrana        (solo medium e large, solo galleria)
            └─ codifica         WebP + JPEG di fallback
                    ↓
            public/uploads/{collezione}/{chiave}-{misura}.{webp|jpg}
```

Sei file pubblici per fotografia. WebP pesa circa il 60% in meno del JPEG a
parita di resa, e il `srcset` lascia scegliere al browser: su rete mobile la
differenza e sostanziale.

### Chiavi di archiviazione

Formato `AAAA/MM/<16 caratteri esadecimali>`, generato dal server.

Il nome non arriva **mai** dal browser. La suddivisione per anno e mese evita
cartelle con decine di migliaia di file. Una sola colonna a database
(`storage_key`) invece di sei percorsi: la convenzione dei nomi vive in un solo
punto, `MediaPaths`.

### Originali privati

Gli originali stanno in `storage/`, fuori dalla cartella web: non sono
scaricabili nemmeno indovinandone il nome. Le versioni pubbliche sono file
statici serviti direttamente da Apache, senza passare da PHP: su hosting
condiviso e la differenza fra una galleria scattante e una lenta.

### Filigrana

Applicata **solo alle copie pubbliche**, mai all'originale: le impostazioni
restano quindi modificabili in qualsiasi momento, e il pulsante *Rielabora
tutte* rigenera un album intero.

Semitrasparente, proporzionale al lato lungo, non applicata alle miniature (dove
coprirebbe il soggetto) ne alle immagini sotto i 600 pixel. Posizione, opacita e
dimensione si regolano dal pannello *Impostazioni*.

### Caricamento multiplo

Il pannello invia i file **a lotti di quattro**, non tutti insieme: con trenta
immagini da 8 megapixel una sola richiesta supererebbe `post_max_size` di un
hosting condiviso e, in caso di errore, farebbe perdere tutto il lavoro. A
lotti, ogni gruppo che riesce e salvato per sempre.

L'avanzamento usa `XMLHttpRequest` e non `fetch`: e l'unico modo per avere la
percentuale reale di caricamento, che con file pesanti fa la differenza fra
un'attesa comprensibile e una pagina che sembra bloccata.

Un file rifiutato non blocca gli altri: il rapporto elenca esattamente cosa non
e stato accettato e perche.

---

## 8. Merchandising e ordini

**Nessun pagamento online.** Non ci sono Stripe, PayPal, carte, CVV o gateway
bancari, e non esiste alcuna colonna che possa contenerne i dati.

```text
Catalogo → Prodotto → Carrello → Dati cliente → Riepilogo
                                                    │
                                          ordine registrato
                                                    │
                          ┌─────────────────────────┴───────────────────┐
                          ▼                                             ▼
        email al responsabile merchandising            email al cliente con
        (numero, cliente, articoli, totale)            istruzioni di pagamento
```

### Carrello

Vive nella sessione e contiene **solo identificativi e quantita**. Prezzi, nomi
e disponibilita si rileggono a ogni richiesta dal database: un prezzo modificato
dall'amministratore vale subito, e nessuno puo manipolare gli importi agendo sui
dati del proprio browser.

Le righe non piu acquistabili (prodotto ritirato, variante esaurita) vengono
segnalate e rimosse quando si apre il carrello, non al momento della conferma.

### Ordine

Creato in **transazione**: numero progressivo, righe e prima voce di storico
compaiono insieme. Un ordine senza articoli, o due ordini con lo stesso numero,
sarebbero entrambi ingestibili per il responsabile.

I totali vengono ricalcolati lato server a partire dai prezzi correnti. L'ordine
viene salvato **prima** di tentare gli invii: se la posta non risponde, l'ordine
esiste comunque ed e visibile nel pannello. Il contrario sarebbe grave.

Otto stati, dal ricevimento alla consegna, con storico di ogni passaggio.

---

## 9. Posta elettronica

Symfony Mailer con tre trasporti, scelti da `MAIL_MAILER`:

| | Comportamento | Uso |
|---|---|---|
| `log` | scrive file `.eml` in `storage/logs/mail/` | sviluppo |
| `smtp` | invio reale | produzione |
| `null` | scarta | test |

I file `.eml` si aprono con qualsiasi client di posta: si verifica l'intero
flusso ordini senza configurare un SMTP e senza rischiare invii a indirizzi
veri.

Ogni messaggio ha una parte HTML e una **testuale**: i client che non mostrano
HTML devono restare leggibili, e i filtri antispam penalizzano le email con la
sola parte HTML.

I template usano layout a tabelle e stili in linea: molti client ignorano il CSS
esterno e Outlook non supporta flexbox ne grid.

Un invio fallito **non fa mai fallire l'azione dell'utente**: viene registrato
nei log e segnalato nell'interfaccia.

---

## 10. Integrazioni esterne

### Il principio

```text
API esterna  →  cron  →  Service  →  database  →  frontend
```

Il frontend legge **solo il database**. Nessuna visita al sito genera una
chiamata esterna. Tre conseguenze: le pagine restano veloci, i limiti di
chiamate del fornitore non si esauriscono, e un disservizio dell'API non
diventa un disservizio del sito.

### Calcio

`FootballApiInterface` espone due soli metodi: prossime partite e risultati
recenti. Piu piccola e l'interfaccia, piu facile e cambiare fornitore.

Implementazioni fornite: `ApiFootballProvider` (api-sports.io, completa) e
`MockFootballProvider` (dati verosimili, sempre disponibile).

Senza chiave API si ricade automaticamente sul fornitore dimostrativo: il sito
resta completo e valutabile prima ancora di scegliere un servizio a pagamento.

Le partite inserite o corrette a mano dal pannello sono marcate `is_manual` e
non vengono piu sovrascritte: altrimenti una correzione durerebbe fino al cron
successivo.

### Social

`SocialProviderInterface` con implementazioni per Instagram Graph API, Facebook
Graph API, YouTube Data API v3 e un fornitore dimostrativo.

Due scelte non ovvie:

1. **Le miniature vengono scaricate e archiviate in locale.** Gli URL dei CDN di
   Meta scadono nel giro di giorni: senza copia locale la homepage si
   riempirebbe di riquadri vuoti. In piu il visitatore non contatta mai i server
   di Meta, il che semplifica anche il fronte cookie e tracciamento.
2. **La sincronizzazione non cancella mai i contenuti esistenti.** Se l'API non
   risponde o restituisce una lista vuota, resta visibile l'ultimo stato buono
   invece di una sezione vuota.

---

## 11. Frontend

### Stack

Twig per i template, Tailwind per lo stile, Alpine (build CSP) per le
interazioni, TypeScript per i moduli, Vite per la compilazione.

Nessun framework a componenti: il sito e reso lato server, e React o Vue
porterebbero centinaia di kilobyte per gestire un menu a scomparsa e una
lightbox.

**Peso degli asset**: circa 12 KB di CSS e 34 KB di JavaScript compressi.
Nessun webfont di terze parti, nessuna richiesta esterna.

### Design system

Tre famiglie cromatiche: **viola** (identita Fiorentina), **rosso** (accento) e
una scala neutra calda che le lega. Ogni accostamento usato nell'interfaccia e
verificato da `scripts/check-contrast.php` contro le soglie WCAG 2.1 AA — lo
script fallisce se qualcosa scende sotto soglia, quindi la conformita non
dipende dalla memoria di chi modifica i colori.

Componenti definiti una volta in `resources/css/`: `.btn-*`, `.campo-*`,
`.badge-*`, `.pannello*`, `.tabella*`. I template li usano invece di ripetere
lunghe stringhe di utility.

### Accessibilita

Non e stata aggiunta alla fine: e nella struttura.

- HTML semantico e punti di riferimento reali (`header`, `nav`, `main`, `footer`);
- un solo `h1` per pagina, gerarchia dei titoli rispettata;
- collegamento "vai al contenuto" come primo elemento focalizzabile;
- focus **sempre visibile**, reso piu evidente del predefinito;
- ogni campo ha etichetta collegata; errori e testi d'aiuto legati via
  `aria-describedby`; campi non validi marcati con `aria-invalid`;
- lightbox con fuoco intrappolato, Esc per chiudere, ritorno del fuoco alla
  miniatura di partenza;
- riordino per trascinamento **sempre affiancato da pulsanti** su/giu;
- `prefers-reduced-motion` disattiva tutte le animazioni, non le rallenta;
- il colore non e mai l'unico veicolo di informazione: badge e voci attive hanno
  sempre anche testo o forma;
- bersagli tattili di almeno 44 pixel;
- ogni immagine ha un testo alternativo, con fallback generato quando
  l'amministratore non lo compila.

### Senza JavaScript

Il sito resta completamente utilizzabile: tutte le pagine sono leggibili, tutti
i moduli inviabili, la galleria apre le immagini a piena pagina. JavaScript
aggiunge comodita, non contenuto.

### Mobile first

Sviluppato partendo da 320 pixel. Attenzione particolare a:

- **calendario**: su schermo stretto la vista predefinita e l'elenco, non la
  griglia mensile, che sarebbe illeggibile;
- **tabelle amministrative**: scorrono orizzontalmente dentro il proprio
  contenitore invece di comprimersi;
- **barra laterale del pannello**: pannello a scomparsa sotto 1024 pixel, con
  fuoco intrappolato e chiusura con Esc.

---

## 12. Deployment

```text
repository
    │
    ├── composer install --no-dev --optimize-autoloader
    ├── npm install && npm run build
    └── php scripts/build-deploy.php
              │
              ▼
        build/deploy/          ~1700 file, ~6,5 MB
              │
              ▼  FTP/FTPS
        Aruba Linux Easy
              │
              ├── .env creato sul server
              ├── schema importato da phpMyAdmin
              ├── permessi 755 su storage/ e public/uploads/
              └── cron configurati dal pannello
```

Lo script di build si rifiuta di procedere se gli asset non sono compilati o se
`vendor/` contiene ancora le dipendenze di sviluppo: sono i due errori piu
facili da commettere e i piu difficili da diagnosticare dopo.

Procedura completa in [DEPLOY_ARUBA.md](DEPLOY_ARUBA.md).

---

## 13. Scelte tecniche e alternative scartate

| Scelta | Alternativa scartata | Perche |
|---|---|---|
| Kernel proprietario | Laravel, Symfony | su hosting condiviso il peso conta; qui il pacchetto da caricare via FTP e di 10 MB invece di 60, e non c'e nulla che richieda comandi di build sul server |
| Twig | PHP nei template | autoescape attivo per default: e la ragione per cui l'XSS non e un rischio diffuso in questo progetto |
| Alpine build CSP | Alpine standard | quella standard richiede `unsafe-eval` nella CSP: indebolire la policy per una libreria di interfaccia non e un compromesso accettabile |
| Migrazioni in SQL | DSL in PHP | senza SSH lo schema si importa da phpMyAdmin: lo stesso file serve a entrambe le strade, e non esistono due versioni che si disallineano |
| Sessioni native su file | Redis, database | nessun demone aggiuntivo, funziona ovunque |
| Rate limiting su database | Redis, APCu | su hosting condiviso APCu non e condiviso fra processi e Redis non c'e |
| Lightbox scritta a mano | libreria esterna | circa 150 righe contro decine di kilobyte, e il controllo completo sulla gestione del fuoco da tastiera, che quasi nessuna libreria fa bene |
| Client HTTP su cURL | Guzzle | poche GET verso API note, eseguite dal cron: una dipendenza con il suo albero di sotto-dipendenze non si giustifica |
| Font di sistema | webfont | zero richieste esterne, zero consenso cookie aggiuntivo, tempo di visualizzazione piu rapido |
| Miniature social in locale | hotlink ai CDN Meta | gli URL scadono; e cosi il visitatore non contatta mai server di terze parti |
| Due ruoli fissi | permessi configurabili | il gruppo avra tre o quattro amministratori: un sistema di ruoli a database sarebbe complessita senza ritorno |
| Snapshot nelle righe d'ordine | join sul prodotto | un ordine deve restare leggibile com'era anche se il prodotto cambia o sparisce |
| Nessun page builder | editor a blocchi libero | un costruttore libero permette di scomporre il layout: chi eredita il sito si troverebbe a rimetterlo a posto |

### Cosa e stato aggiunto oltre alla specifica

- **`scripts/check-contrast.php`** — verifica automatica dei contrasti WCAG.
  La combinazione viola/rosso/bianco richiesta e insidiosa: senza un controllo
  meccanico, la conformita dipenderebbe dalla memoria di chi tocca i colori.
  Ha gia individuato due accostamenti sotto soglia durante lo sviluppo.
- **`scripts/build-deploy.php`** — artefatto di produzione riproducibile, con
  controlli preliminari sugli errori piu facili da commettere.
- **`scripts/generate-placeholders.php`** — genera logo, favicon e filigrana
  segnaposto con GD: nessun file binario di provenienza incerta nel repository,
  e i segnaposto sono chiaramente riconoscibili come tali.
- **`PasswordPolicy`** — regole sulle password in un solo punto, condivise fra
  validatore dei form e script CLI, cosi non possono divergere.
- **Espansione automatica dei segnaposto SQL ripetuti** — rimuove una trappola
  che si manifesta solo quando qualcuno usa un filtro di ricerca, cioe spesso
  solo in produzione.
