# WP SFTP Stream Backup

Plugin WordPress per backup completi (database + file) inviati a **SFTP, S3 e Nextcloud, senza creare archivi o file temporanei sul disco del sito**.

**Coded by [Radici Soluzioni Informatiche](https://radici.biz)**

**Novita 1.2:** [ripristino one click](#ripristino-one-click-12) direttamente dal
repository, per tornare a un backup o migrare il sito su un nuovo server.

[Scarica l'ultima release](https://github.com/radicisoluzioni/wp-sftp-stream-backup/releases/latest) ·
[Guida all'installazione](#installazione) · Licenza GPLv2 o successiva

### Interfaccia 1.1.1

Dashboard backup, repository e ripristino hanno una grafica coordinata con il
logo originale Radici, accenti lime/grigio, schede di stato piu leggibili e
layout adattabile a desktop e mobile. I credits collegano al sito Radici;
immagini, font e librerie non vengono caricati da servizi esterni.
Il logo e incluso nel plugin e incorporato nello script di ripristino,
che rimane autonomo e utilizzabile senza Internet.

## Repository multipli (1.1)

Da *Strumenti → Backup repositories* puoi salvare e attivare piu destinazioni:

- SFTP, con password o chiave SSH e fingerprint verificato;
- Amazon S3, Hetzner Object Storage, DigitalOcean Spaces e Aruba Object Storage
  tramite API S3 Signature V4, oltre a endpoint S3 personalizzati;
- Nextcloud autenticato via WebDAV con password applicazione;
- Nextcloud pubblico File Drop, anche con password (Nextcloud 29 o successivo).

Seleziona un **repository principale leggibile**: il plugin genera il backup
una sola volta li, poi lo rilegge una parte per volta e lo replica automaticamente
su tutte le altre destinazioni attive. File Drop puo essere soltanto una replica.
La replica passa attraverso PHP WordPress: non usa il disco locale, ma consuma
banda in ingresso/uscita e spazio remoto sul principale.

I nuovi backup multi-repository usano il **formato 2**:

| Oggetto | Contenuto |
|---|---|
| `database.sql.gz.part-*`, `files.tar.gz.part-*` | parti numerate, massimo 8 MiB ciascuna; un passaggio breve puo produrre parti piu piccole |
| `*.index-00000000.json`, ... | cataloghi paginati, fino a 256 parti ciascuno, dimensioni e SHA-256 |
| `*.parts.json` | descrittori dei due flussi compressi e radici dei cataloghi |
| `restore.php`, `manifest.json` | ripristino e manifest finale, pubblicato dopo i dati |

Le parti sono segmenti di un flusso gzip: non sono archivi autonomi. Lo script
di ripristino le legge in ordine, senza unirle prima sul disco. I vecchi backup
SFTP con due archivi interi continuano a funzionare.

### Configurazione e prove

1. Aggiungi una destinazione, salva, compila i campi, attivala e scegli il principale.
2. S3: bucket gia esistente, access key, secret key e prefisso. Con Amazon,
   Hetzner o Spaces, *Provider + Location* compilano endpoint/regione predefiniti
   se lasciati vuoti; un endpoint esplicito ha precedenza. Per Aruba inserisci
   endpoint e regione di firma forniti dal pannello: non vengono presunti.
3. Nextcloud autenticato: URL istanza, utente, password applicazione e *Path*
   della cartella. *Base* identifica lo spazio dei backup dentro quella cartella.
   Per File Drop usa *Public URL* con il link completo `/s/TOKEN` e, se prevista,
   *Public password*. Il token viene salvato cifrato.
4. Il pulsante di test usa le impostazioni **gia salvate**. Su SFTP il fingerprint
   mostrato va verificato indipendentemente e inserito prima di autenticarsi.
5. Avvio, programmazione, esclusioni e avanzamento sono nella pagina backup
   esistente. La retention si imposta per repository; `0` conserva tutto.

Le installazioni esistenti restano nella modalita SFTP originale finche non
salvi la pagina repository: le impostazioni SFTP vengono riportate senza
ricifrare o perdere le credenziali. Le costanti `WPSSB_SFTP_*` continuano a
valere per il repository `legacy-sftp`. Override avanzati per ID sono possibili
con una costante array `WPSSB_REPOSITORIES`, con gli stessi nomi dei campi;
i valori definiti nella costante sono gia in chiaro e non passano dalla UI.

### Errori, retention e File Drop

- Ogni copia ha avanzamento e retry propri. Le destinazioni leggibili vengono
  controllate rileggendo i dati e confrontando i checksum.
- Un File Drop puro permette solo l'upload: il risultato e **accepted**, non
  **verified**. Non sono disponibili elenco remoto, retention, cancellazione
  o verifica mediante rilettura. Il test lascia una piccola sonda permanente
  e richiede consenso; il proprietario puo cancellarla dal proprio account.
- Se una richiesta File Drop resta senza conferma, la copia riparte con un
  nuovo prefisso univoco per evitare le rinomine automatiche dei file omonimi.
  I tentativi precedenti possono lasciare parti da eliminare manualmente.
- Se una replica esaurisce i tentativi, il risultato globale e **partial**.
  Le copie riuscite restano disponibili e il principale resta protetto.
  Usa *Retry failed copies* oppure abbandona esplicitamente le copie pendenti.
  **Finche non risolvi o abbandoni, nuovi backup anche programmati non partono.**
- Annullamento e abbandono conservano le copie gia complete; possono lasciare
  upload parziali. Le parti orfane di un backup completo restano nel suo
  namespace fino alla retention. Controlla gli avvisi per la pulizia manuale.
- L'elenco multi-repository mostra lo **storico noto al plugin**, non una
  scansione completa di repository importati da altre installazioni.
- SFTP e WebDAV elencano cartelle complete: la gestione di directory WebDAV
  oltre 10.000 elementi o risposte oltre 8 MiB viene rifiutata esplicitamente.
  Per repository molto grandi preferire S3 con elenco paginato.
- Versioning e Object Lock del bucket possono mantenere versioni e costi
  anche dopo la retention. Il plugin non modifica policy o ACL del bucket.
- TLS protegge il trasporto; questa versione non aggiunge cifratura end-to-end
  degli archivi a riposo.

Comandi nuovi (l'alias `wp sftp-backup` resta valido):

```text
wp stream-backup repositories
wp stream-backup run --budget=30
wp stream-backup status --format=json
wp stream-backup test --repository=ID
wp stream-backup test --repository=ID --allow-public-probe
wp stream-backup list --repository=ID
wp stream-backup retry
wp stream-backup abandon
```

### Ripristino del formato a parti

Scarica **tutti** i file della generazione scelta da una destinazione riuscita,
inclusi cataloghi, descrittori, manifest e script. In File Drop i nomi hanno
il prefisso `<backup>--<generazione>--`: scaricali tramite l'account proprietario,
non tramite il link solo-caricamento. Conserva i nomi dei dati; puoi rinominare
lo script in `restore.php` e il manifest in `manifest.json`.

Carica i file nella destinazione vuota e usa la procedura browser/CLI descritta
sotto. Il lettore verifica cataloghi e parti prima di consumarli e supporta
la ripresa; non serve spazio aggiuntivo per un archivio ricomposto. Servono
comunque spazio per file ripristinati e database. Il restore non scarica
automaticamente dai repository.

## Come funziona la modalita SFTP originale

```
MySQL ──► Dumper SQL ──┐
                       ├─► gzip (deflate in RAM) ──► buffer 1 MB ──► SFTP put(offset) ──► server remoto
File  ──► Walker + tar ┘
```

- L'archivio non esiste mai in locale: i dati passano in memoria da database/file a zlib e alla connessione SFTP (phpseclib 3, PHP puro, namespace prefissato con Strauss).
- Il lavoro è diviso in **passaggi** a tempo. Ogni passaggio aggiunge in coda al file remoto una o più **parti gzip complete** (una nuova ogni 8 MB, così il ripristino può riprendere vicino a qualsiasi punto): più parti attaccate formano un unico stream valido, letto senza problemi da `gzip`, `tar` e `gzopen()`.
- Lo stato (posizione remota confermata + cursore di database/file) viene salvato in `wp_options` **solo dopo** che l'invio è riuscito. Se un passaggio si interrompe, il successivo accorcia il file remoto fino all'ultima posizione confermata (`truncate`) e riparte da lì. Un singolo file grande può occupare più passaggi.
- La scansione dei file è in ordine fisso, quindi per riprendere basta l'ultimo percorso elaborato: non si salva nessun elenco di file.
- Il passaggio successivo parte da una richiesta "a vuoto" del sito verso se stesso, autenticata con un token del job. In alternativa partono WP-Cron oppure la pagina admin aperta. Da WP-CLI tutto gira in un solo processo, con istantanea coerente del database.

Contenuto della cartella di ogni backup sul server:

| File | Contenuto |
|---|---|
| `database.sql.gz` | dump SQL (ogni istruzione termina con `;\n`, valori binari in esadecimale) |
| `files.tar.gz` | radice di WordPress in formato tar POSIX (ustar + pax per i nomi lunghi) |
| `restore.php` | script di ripristino indipendente |
| `manifest.json` | scritto per ultimo = backup completo (versioni, URL, prefisso, conteggi) |

## Struttura del progetto

```
wp-sftp-stream-backup/        plugin
  wp-sftp-stream-backup.php   file principale (bootstrap e autoload)
  src/Remote/                 SftpStorage (phpseclib), RemoteAppender, interfaccia RemoteStorage
  src/Stream/                 GzipMemberWriter
  src/Archive/                TarWriter
  src/Files/                  Walker (ordine fisso, ripresa da cursore), Exclusions
  src/Db/                     Dumper (paginazione sulla chiave primaria)
  src/Job/                    Runner (macchina a stati), fasi Db/Files, Lock, Retention, Driver
  src/Admin/                  pagina Strumenti → SFTP Backup e endpoint AJAX
  src/Cli/                    comandi `wp sftp-backup …`
  src/Security/Secrets.php    cifratura delle credenziali (libsodium)
  restore/restore.php         ripristino via browser o CLI
  tests/                      PHPUnit
dev/                          build, Docker e test di integrazione
dist/                         pacchetto pronto (cartella + zip), generato da dev/build.ps1
```

## Installazione

### 1. Requisiti

| Requisito | Dettagli |
|---|---|
| WordPress | 5.4 o successivo, installazione singola (Multisite non supportato) |
| PHP | 7.4 o successivo, con `zlib` e `mysqli`; per S3/Nextcloud serve supporto HTTPS (cURL o OpenSSL) |
| Rete | connessioni in uscita verso la destinazione: SFTP (di solito porta 22) oppure HTTPS 443 per S3/Nextcloud |
| Destinazione | server SFTP, bucket S3 privato gia creato oppure cartella/link Nextcloud |
| Permessi | utente WordPress amministratore (`manage_options`) |

Non serve spazio libero locale per l'archivio. Servono pero spazio sufficiente
sulla destinazione remota e, al momento del ripristino, sul nuovo server.

### 2. Scarica il pacchetto

Scarica **`wp-sftp-stream-backup.zip`** dalla pagina
[Releases](https://github.com/radicisoluzioni/wp-sftp-stream-backup/releases/latest).

Usa lo ZIP pubblicato nella release, non il pulsante *Code → Download ZIP*:
il sorgente del repository non contiene le dipendenze prefissate che il plugin
richiede in esecuzione.

### 3. Installa il plugin

**Da WordPress (consigliato)**

1. Vai in *Plugin → Aggiungi nuovo plugin → Carica plugin*.
2. Seleziona `wp-sftp-stream-backup.zip` e clicca *Installa ora*.
3. Clicca *Attiva plugin*.

**Manualmente via SFTP/FTP o pannello hosting**

1. Estrai lo ZIP sul tuo computer.
2. Carica la cartella `wp-sftp-stream-backup` in `wp-content/plugins/`.
3. Attiva *WP SFTP Stream Backup* da *Plugin → Plugin installati*.

**Con WP-CLI**

```bash
wp plugin install /percorso/wp-sftp-stream-backup.zip --activate
```

Dopo l'attivazione trovi due pagine nel menu *Strumenti*:

- **SFTP Backup**: avvio manuale, stato, pianificazione, esclusioni, notifiche ed elenco backup;
- **Backup repositories**: destinazioni SFTP, S3 e Nextcloud.

### 4. Configura la prima destinazione

Per usare piu destinazioni apri *Strumenti → Backup repositories*:

1. In *Add repository* scegli SFTP, S3 compatible o Nextcloud e clicca
   *Save repositories*: comparira una nuova scheda.
2. Compila i campi, clicca *Save repositories* e poi *Test saved connection*:
   il test usa sempre le impostazioni salvate.
3. Attiva le destinazioni desiderate e scegli un **repository principale leggibile**
   (SFTP, S3 o Nextcloud autenticato). Le altre destinazioni attive diventano repliche.
4. Imposta la retention per ogni destinazione (`0` = conserva tutto).

Promemoria rapido per provider:

| Tipo | Cosa preparare |
|---|---|
| SFTP | host, porta, utente, password o chiave privata, cartella remota. Verifica il fingerprint con il provider o sul server (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`) prima di confermarlo. |
| Amazon S3 | bucket privato esistente, regione, access key/secret key con permessi di lettura, scrittura, elenco e cancellazione sul prefisso. |
| Hetzner / DigitalOcean Spaces | bucket e Location; se l'endpoint resta vuoto viene usato quello predefinito della Location. |
| Aruba o S3 personalizzato | endpoint, regione di firma, bucket e chiavi forniti dal pannello del provider. |
| Nextcloud autenticato | URL istanza, utente, **password applicazione** (non la password principale) e cartella di destinazione. |
| Nextcloud File Drop | link pubblico completo `/s/TOKEN` ed eventuale password; usalo solo come replica, perche non e leggibile dal plugin. |

Le installazioni aggiornate dalla modalita SFTP 1.0 continuano a funzionare
dalla pagina *SFTP Backup* finche non salvi la pagina repository.

### 5. Esegui e verifica il primo backup

1. Apri *Strumenti → SFTP Backup* e clicca *Back up now*.
2. Lascia aperta la pagina fino al completamento: i passaggi successivi partono
   automaticamente e l'avanzamento mostra anche lo stato delle repliche.
3. Controlla che il backup compaia nell'elenco e che sulla destinazione ci siano
   `manifest.json`, `restore.php` e i dati del database e dei file.
4. Se usi repliche, verifica che non restino copie in stato *partial*.

Prima di affidarti al plugin, prova almeno un ripristino su un ambiente separato.

### 6. Pianifica i backup

Dalla pagina *SFTP Backup* puoi attivare la pianificazione WP-Cron. WP-Cron
dipende dalle visite al sito: per siti grandi o con poco traffico usa un cron
di sistema con WP-CLI, che produce anche un dump coerente del database:

```cron
0 3 * * * cd /percorso/wordpress && wp stream-backup run --quiet
```

### Credenziali in `wp-config.php` (opzionale)

Per la modalita SFTP originale puoi evitare di salvare le credenziali dalla UI:

```php
define( 'WPSSB_SFTP_HOST', 'backup.example.com' );
define( 'WPSSB_SFTP_PORT', 22 );
define( 'WPSSB_SFTP_USERNAME', 'utente' );
define( 'WPSSB_SFTP_AUTH', 'key' ); // oppure 'password'
define( 'WPSSB_SFTP_PRIVATE_KEY_FILE', '/home/utente/.ssh/backup_key' );
define( 'WPSSB_SFTP_FINGERPRINT', 'SHA256:...' );
define( 'WPSSB_SFTP_PATH', 'backups/miosito' );
```

Le credenziali salvate dalla UI sono cifrate. Puoi definire anche
`WPSSB_ENCRYPTION_KEY`; conservala, perche senza la stessa chiave le credenziali
cifrate non sono piu leggibili. Le opzioni avanzate sono descritte in `readme.txt`
e nella sezione [Repository multipli](#repository-multipli-11).

### Comandi WP-CLI

```text
wp stream-backup repositories
wp stream-backup test --repository=ID
wp stream-backup run [--budget=120] [--resume]
wp stream-backup status [--format=json]
wp stream-backup list [--repository=ID]
wp stream-backup retry
wp stream-backup abandon
wp stream-backup cancel
wp stream-backup restore <percorso> [--repository=ID] [--yes]
wp stream-backup migration-code <percorso> [--repository=ID] [--days=7] [--installer=FILE]
```

L'alias storico `wp sftp-backup` resta disponibile.

### Aggiornamento e disinstallazione

- **Aggiornamento**: carica lo ZIP della nuova release da *Plugin → Aggiungi nuovo plugin → Carica plugin* e conferma la sostituzione. Evita di aggiornare durante un backup.
- **Disattivazione**: interrompe le pianificazioni ma conserva impostazioni e backup remoti.
- **Eliminazione**: rimuove impostazioni e stato del plugin dal database. I backup remoti non vengono cancellati.

### Risoluzione problemi

| Problema | Controllo consigliato |
|---|---|
| Timeout o connessione rifiutata | verifica firewall dell'hosting, porta 22 per SFTP o 443 per HTTPS |
| Fingerprint diverso | non confermarlo finche il provider non ha verificato il cambio di chiave |
| Errore S3 di firma o accesso negato | controlla endpoint, regione, bucket, chiavi e permessi sul prefisso |
| Nextcloud rifiuta l'accesso | crea una password applicazione e verifica il percorso della cartella |
| Backup fermo con WP-Cron | usa *Back up now* o configura il cron di sistema |
| Nuovi backup bloccati | risolvi le repliche fallite con *Retry failed copies* o abbandonale esplicitamente |
| Memoria esaurita con tabelle molto grandi | aumenta `memory_limit` di PHP |

## Ripristino one click (1.2)

Dalla 1.2 il ripristino non richiede piu di scaricare e caricare archivi: il
backup viene letto **in streaming direttamente dal repository** (SFTP, S3 o
Nextcloud WebDAV; non da un File Drop pubblico, che e solo in scrittura).

### Rollback del sito

1. Apri *Strumenti → SFTP Backup* e premi *Load list* in *Backups on the server*.
2. Premi **Restore** sulla riga del backup e digita il dominio del sito per confermare.
3. Si apre la pagina di ripristino (temporanea, protetta da token): premi
   *Start restore* e lascia aperta la pagina fino al termine.

Da shell, ad esempio da cron o SSH:

```bash
wp stream-backup list --repository=ID
wp stream-backup restore <percorso-backup> --repository=ID --yes
```

Cosa succede:
- il sito resta in manutenzione durante il ripristino;
- file e tabelle **non presenti** nel backup vengono rimossi, ma percorsi e tabelle esclusi restano intatti;
- `wp-config.php`, impostazioni del plugin, repository e storico dei backup vengono mantenuti;
- l'operazione e a passaggi e riprendibile (*Retry*); a fine lavoro i file temporanei `wpssb-restore-*` si cancellano da soli;
- **non** viene fatta una copia di sicurezza automatica: se serve, lancia prima un backup.

### Migrazione su un nuovo server

1. Sul sito di origine premi **Migrate** sulla riga del backup, scegli una password
   (minimo 12 caratteri) e una validita (1–30 giorni).
2. Premi *Download installer with this code*: ottieni `wpssb-installer.php` con il
   codice di migrazione cifrato (AES-256-GCM, chiave derivata dalla password).
3. Sul nuovo server crea un database vuoto e carica `wpssb-installer.php` nella radice web vuota.
4. Apri `https://nuovo-sito/wpssb-installer.php`, inserisci password e dati del
   database: il backup viene scaricato in streaming, gli URL vengono riscritti e
   l'installer si cancella da solo.

In alternativa da shell sul nuovo server:

```bash
WPSSB_MIGRATION_PASSWORD='la-password' php wpssb-installer.php \
  --db-host=localhost --db-name=DB --db-user=UTENTE --db-pass=PASS \
  --url=https://nuovo-sito
```

Il codice si puo creare anche da WP-CLI:

```bash
wp stream-backup migration-code <percorso-backup> --repository=ID --days=7 --installer=wpssb-installer.php
```

**Installer generico**: ogni release pubblica anche `wpssb-installer.php` senza
codice incorporato. Si usa allo stesso modo, incollando il codice `WPSSB1...` nel
modulo web oppure passando `--code=WPSSB1...` da shell.

> Il codice contiene le credenziali di lettura del repository, cifrate con la
> password. Conserva codice e password separati, usa una validita breve e, dove
> possibile, credenziali di sola lettura.

## Ripristino manuale

Resta disponibile per backup scaricati a mano o senza accesso al repository.

1. Scarica dalla cartella del backup `files.tar.gz`, `database.sql.gz`, `manifest.json` e `restore.php`.
2. Caricali nella radice web (vuota) della destinazione e crea un database vuoto.
3. Da browser: imposta `WPSSB_RESTORE_PASSWORD` in cima a `restore.php` e apri `https://sito/restore.php`.
   Da shell: `php restore.php --db-host=localhost --db-name=DB --db-user=UTENTE --db-pass=PASS --url=https://nuovo-sito [--delete-archives] [--self-delete]`
4. Lo script lavora a passaggi e riprende da dove si era fermato, anche dopo un crash: se un passaggio dell'import SQL si interrompe, la tabella in corso viene reimportata da capo, e l'avanzamento della sostituzione URL è salvato nel database nella stessa transazione delle modifiche. Un lock impedisce che due richieste lavorino insieme. In ordine:
   - estrae i file, con protezione da percorsi `../` e link simbolici;
   - importa il database;
   - sostituisce vecchio URL e vecchio percorso anche nei dati serializzati e JSON, senza deserializzare oggetti (i GUID restano invariati);
   - aggiorna `wp-config.php`;
   - si cancella da solo.

Il ripristino manuale funziona comunque: `tar xzf files.tar.gz` e `gunzip < database.sql.gz | mysql -u UTENTE -p DB`.

## Sviluppo e test

Requisito: Docker. PHP e Composer non servono sull'host.

```powershell
# build: composer + Strauss nel container, poi crea dist\wp-sftp-stream-backup(.zip)
powershell -ExecutionPolicy Bypass -File dev\build.ps1

# test unitari (PHP 7.4 e 8.3)
docker run --rm -v "${PWD}\wp-sftp-stream-backup:/app" -w /app php:7.4-cli php vendor/bin/phpunit
docker run --rm -v "${PWD}\wp-sftp-stream-backup:/app" -w /app php:8.3-cli php vendor/bin/phpunit

# test end-to-end: WordPress sorgente → atmoz/sftp → ripristino web e CLI su database nuovi
powershell -ExecutionPolicy Bypass -File dev\integration.ps1

# SFTP + MinIO (S3) + Nextcloud reale, incluse share pubbliche e ripristino a parti
# default: aggiunge un file sorgente casuale di 512 MiB; -Quick lo omette
powershell -ExecutionPolicy Bypass -File dev\storage-integration.ps1

# ripristino one click: rollback da Nextcloud, S3 (runtime web) e SFTP, migrazioni
# con installer incorporato (CLI) e generico (web, in una sotto-cartella)
powershell -ExecutionPolicy Bypass -File dev\oneclick-integration.ps1
```

La suite multi-repository usa credenziali effimere locali, testa anche un upload
File Drop accettato ma senza conferma, il fallimento di una sola destinazione,
la ripresa dalla stessa copia principale e i checksum dopo il ripristino.
Per impostazione predefinita elimina i propri container/volumi di test;
`-KeepRunning` li conserva per ispezione. Non usare questi script su ambienti
Docker di produzione: ricreano il progetto di integrazione `wpssb-dev`.

I trasporti sono verificati su MinIO e Nextcloud reale. Senza credenziali
autorizzate dei servizi commerciali non si dichiara una certificazione su
Amazon, Hetzner, Spaces o Aruba: endpoint e permessi vanno provati sul proprio
account tramite il test connessione.

Cosa verifica il test end-to-end:
- connessione: rifiuto dell'host sconosciuto, conferma del fingerprint, rilevamento di un fingerprint diverso, autenticazione con chiave;
- backup da CLI e da web (catena di richieste "a vuoto") con passaggi di 3 secondi;
- annullamento mentre un passaggio è in corso (il job non "risorge" e la cartella remota parziale viene cancellata);
- nessun file scritto in locale durante il backup (il plugin è montato in sola lettura);
- retention, `gzip -t` e `tar -t` sugli archivi remoti;
- pagina admin, endpoint AJAX e rifiuto delle richieste non autorizzate;
- ripristino da browser e da CLI: checksum delle tabelle, ID zero, timestamp, URL nei dati serializzati e JSON, GUID invariati, tutti i file identici, sito funzionante;
- ripristino interrotto (`SIGKILL` o crash dopo il commit) decine di volte durante l'import SQL e la sostituzione degli URL: nessuna riga duplicata e nessun URL sostituito due volte, anche quando il nuovo URL contiene quello vecchio.

## Limiti noti

- Solo installazioni singole (niente Multisite).
- Le parti dei file sono limitate a 8 MiB, ma la memoria necessaria al dump dipende anche dalle dimensioni delle righe del database. Record o BLOB molto grandi possono richiedere un `memory_limit` PHP maggiore; non vengono spostati su file temporanei.
- Il dump eseguito da browser o WP-Cron, diviso in più richieste, non è un'istantanea coerente; da WP-CLI lo è (InnoDB). Le tabelle vengono lette a pagine sulla chiave primaria (o su un indice univoco `NOT NULL`); le rare tabelle senza alcuna chiave usano `LIMIT/OFFSET` e, se modificate durante il backup web, possono perdere o duplicare righe.
- Non vengono esportati viste, trigger e stored procedure; una cartella `wp-content` esterna alla radice non viene inclusa.
- Il ripristino mantiene il prefisso originale delle tabelle.
- Su alcuni hosting le connessioni in uscita sulla porta 22 sono bloccate: *Test connection* lo segnala subito.
