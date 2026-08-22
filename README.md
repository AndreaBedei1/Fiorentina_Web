# Baraonda Fiorentina

Sito ufficiale del gruppo organizzato di tifosi **Baraonda Fiorentina**: portale
pubblico e CMS proprietario, costruito per essere gestito autonomamente dal
gruppo dopo la consegna.

Non usa WordPress ne altri CMS esterni, non gestisce pagamenti online e non
richiede alcuna registrazione ai visitatori.

---

## Indice

- [Cosa fa il sito](#cosa-fa-il-sito)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Configurazione (.env)](#configurazione-env)
- [Database](#database)
- [Sviluppo](#sviluppo)
- [Primo amministratore](#primo-amministratore)
- [Email](#email)
- [API esterne](#api-esterne)
- [Compilazione degli asset](#compilazione-degli-asset)
- [Test](#test)
- [Deploy su Aruba](#deploy-su-aruba)
- [Cron](#cron)
- [Backup](#backup)
- [Struttura del progetto](#struttura-del-progetto)

---

## Cosa fa il sito

**Area pubblica**

| Indirizzo | Contenuto |
|---|---|
| `/` | Homepage: apertura, prossima partita, eventi, notizie, galleria, merchandising, social |
| `/chi-siamo` | Storia, valori e organigramma del direttivo |
| `/diventa-socio` | Come iscriversi, benefici, quota, domande frequenti |
| `/notizie`, `/notizie/{slug}` | Notizie del gruppo |
| `/eventi`, `/eventi/{slug}` | Trasferte, riunioni, cene, raduni |
| `/calendario` | Partite della Fiorentina piu appuntamenti del gruppo |
| `/galleria`, `/galleria/{slug}` | Album fotografici con filigrana automatica |
| `/merchandising`, `/merchandising/{slug}` | Catalogo del materiale ufficiale |
| `/carrello`, `/ordine` | Richiesta d'ordine, **senza alcun pagamento online** |
| `/contatti` | Recapiti e modulo di contatto |
| `/privacy`, `/cookie-policy` | Informative |
| `/sitemap.xml`, `/robots.txt` | Generati dinamicamente dai contenuti pubblicati |

**Area riservata** (`/admin`) — accessibile ai soli amministratori invitati:
notizie, eventi, calendario partite, galleria con caricamento multiplo,
contenuti social, prodotti, ordini, organigramma e amministratori.

---

## Requisiti

### Sviluppo

| Componente | Versione | Note |
|---|---|---|
| PHP | 8.2 o superiore | testato su 8.3 |
| Composer | 2.x | |
| MySQL | 8.0 | oppure MariaDB 10.6+ |
| Node.js | 20 o superiore | **solo in sviluppo**, per compilare gli asset |

Estensioni PHP: `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `openssl`, `curl`,
`gd` **oppure** `imagick`, `exif` (consigliata), `intl` (consigliata).

### Produzione (Aruba Linux Easy)

PHP 8.2+, MySQL 8, Apache con `mod_rewrite`, cURL, GD o ImageMagick, Sodium,
OPCache, cron, SMTP.
**Node.js non serve**: il server riceve gli asset gia compilati.

---

## Installazione

```bash
git clone https://github.com/AndreaBedei1/Fiorentina_Web.git
cd Fiorentina_Web

composer install
npm install

cp .env.example .env
php scripts/generate-key.php --write
```

Poi apri `.env` e compila almeno le variabili `DB_*` (vedi sotto).

---

## Configurazione (.env)

Il file `.env` non e mai nel repository: contiene i segreti. Le variabili
principali:

```dotenv
APP_ENV=local              # local | production
APP_DEBUG=true             # false in produzione
APP_URL=http://localhost:8080
APP_KEY=                   # php scripts/generate-key.php --write

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=baraonda
DB_USERNAME=
DB_PASSWORD=
DB_TEST_DATABASE=baraonda_test

MAIL_MAILER=log            # log | smtp | null
MAIL_HOST=smtps.aruba.it
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=
MAIL_PASSWORD=

FOOTBALL_PROVIDER=mock     # mock | apifootball
FOOTBALL_API_KEY=

SOCIAL_PROVIDER=mock       # mock | live
INSTAGRAM_TOKEN=
FACEBOOK_TOKEN=
YOUTUBE_API_KEY=
```

`.env.example` contiene l'elenco completo, commentato riga per riga.

> **Nota.** `APP_KEY` firma sessioni e impronte del browser. Cambiarla
> disconnette tutti gli amministratori: si genera una volta e non si tocca piu.

---

## Database

```bash
php scripts/migrate.php            # applica le migrazioni mancanti
php scripts/migrate.php --status   # mostra cosa e stato applicato
php scripts/migrate.php --fresh    # ATTENZIONE: cancella tutto e ricrea
php scripts/migrate.php --rollback # annulla l'ultimo lotto

php scripts/seed.php               # dati dimostrativi
php scripts/seed.php --list        # elenca i seeder disponibili
php scripts/seed.php --only=shop   # esegue un solo seeder
```

Le migrazioni sono file `.sql` leggibili in `database/migrations/`: lo stesso
file serve al comando e all'import manuale da phpMyAdmin, quindi non esistono
due versioni dello schema che possono disallinearsi.

I seed creano contenuti dimostrativi realistici, chiaramente riconoscibili e
sostituibili: notizie, eventi, album con fotografie generate, catalogo,
organigramma, calendario partite e contenuti social.

---

## Sviluppo

**Server PHP integrato** (piu semplice, nessuna dipendenza):

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

**Asset in tempo reale**, in un secondo terminale:

```bash
npm run dev      # con ricarica automatica
# oppure
npm run watch    # ricompila a ogni modifica, senza dev server
```

Il sito e su <http://localhost:8080>, il pannello su
<http://localhost:8080/admin>.

**Docker**, se preferisci un ambiente isolato con MySQL e Mailpit inclusi:

```bash
docker compose up -d
docker compose exec app php scripts/migrate.php
docker compose exec app php scripts/seed.php
```

Sito su <http://localhost:8080>, casella di posta di prova su
<http://localhost:8025>.

---

## Primo amministratore

**In sviluppo**: imposta `DEV_ADMIN_PASSWORD` nel file `.env` e lancia
`php scripts/seed.php`. Il seeder crea un super amministratore usando
`DEV_ADMIN_EMAIL` e quella password, e si rifiuta di farlo fuori da
`APP_ENV=local`.

**In produzione**:

```bash
php scripts/create-admin.php
```

Chiede email, nome e password in modo interattivo: la password non compare mai
fra i parametri della riga di comando, quindi non finisce nella cronologia
della shell. Lo stesso comando puo promuovere un account esistente a super
amministratore, utile se si perde l'accesso.

Da quel momento in poi tutti gli altri account si creano **dal pannello**, alla
voce *Amministratori*: la persona invitata riceve un collegamento monouso e
sceglie da se la propria password. Non esiste, e non deve esistere, una pagina
di registrazione pubblica.

---

## Email

Tre modalita, decise da `MAIL_MAILER`:

| Valore | Comportamento | Quando usarlo |
|---|---|---|
| `log` | Salva i messaggi come file `.eml` in `storage/logs/mail/` | sviluppo |
| `smtp` | Invio reale via SMTP | produzione |
| `null` | Scarta i messaggi | test automatici |

I file `.eml` si aprono con qualsiasi client di posta: si puo verificare
l'intero flusso ordini senza configurare un server SMTP e senza rischiare invii
accidentali a indirizzi veri.

Con Docker e attivo **Mailpit**: le email compaiono su
<http://localhost:8025>. Basta impostare `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`,
`MAIL_PORT=1025`, `MAIL_ENCRYPTION=null`.

Configurazione Aruba: `MAIL_HOST=smtps.aruba.it`, `MAIL_PORT=465`,
`MAIL_ENCRYPTION=ssl`, con le credenziali della casella creata dal pannello.

---

## API esterne

Il sito **funziona completamente senza alcuna chiave API**: in assenza di
credenziali entrano in gioco fornitori dimostrativi che popolano calendario e
sezione social con dati verosimili.

### Calcio

```dotenv
FOOTBALL_PROVIDER=apifootball
FOOTBALL_API_KEY=<la tua chiave>
FOOTBALL_TEAM_ID=502
FOOTBALL_SEASON=2026
```

L'implementazione fornita e per [API-Football](https://www.api-football.com/).
Per adottare un altro servizio basta scrivere una classe che implementi
`App\Services\Football\FootballApiInterface` e registrarla in
`FootballService::provider()`: nessun'altra parte del progetto ne risente.

Il frontend **non interroga mai l'API**: legge la copia locale aggiornata dal
cron. La homepage resta veloce e continua a funzionare anche se il servizio
esterno e irraggiungibile.

### Social

```dotenv
SOCIAL_PROVIDER=live
INSTAGRAM_TOKEN=...      # Instagram Graph API (account Business o Creator)
INSTAGRAM_USER_ID=...
FACEBOOK_TOKEN=...       # Graph API, pagina Facebook
FACEBOOK_PAGE_ID=...
YOUTUBE_API_KEY=...      # YouTube Data API v3 (solo chiave, nessun OAuth)
YOUTUBE_CHANNEL_ID=...
```

Le anteprime vengono **scaricate e archiviate in locale** durante la
sincronizzazione: gli URL dei CDN di Meta scadono, e senza copia locale la
homepage si riempirebbe di riquadri vuoti dopo qualche giorno. In piu il
visitatore non contatta mai i server di Meta, il che semplifica anche il fronte
cookie.

---

## Compilazione degli asset

```bash
npm run build       # produzione: minificato, con hash nei nomi
npm run dev         # sviluppo con ricarica automatica
npm run watch       # ricompila senza dev server
npm run typecheck   # controllo dei tipi TypeScript
```

Il risultato finisce in `public/assets/`, insieme a un `manifest.json` che PHP
legge per generare i tag con i nomi corretti. **In produzione Node non serve**:
si carica la cartella gia compilata.

---

## Test

```bash
composer test                  # tutta la suite
composer test:unit             # solo test unitari (nessun database)
composer test:integration      # solo test di integrazione
vendor/bin/phpunit --filter MediaTest
```

I test di integrazione usano il database indicato da `DB_TEST_DATABASE`, che
viene **ricostruito da zero** a ogni esecuzione. La suite si rifiuta di partire
se il nome del database non contiene `test`: e la salvaguardia che impedisce di
azzerare per sbaglio i dati di sviluppo.

Copertura delle aree critiche: hashing password, autenticazione, blocco
account, permessi dei ruoli, salvaguardie sui super amministratori, CSRF,
validazione e sanitizzazione, upload e validazione dei file, elaborazione
immagini e filigrana, carrello, calcolo dei totali, numerazione degli ordini,
slug e collisioni, rate limiting, fornitori esterni.

**Altri controlli utili:**

```bash
composer lint                     # i due controlli qui sotto, uno dopo l'altro
composer lint:views               # sintassi di tutti i template Twig
composer lint:rotte               # collegamenti route() scritti nei template
php scripts/check-contrast.php    # contrasti WCAG 2.1 AA della palette
```

`lint:rotte` esiste per un tipo di errore che nient'altro intercetta:
`route('shop.show', { slug: ... })` è sintatticamente perfetto, ma se quella
rotta chiama il suo parametro `riferimento` la pagina esplode - e solo quando
qualcuno ci arriva davvero. È successo con il carrello, che dopo il passaggio
agli indirizzi con id continuava a chiedere `slug`: vuoto funzionava, pieno no.
Il comando confronta i nomi e i parametri delle rotte dichiarate con quelli
usati nei template, e dice file e riga.

---

## Deploy su Aruba

Procedura completa e commentata: **[docs/DEPLOY_ARUBA.md](docs/DEPLOY_ARUBA.md)**.

In sintesi:

```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
php scripts/build-deploy.php        # prepara build/deploy/
```

Poi si carica il contenuto di `build/deploy/` via FTP/FTPS, si crea il `.env`
sul server, si importa lo schema del database e si crea il primo
amministratore.

---

## Cron

Da configurare nel pannello Aruba (dettagli e orari consigliati in
`docs/DEPLOY_ARUBA.md`):

| Script | Frequenza consigliata | Cosa fa |
|---|---|---|
| `scripts/sync-football.php` | 2 volte al giorno | aggiorna il calendario partite |
| `scripts/sync-social.php` | ogni 6 ore | aggiorna i contenuti social |

Un comando di manutenzione, da lanciare a mano quando serve:

| Comando | Cosa fa |
| --- | --- |
| `composer media:clean` | elenca le immagini su disco che nessuna riga del database rivendica; con `--elimina` le toglie |
| `scripts/cleanup-tokens.php` | 1 volta al giorno | rimuove token e contatori scaduti |
| `scripts/cleanup-temp-files.php` | 1 volta a settimana | pulisce cache, log e temporanei |

Tutti gli script sono idempotenti: rieseguirli non crea duplicati ne danni.

---

## Backup

Cosa salvare, in ordine di importanza:

1. **Database** — contiene tutto: contenuti, ordini, account, impostazioni.
   Export completo da phpMyAdmin o `mysqldump`.
2. **`storage/originals/`** — le fotografie originali, non rigenerabili.
3. **`public/uploads/`** — le versioni pubbliche. Rigenerabili dagli originali,
   ma ripristinarle e piu rapido che rielaborarle.
4. **`.env`** — la configurazione, `APP_KEY` compresa.

Non serve salvare `vendor/`, `node_modules/`, `public/assets/`,
`storage/cache/` e `storage/logs/`: si rigenerano tutti.

---

## Struttura del progetto

```text
app/
  Console/         utilita per gli script da riga di comando
  Controllers/     Site/ (pubblico) e Admin/ (area riservata)
  Core/            kernel: container, routing, HTTP, database, sessioni, view
  DTO/             oggetti di trasferimento dai servizi esterni
  Exceptions/      eccezioni applicative
  Helpers/         funzioni globali (poche, volutamente)
  Middleware/      HTTPS, intestazioni di sicurezza, CSRF, autenticazione
  Models/          entita di dominio tipizzate
  Repositories/    tutto l'accesso SQL, in un solo strato
  Services/        logica applicativa (media, negozio, calcio, social, posta)
  Validation/      validatore dei form e regole sulle password

config/            configurazione, letta dalle variabili d'ambiente
database/
  migrations/      schema in SQL leggibile
  seeds/           dati dimostrativi
docs/              architettura e procedura di deploy
public/            UNICA cartella esposta sul web
resources/
  css/ js/         sorgenti compilate da Vite
  static/          logo, immagini segnaposto (copiati cosi come sono)
  images/          filigrana
  views/           template Twig
routes/            definizione delle rotte
scripts/           comandi CLI: migrazioni, seed, cron, deploy
storage/           dati runtime: originali, cache, log, sessioni
tests/             suite PHPUnit
```

Architettura, scelte tecniche e flussi principali sono documentati in
**[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**.
