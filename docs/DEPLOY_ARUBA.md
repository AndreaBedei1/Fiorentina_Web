# Deploy su Aruba Linux Easy

Procedura completa per pubblicare il sito su un hosting condiviso Aruba, dal
momento in cui il piano viene acquistato fino al sito online.

Il progetto e stato costruito fin dall'inizio per questo scenario: nessun
Docker, nessun Redis, nessun processo Node, nessun demone custom, nessun
accesso SSH necessario.

---

## Indice

1. [Prima di iniziare](#1-prima-di-iniziare)
2. [Configurazione PHP](#2-configurazione-php)
3. [Database MySQL](#3-database-mysql)
4. [Preparare l'artefatto in locale](#4-preparare-lartefatto-in-locale)
5. [Caricamento via FTP/FTPS](#5-caricamento-via-ftpftps)
6. [Document root](#6-document-root)
7. [Il file .env](#7-il-file-env)
8. [Import dello schema del database](#8-import-dello-schema-del-database)
9. [Permessi delle cartelle](#9-permessi-delle-cartelle)
10. [HTTPS](#10-https)
11. [Impostazioni del sito](#11-impostazioni-del-sito)
12. [Posta elettronica](#12-posta-elettronica)
13. [Primo amministratore](#13-primo-amministratore)
14. [Cron](#14-cron)
15. [Verifica finale](#15-verifica-finale)
16. [Aggiornamenti successivi](#16-aggiornamenti-successivi)
17. [Backup](#17-backup)
18. [Problemi frequenti](#18-problemi-frequenti)

---

## 1. Prima di iniziare

Cosa serve avere sottomano:

- credenziali del **pannello di controllo Aruba**;
- credenziali **FTP/FTPS** (le trovi nel pannello, sezione *Hosting*);
- un client FTP che supporti **FTPS esplicito**: FileZilla, WinSCP o Cyberduck;
- il dominio gia puntato sull'hosting.

> **Sul piano Easy non c'e accesso SSH.** La procedura qui sotto e scritta per
> funzionare interamente da pannello e FTP. Dove un comando da riga di comando
> renderebbe le cose piu semplici, e indicata anche l'alternativa manuale.

---

## 2. Configurazione PHP

Dal pannello Aruba, sezione **Hosting Linux → Configurazione PHP**:

| Impostazione | Valore |
|---|---|
| Versione PHP | **8.2** o superiore |
| `memory_limit` | 256M (o il massimo consentito) |
| `upload_max_filesize` | 32M |
| `post_max_size` | 128M |
| `max_execution_time` | 300 |
| `max_file_uploads` | 60 |
| `display_errors` | **Off** |

Le ultime quattro servono al caricamento multiplo delle fotografie: con i valori
predefiniti, caricare venti immagini da smartphone fallisce a meta senza un
messaggio chiaro.

**Estensioni da verificare come attive** (di norma lo sono):
`pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `gd` oppure `imagick`, `openssl`,
`sodium`, `exif`, `opcache`.

Se sono disponibili **sia GD sia ImageMagick**, imposta `IMAGE_DRIVER=imagick`
nel file `.env`: la resa della filigrana e migliore. Con `auto` il sito sceglie
Imagick quando c'e e GD altrimenti, quindi puoi anche lasciarlo com'e.

---

## 3. Database MySQL

Dal pannello, sezione **Database MySQL**:

1. crea un nuovo database (annota **nome, host, utente e password**: l'host
   Aruba non e `localhost`, e qualcosa come `31.11.xxx.xxx` o
   `sql.tuodominio.it`);
2. imposta la codifica su **utf8mb4** se il pannello lo consente;
3. abilita l'accesso a **phpMyAdmin**, che servira per l'import dello schema.

> Aruba assegna un solo database per piano. Il progetto ne usa uno soltanto: il
> secondo (`baraonda_test`) serve unicamente ai test in locale e non va creato
> in produzione.

---

## 4. Preparare l'artefatto in locale

Sul tuo computer, nella cartella del progetto:

```bash
# 1. Dipendenze PHP senza quelle di sviluppo
composer install --no-dev --optimize-autoloader

# 2. Asset compilati
npm install
npm run build

# 3. Artefatto pronto da caricare
php scripts/build-deploy.php
```

Il risultato e in **`build/deploy/`**. Contiene codice, `vendor/`, template,
asset compilati, migrazioni e cartelle di lavoro vuote.

**Non contiene** — di proposito:

- il file `.env` (lo crei sul server, punto 7);
- `node_modules/` e i sorgenti CSS/JS;
- i test e gli script di sviluppo;
- i contenuti caricati (`public/uploads/`, `storage/originals/`).

Lo script si rifiuta di generare l'artefatto se gli asset non sono stati
compilati o se `vendor/` contiene ancora le dipendenze di sviluppo: sono i due
errori piu facili da commettere e i piu difficili da diagnosticare dopo.

> Dopo il deploy, per tornare a lavorare in locale: `composer install`
> (senza `--no-dev`), altrimenti i test non partono.

---

## 5. Caricamento via FTP/FTPS

Connettiti in **FTPS esplicito** (porta 21 con TLS, non FTP in chiaro).

Carica **il contenuto** di `build/deploy/` nella cartella radice dell'hosting —
di solito `/` oppure `/httpdocs`, a seconda del piano.

Struttura attesa sul server:

```text
/                        <- radice FTP
├── app/
├── config/
├── database/
├── public/              <- l'unica cartella che deve essere esposta sul web
├── resources/
├── routes/
├── scripts/
├── storage/
├── vendor/
├── composer.json
└── .env                 <- da creare (punto 7)
```

> Il caricamento di `vendor/` richiede tempo: sono circa 1400 file. Se il client
> FTP si interrompe, riprendi con l'opzione "sovrascrivi solo i file diversi".
> In alternativa, se il pannello Aruba offre un gestore file con estrazione ZIP,
> comprimi `build/deploy/` in un archivio, caricalo e decomprimilo da li: e
> molto piu rapido.

---

## 6. Document root

**Situazione ideale.** Nel pannello Aruba, alla voce *Gestione domini* o
*Configurazione sito*, imposta la cartella radice del dominio su **`public`**.

Cosi solo `public/` e raggiungibile dal web: codice, configurazione, originali
delle fotografie e `vendor/` restano fuori portata.

**Se il pannello non lo consente** (capita su alcuni piani Easy):

1. sposta il **contenuto** di `public/` nella radice FTP;
2. apri il file `index.php` appena spostato e correggi i due percorsi:

   ```php
   // da:
   $autoload = dirname(__DIR__) . '/vendor/autoload.php';
   (new Application(dirname(__DIR__)))->boot()->run();

   // a:
   $autoload = __DIR__ . '/app-privato/vendor/autoload.php';
   (new Application(__DIR__ . '/app-privato'))->boot()->run();
   ```

3. sposta tutto il resto (`app/`, `config/`, `database/`, `resources/`,
   `routes/`, `scripts/`, `storage/`, `vendor/`, `.env`, `composer.json`) in una
   cartella `app-privato/`;
4. verifica che `app-privato/.htaccess` contenga il blocco `Require all denied`
   (lo script di build lo crea gia).

Questa variante funziona, ma la prima e piu sicura: se un giorno un modulo
Apache venisse disattivato, i file `.htaccess` smetterebbero di proteggere e il
codice diventerebbe scaricabile. Chiedi all'assistenza Aruba se il document root
si puo cambiare: quasi sempre si puo.

---

## 7. Il file .env

Crea `.env` nella radice (accanto a `composer.json`) partendo da `.env.example`.
Valori per la produzione:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_NAME="Baraonda Fiorentina"
APP_URL=https://www.baraondafiorentina.it
APP_TIMEZONE=Europe/Rome
APP_KEY=base64:...            # vedi sotto

FORCE_HTTPS=true
SESSION_SECURE=true

DB_HOST=sql.tuodominio.it     # l'host indicato da Aruba, NON localhost
DB_PORT=3306
DB_DATABASE=<nome del database, dal pannello Aruba>
DB_USERNAME=<utente del database, dal pannello Aruba>
DB_PASSWORD=<la password del database, dal pannello Aruba>

MAIL_MAILER=smtp
MAIL_HOST=smtps.aruba.it
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=noreply@baraondafiorentina.it
MAIL_PASSWORD=<la password della casella di posta>
MAIL_FROM_ADDRESS=noreply@baraondafiorentina.it
MAIL_FROM_NAME="Baraonda Fiorentina"
MAIL_ORDERS_TO=merchandising@baraondafiorentina.it
MAIL_CONTACT_TO=info@baraondafiorentina.it

IMAGE_DRIVER=auto
```

**Generare `APP_KEY`.** Se hai accesso alla riga di comando:

```bash
php scripts/generate-key.php --write
```

Senza `--write` la chiave viene solo mostrata, da copiare a mano. Se sul server
la riga di comando non c'e, generala in locale con lo stesso comando e incolla
il valore: e una stringa casuale, non dipende dal server.

> `APP_KEY` firma sessioni e impronte del browser. Cambiarla in seguito
> disconnette tutti gli amministratori: si imposta una volta e non si tocca piu.

**Controllo importante:** apri <https://www.tuodominio.it/.env> nel browser.
Deve rispondere **403 o 404**. Se mostra il contenuto del file, il document root
non e configurato correttamente: fermati e sistema il punto 6 prima di
proseguire.

---

## 8. Import dello schema del database

### Opzione A — da phpMyAdmin (sempre disponibile)

1. apri phpMyAdmin dal pannello Aruba e seleziona il database;
2. scheda **Importa**;
3. carica **in questo ordine** i file di `database/migrations/`:

   ```text
   001_create_auth_tables.sql
   002_create_content_tables.sql
   003_create_gallery_tables.sql
   004_create_shop_tables.sql
   005_create_organization_and_settings_tables.sql
   ```

   L'ordine conta: le tabelle successive hanno chiavi esterne verso le
   precedenti.

4. **importante**: ogni file contiene in fondo una sezione `-- @DOWN` con i
   comandi `DROP TABLE`. Prima di importare, **cancella tutto dalla riga
   `-- @DOWN` in poi**, oppure le tabelle appena create verrebbero eliminate.

### Opzione B — da riga di comando (se disponibile)

```bash
php scripts/migrate.php
```

Applica solo le migrazioni mancanti e tiene traccia di quelle gia eseguite:
e la strada da preferire, quando c'e.

### Dati iniziali

Dopo lo schema servono le impostazioni e le pagine editoriali:

```bash
php scripts/seed.php --only=settings      # impostazioni con i valori predefiniti
php scripts/seed.php --only=taxonomy      # categorie di eventi e prodotti
php scripts/seed.php --only=pages         # pagine editoriali, da riscrivere
php scripts/seed.php --only=organization  # ruoli del direttivo, senza nomi reali
```

L'elenco completo dei seeder e in `php scripts/seed.php --list`.

Senza riga di comando, apri temporaneamente il sito: le impostazioni mancanti
usano automaticamente i valori predefiniti.

> **Non lanciare `php scripts/seed.php` senza `--only`** in produzione: creerebbe
> anche notizie, eventi e prodotti dimostrativi da cancellare a mano.

---

## 9. Permessi delle cartelle

Dal client FTP, imposta i permessi **755** (o **775** se il server lo richiede)
in modo ricorsivo su:

```text
storage/
storage/originals/
storage/processed/
storage/products/
storage/logs/
storage/cache/
storage/temp/
storage/sessions/
public/uploads/
```

Sono le uniche cartelle in cui il sito scrive. Tutto il resto puo restare in
sola lettura (644 per i file, 755 per le cartelle).

Se il caricamento delle fotografie fallisce con un errore generico, i permessi
sono la prima cosa da controllare.

---

## 10. HTTPS

1. attiva il certificato SSL dal pannello Aruba (i piani Easy includono
   Let's Encrypt gratuito);
2. attendi l'emissione, di norma pochi minuti;
3. verifica che `https://www.tuodominio.it` si apra senza avvisi;
4. **solo a questo punto** attiva il redirect. In `public/.htaccess` togli il
   commento a queste righe:

   ```apache
   RewriteCond %{HTTPS} !=on
   RewriteCond %{HTTP:X-Forwarded-Proto} !https
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

5. verifica che nel `.env` ci siano `FORCE_HTTPS=true` e `SESSION_SECURE=true`.

**HSTS.** Solo dopo qualche settimana di HTTPS stabile, togli il commento anche a:

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

Attivarlo troppo presto e rischioso: se il certificato avesse problemi, i
browser rifiuterebbero di aprire il sito e non ci sarebbe modo di aggirarli.

---

## 11. Impostazioni del sito

Nome del gruppo, email e telefono, indirizzo della sede, orari, collegamenti
social, testo del piede di pagina, istruzioni di pagamento, interruttore del
catalogo e regolazioni della filigrana stanno tutti nella tabella
`site_settings`. **Non c'è una pagina del pannello per cambiarli**: sono cose
che si scrivono una volta all'inizio, e l'amministratore di turno non deve
poterle spostare per sbaglio.

Si cambiano da phpMyAdmin, oppure con una query:

```sql
UPDATE site_settings SET value = 'info@tuodominio.it' WHERE key_name = 'contact_email';
```

Per vedere tutto quello che si puo cambiare, con il valore attuale:

```sql
SELECT group_name, key_name, label, value FROM site_settings ORDER BY group_name, sort_order;
```

Le chiavi che servono piu spesso:

| Chiave | Cosa è |
|---|---|
| `site_group_name` | nome del gruppo, in testata e nelle email |
| `contact_email` | indirizzo a cui arrivano i messaggi dal modulo contatti |
| `contact_phone`, `contact_address`, `contact_opening_hours` | i dati della pagina Contatti |
| `contact_merchandising_email` | dove arrivano gli ordini |
| `shop_payment_instructions` | il testo che il cliente riceve per pagare |
| `shop_enabled` | `1` o `0`: sospende gli ordini lasciando il catalogo visibile |
| `social_instagram_url`, `social_facebook_url`, `social_youtube_url`, `social_telegram_url` | i collegamenti ai profili |
| `social_behold_feed_id` | il feed Behold per i post di Instagram in homepage |
| `footer_association_details` | i dati dell'associazione nel piede di pagina |

**Dopo ogni modifica svuota la cache** dei template: cancella il contenuto di
`storage/cache/twig/`. I valori vengono riletti da soli, ma le pagine gia
compilate no.

---

## 12. Posta elettronica

1. crea dal pannello Aruba una casella `noreply@tuodominio.it`;
2. inserisci le credenziali nel `.env` (punto 7);
3. crea anche `merchandising@` e `info@`, oppure usa indirizzi esistenti;
4. imposta i destinatari operativi in `site_settings` (si veda la sezione
   *Impostazioni del sito* qui sotto).

**Verifica**: dal sito pubblico invia un messaggio dal modulo contatti e
controlla che arrivi. Se non arriva, guarda `storage/logs/app-*.log`: l'errore
SMTP viene registrato per esteso. Questa prova conta piu delle altre: il sito
non conserva copia dei messaggi ricevuti, quindi con la posta guasta quello
che le persone scrivono va perduto. Il modulo se ne accorge e lo dice a chi
ha scritto, ma il messaggio resta comunque non recapitato.

> Aruba impone un limite di invii orari. Il sito ne fa pochissimi (un paio per
> ordine, uno per messaggio di contatto), quindi il limite non e un problema.

---

## 13. Primo amministratore

**Con riga di comando:**

```bash
php scripts/create-admin.php
```

**Senza riga di comando**, procedura manuale:

1. genera l'hash della password. In locale:

   ```bash
   php -r "echo password_hash('LaTuaPasswordSicura', PASSWORD_ARGON2ID), PHP_EOL;"
   ```

   Se il tuo PHP locale non ha Argon2id, usa `PASSWORD_BCRYPT`: il sito accetta
   entrambi e aggiorna l'hash da solo al primo accesso.

2. da phpMyAdmin, esegui questa query sostituendo i valori:

   ```sql
   INSERT INTO users (name, email, password_hash, role, status, password_changed_at, created_at, updated_at)
   VALUES (
       'Nome Cognome',
       'tua@email.it',
       '$argon2id$...',            -- l'hash generato al punto 1
       'SUPER_ADMIN',
       'active',
       NOW(), NOW(), NOW()
   );
   ```

3. accedi su `https://www.tuodominio.it/admin` e **cambia subito la password**
   dal tuo profilo: cosi l'hash viene rigenerato dal sito e quello passato da
   phpMyAdmin non e piu valido.

Da qui in poi tutti gli altri amministratori si invitano **dal pannello**, alla
voce *Amministratori*.

---

## 14. Cron

Dal pannello Aruba, sezione **Cron Job**. Il percorso assoluto e visibile nel
pannello (qualcosa come `/web/htdocs/www.tuodominio.it/home`).

| Comando | Frequenza | Espressione cron |
|---|---|---|
| `php /percorso/assoluto/scripts/sync-football.php` | 2 volte al giorno | `0 6,18 * * *` |
| `php /percorso/assoluto/scripts/sync-social.php` | ogni 6 ore | `30 */6 * * *` |
| `php /percorso/assoluto/scripts/cleanup-tokens.php` | ogni notte | `0 3 * * *` |
| `php /percorso/assoluto/scripts/cleanup-temp-files.php` | ogni domenica | `0 4 * * 0` |

Note pratiche:

- gli orari sono sfalsati di proposito: due script pesanti nello stesso minuto
  su hosting condiviso possono andare in timeout;
- tutti gli script sono **idempotenti**: se un'esecuzione salta o parte due
  volte, non succede nulla di male;
- se il pannello richiede il percorso completo dell'eseguibile PHP, usa quello
  indicato da Aruba (spesso `/usr/local/bin/php` o simile);
- **senza cron il sito funziona lo stesso**: calendario e social restano
  fermi all'ultimo aggiornamento, ma nessuna pagina si rompe. Il calendario si
  puo comunque aggiornare a mano dal pannello, voce *Calendario partite →
  Sincronizza adesso*.

---

## 15. Verifica finale

Da spuntare prima di considerare il sito pubblicato:

**Sicurezza**

- [ ] `https://tuodominio.it/.env` risponde 403 o 404
- [ ] `https://tuodominio.it/composer.json` risponde 403 o 404
- [ ] `https://tuodominio.it/storage/logs/` risponde 403 o 404
- [ ] `https://tuodominio.it/vendor/` risponde 403 o 404
- [ ] `https://tuodominio.it/admin` chiede l'accesso
- [ ] il sito e raggiungibile solo in HTTPS
- [ ] nel `.env`: `APP_DEBUG=false` e `APP_ENV=production`
- [ ] un errore volontario (indirizzo inesistente) mostra la pagina 404 curata,
      non uno stack trace

**Funzionamento**

- [ ] la homepage si apre e mostra notizie, eventi e prodotti
- [ ] le immagini si vedono (galleria e prodotti)
- [ ] il calendario mostra le partite
- [ ] l'accesso al pannello funziona
- [ ] il caricamento di una fotografia va a buon fine e la filigrana compare
- [ ] un ordine di prova arriva a destinazione e le due email vengono ricevute
- [ ] il modulo contatti invia e il messaggio arriva all'indirizzo del gruppo
- [ ] `https://tuodominio.it/sitemap.xml` risponde correttamente

**Contenuti da completare** (cerca i testi marcati `DA COMPLETARE`)

- [ ] `site_settings`: indirizzo della sede, telefono, orari
- [ ] `site_settings`: istruzioni di pagamento del merchandising
- [ ] `site_settings`: dati dell'associazione nel piede di pagina
- [ ] `site_settings`: collegamenti ai profili social
- [ ] Pagina *Chi siamo*: la storia reale del gruppo (si scrive a database, tabella `pages`)
- [ ] Pagine *Privacy policy* e *Cookie policy*, fatte verificare da chi di dovere
- [ ] Organizzazione: nomi e ruoli reali del direttivo
- [ ] Logo ufficiale al posto dei segnaposto (vedi punto 16)

---

## 16. Aggiornamenti successivi

Per pubblicare una modifica al codice:

```bash
composer install --no-dev --optimize-autoloader
npm run build
php scripts/build-deploy.php
```

Poi carica via FTP **solo cio che e cambiato**. Nella pratica quasi sempre:

- `app/`, `resources/views/`, `routes/`, `config/`
- `public/assets/` (se hai toccato CSS o JavaScript)
- `vendor/` (solo se hai cambiato le dipendenze)

**Non sovrascrivere mai**: `.env`, `public/uploads/`, `storage/`.

Se sono state aggiunte migrazioni, importa i nuovi file `.sql` da phpMyAdmin
oppure lancia `php scripts/migrate.php`.

Dopo un aggiornamento dei template, svuota la cache: cancella il contenuto di
`storage/cache/twig/`. Si rigenera da sola alla prima visita.

### Sostituire i segnaposto grafici

| File | Cosa e |
|---|---|
| `resources/static/logo.png` | logo del gruppo: testata, piede di pagina, pannello, pagine d'errore |
| `resources/images/logo-originale.png` | l'originale ad alta risoluzione, da cui si rigenerano gli altri |
| `public/favicon/` | l'icona nella scheda del browser e sulla schermata iniziale del telefono |
| `resources/static/hero.svg` | immagine di apertura della homepage |
| `resources/images/watermark.png` | filigrana applicata alle fotografie (PNG con trasparenza) |
| `resources/views/components/giglio.twig` | giglio fiorentino usato come decorazione di sfondo |
| `resources/static/stemma-fiorentina.svg` | stemma della ACF Fiorentina, decorazione delle fasce viola |

> Il giglio non è un segnaposto: è un disegno originale dello stemma civico di
> Firenze, simbolo araldico di pubblico dominio.

#### Lo stemma della Fiorentina

Lo stemma della ACF Fiorentina è un marchio registrato e **non fa parte del
repository**: il permesso di usarlo appartiene al gruppo, non al codice. Va
quindi aggiunto a mano, una volta sola:

1. si appoggia il file in `resources/static/` chiamandolo
   `stemma-fiorentina.svg` (vanno bene anche `.png` e `.webp`; con piu formati
   presenti vince l'svg, che resta nitido a ogni ingrandimento);
2. si lancia `npm run build`, che lo copia in `public/assets/`;
3. si carica `public/assets/` sul server.

Compare in tre punti, sempre a destra e sempre in trasparenza: l'apertura
della homepage, il richiamo *Entra nella Baraonda* e il piede di pagina. è
una decorazione di sfondo, non un'immagine di contenuto: sta dietro al testo,
non si puo selezionare e i lettori di schermo la saltano, perche a chi ascolta
la pagina non aggiunge niente.

**Finche il file non c'è, il sito non lo disegna affatto**: nessun riquadro
vuoto, nessuna immagine rotta. Le stesse fasce viola restano esattamente come
sono oggi.

Le trasparenze sono tarate sullo stemma a colori oggi in uso: 16%
nell'apertura, 12% nel richiamo, 9% nel piede di pagina. Sembrano valori
alti rispetto al giglio, che sta al 7-9%, ma il giglio è bianco su viola
mentre lo stemma è quasi dello stesso viola del fondo, e sotto il 10%
sparirebbe del tutto. Se un giorno arrivasse una versione monocromatica
bianca, quei tre valori vanno riportati intorno al 5-7%: si cambiano in
`resources/views/site/home.twig` e
`resources/views/components/site-footer.twig`, dove il componente viene
richiamato.

Dopo aver sostituito logo e immagine di apertura serve `npm run build` e un
nuovo caricamento di `public/assets/`. La filigrana invece si applica alle
fotografie caricate da quel momento: per riapplicarla a un album esistente usa
il pulsante *Rielabora tutte* nella pagina dell'album.

---

## 17. Backup

**Cosa salvare**, in ordine di importanza:

1. **Database** — contiene tutto: contenuti, ordini, account, impostazioni.
   Da phpMyAdmin: *Esporta → Rapido → SQL*. Consigliato una volta a settimana e
   sempre prima di un aggiornamento.
2. **`storage/originals/`** — le fotografie originali. Non sono rigenerabili:
   se si perdono, si perdono davvero.
3. **`public/uploads/`** — le versioni pubbliche. Rigenerabili dagli originali,
   ma ripristinarle e molto piu veloce che rielaborarle.
4. **`.env`** — la configurazione, `APP_KEY` compresa.

**Cosa non serve salvare**: `vendor/`, `public/assets/`, `storage/cache/`,
`storage/logs/`, `storage/sessions/`. Si rigenerano tutti.

### Spostare il sito su un altro hosting

Il progetto non dipende da nulla di specifico di Aruba. Per migrare:

1. esporta il database e importalo sul nuovo server;
2. copia tutti i file, `storage/` e `public/uploads/` compresi;
3. aggiorna `DB_*`, `APP_URL` e `MAIL_*` nel `.env`;
4. imposta il document root su `public/`;
5. ricrea i cron.

Serve solo un hosting con PHP 8.2+, MySQL 8 e Apache con `mod_rewrite`.

---

## 18. Problemi frequenti

**Errore 500 su tutte le pagine**
Imposta temporaneamente `APP_DEBUG=true` nel `.env` e ricarica: comparira il
dettaglio dell'errore. Nella grande maggioranza dei casi sono le credenziali del
database sbagliate. **Rimetti `false` appena risolto.**

**"Connessione al database non riuscita"**
L'host non e `localhost`: Aruba assegna un host dedicato, indicato nel pannello
alla voce *Database MySQL*.

**Le pagine interne danno 404, la home funziona**
`mod_rewrite` non e attivo oppure `AllowOverride` e disabilitato: il file
`public/.htaccess` viene ignorato. Contatta l'assistenza Aruba.

**Le immagini non si vedono**
Verifica i permessi di `public/uploads/` (punto 9) e che il document root punti
a `public/`.

**Il caricamento delle fotografie fallisce**
Controlla `upload_max_filesize`, `post_max_size` e `max_file_uploads` nella
configurazione PHP (punto 2). Il caricamento avviene comunque a lotti di quattro
file per volta, proprio per non superare i limiti.

**Il calendario resta vuoto dopo aver messo la chiave API**
Lancia `php scripts/sync-football.php`: se dice «il fornitore non ha restituito
alcuna partita», il messaggio esatto del servizio e in `storage/logs/app-*.log`,
alla voce «Chiamata HTTP con esito negativo». Le cause tipiche sono la chiave
sbagliata o il limite di richieste superato. Se invece l'errore parla di
certificati (`unable to get local issuer certificate`), al PHP manca il bundle
dei certificati radice: su Aruba non capita, in locale si risolve indicando un
file `cacert.pem` in `curl.cainfo` dentro il `php.ini`.

**Le email non arrivano**
Guarda `storage/logs/app-*.log`. Verifica porta e cifratura: Aruba vuole
`smtps.aruba.it` sulla porta `465` con `ssl`. L'indirizzo mittente deve
corrispondere a una casella realmente esistente sul dominio.

**Il sito e lento**
Verifica che OPCache sia attivo e che `storage/cache/twig/` sia scrivibile: se
non lo e, ogni pagina ricompila i template da zero.

**La sincronizzazione del calendario partite fallisce sempre**
Guarda `storage/logs/app-*.log`: il messaggio dice cosa è successo davvero.
Se parla del certificato che non si riesce a verificare, al PHP del server
manca l'elenco delle autorita di certificazione: si sistema indicando un file
`cacert.pem` in `curl.cainfo` dentro il `.php.ini`. In locale, attenzione a
una cosa che sembra magia nera: il server di sviluppo legge il `php.ini` una
volta sola, all'avvio. Se hai aggiunto `curl.cainfo` mentre era gia acceso,
continuera a fallire finche non lo riavvii, e la riga di comando funzionera
benissimo nel frattempo.

**Ho perso la password di amministratore**
Usa "Password dimenticata" nella pagina di accesso. Se anche la casella email
non e raggiungibile, ripeti la procedura manuale del punto 13 aggiornando la
riga esistente invece di inserirne una nuova.
