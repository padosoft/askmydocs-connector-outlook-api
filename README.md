<p align="center">
  <img src="docs/readme/logo.svg" width="120" alt="Marchio AskMyDocs Microsoft Mail: una busta collegata ai documenti">
</p>

<p align="center">
  <img src="docs/readme/header.png" width="760" alt="Il robot trasferisce il contenuto completo delle email dalla mailbox al sistema documentale">
</p>

<h1 align="center">AskMyDocs Microsoft Mail</h1>

<p align="center">Acquisisci posta Outlook.com e Microsoft 365 tramite Microsoft Graph, con ripresa per cartella e metadati pronti per il triage comune AskMyDocs.</p>

**Composer:** `padosoft/askmydocs-connector-microsoft-mail` · **Connector key:** `microsoft-mail` · **Licenza:** [Apache 2.0](LICENSE)

**Repository:** [padosoft/askmydocs-connector-outlook-api](https://github.com/padosoft/askmydocs-connector-outlook-api)

## Dalla mailbox ai documenti

Il pacchetto legge il body completo, gli headers e gli allegati consentiti, rende ogni messaggio in Markdown e lo consegna al `ConnectorIngestionContract` dell'applicazione Laravel. OAuth è delegato, offline e in sola lettura. Outlook.com usa la mailbox personale; Microsoft 365 può usare una mailbox condivisa scelta esplicitamente.

Il triage usa il contratto condiviso `askmydocs.email.prepared.v1`: il manifesto degli allegati e gli hash della revisione arrivano al listener host **prima di qualsiasi write o dispatch**. Profili, assistente, carte, tag, snapshot, code, retry AI, costi e campagne appartengono al modulo host. Il worker di download non esegue classificazioni AI.

![Il robot scopre le cartelle annidate e seleziona quelle da acquisire](docs/readme/scene-concept.png)

| Cosa acquisisci | Come viene conservato |
| --- | --- |
| Cartelle, anche annidate e omonime | Discovery ricorsiva paginata; selezione per ID, mapping ID/nome/percorso |
| Body e headers completi | `text/markdown`, threading RFC e dati nativi sotto `converter_hints.microsoft_mail` |
| Allegati file, item, reference e inline | ID e slot stabili; ogni esclusione compare nel manifesto con il motivo |
| Storico e aggiornamenti | Baseline e delta separati per cartella; continuation opache e checkpoint riprendibili |
| Messaggi spostati | Immutable ID e chiave composta invarianti nella stessa mailbox |
| Più installazioni e account | Token e lease per tenant/installazione; stato, paths e cancellazioni isolati anche per mailbox |

## Installazione locale

Sono richiesti PHP **^8.3**, estensioni `dom`, `fileinfo`, `mbstring`, `openssl`, Laravel **12 o 13** e `padosoft/askmydocs-connector-base` **^1.6**. Laravel 13 richiede un runtime PHP compatibile con i suoi requisiti. L'applicazione deve configurare database, filesystem, cache con lock e un bridge di ingestione.

Questo checkout è una consegna locale: non è dichiarata una release remota. Nell'applicazione consumer, collega il repository locale e installa il pacchetto:

```bash
composer config repositories.microsoft-mail path /percorso/askmydocs-connector-microsoft-mail
composer require 'padosoft/askmydocs-connector-microsoft-mail:@dev'
php artisan vendor:publish --tag=connector-microsoft-mail-config
php artisan vendor:publish --tag=connector-microsoft-mail-assets
php artisan migrate
```

La repository path usa il checkout locale; Composer può collegarlo con symlink. Questi comandi sono istruzioni per il consumer: il lavoro sul pacchetto non ha modificato Composer, configurazione o database dell'host AskMyDocs. Composer registra automaticamente `MicrosoftMailServiceProvider` e la discovery del connettore. Le migrazioni aggiungono `microsoft_mail_sync_runs`, `microsoft_mail_sync_folders` e `microsoft_mail_sync_messages`; non salvano il contenuto delle email o i token.

Imposta le credenziali dell'app Microsoft nel server consumer:

```dotenv
MICROSOFT_MAIL_CLIENT_ID=application-client-id
MICROSOFT_MAIL_CLIENT_SECRET=secret-value
MICROSOFT_MAIL_REDIRECT_URI=https://askmydocsdev.test/api/admin/connectors/microsoft-mail/oauth/callback
MICROSOFT_MAIL_AUTHORITY=common
```

L'URL mostrato corrisponde alla route OAuth dell'host AskMyDocs e allo schema Herd locale verificato. In altri consumer usa la loro callback registrata. [La guida OAuth](docs/oauth.md) spiega app registration, account consentiti, scope e mailbox condivise.

Avvia la connessione dall'host con il tenant attivo e un'installazione `microsoft-mail`. Il primo risultato utile è una `health()` sana e una discovery delle cartelle; non comportano ingestione. Le chiamate pubbliche usano l'ID di un'installazione già autorizzata dall'host:

```php
use Padosoft\AskMyDocsConnectorMicrosoftMail\MicrosoftMailConnector;

$connector = app(MicrosoftMailConnector::class);
$health = $connector->health($installationId);
$folderIds = $connector->listAvailableFolders($installationId);
$folders = $connector->folderMapping($installationId);

// Esegui nel worker host con tenant e progetto dell'installazione risolti.
$result = $connector->syncFull($installationId);
$status = $connector->syncStatus($installationId);
```

Ripeti il job finché lo stato dichiara il giro completo. `syncFull()` riprende una baseline incompleta, mentre promuove un incremental attivo a una nuova baseline. Dopo la baseline, `syncIncremental($installationId, $since)` segue il delta nativo; `$since` non sostituisce un deltaLink.

## Impostazioni per installazione

Il root server è sempre `connector-microsoft-mail`. Le scelte della singola connessione appartengono a `ConnectorInstallation::config_json`; token e credenziali sono nel vault cifrato del base.

```php
[
    'mailbox' => ['target_id' => null], // null = mailbox personale
    'folders' => ['include' => [], 'exclude' => [], 'include_hidden' => false],
    'sync' => ['date_window_days' => 0, 'max_messages' => 500],
    'filters' => [
        'only_unseen' => false,
        'skip_auto_generated' => true,
        'senders' => ['include' => ['example.org'], 'exclude' => []],
    ],
    'body' => ['format' => 'prefer_text', 'strip_quoted_history' => false],
    'attachments' => ['max_size_mb' => 25, 'max_per_email' => 20, 'skip_inline' => true],
    'reconciliation' => ['enabled' => false],
]
```

`folders.include` vuoto seleziona le cartelle consentite, escludendo di default Deleted Items e Junk Email e le loro discendenti. Include/exclude contengono ID case-sensitive, mai nomi localizzati o slug. Le esclusioni prevalgono sulle inclusioni. La finestra temporale viene applicata al client durante una baseline senza filtro delta sulla data, così lo storico può essere percorso interamente.

La redazione è sempre controllata dal bridge host: il contratto pubblico `redactContent()` non espone un toggle per installazione e non permette di bypassare la policy globale. I default sono visibili in [config/connector-microsoft-mail.php](config/connector-microsoft-mail.php). [La guida sync](docs/sync.md) descrive limiti, filtri, allegati, recovery e reconciliation.

## Ripresa senza perdere il punto

![Il robot marca il punto raggiunto dopo aver consegnato un gruppo di email e allegati](docs/readme/scene-workflow.png)

Ogni cartella conserva la pagina corrente, l'indice da riprendere e il link di continuazione separatamente dal deltaLink completato. Una write fallita, un listener che solleva un errore o un dispatch rifiutato fermano il batch. Il replay usa le ricevute della stessa revisione e mantiene i paths deterministici.

Le richieste Graph portano `Prefer: IdType="ImmutableId"`, anche quando seguono una continuation. Il trasporto accetta soltanto HTTPS su `graph.microsoft.com/v1.0`, verifica mailbox e collection e disabilita i redirect. Retry, bytes, pagine e tempo sono limitati. Un Retry-After troppo lungo rimanda il batch invece di trattenere indefinitamente il worker.

## Il contratto pronto per l'host

![Il robot consegna un body con allegati distinti e un manifesto completo al bridge host](docs/readme/scene-outcome.png)

Il body e ogni allegato condividono `email_message_key`, `email_body_hash` e `email_revision_hash`. Gli allegati aggiungono ID e slot: due file con lo stesso nome mantengono identità e ricevute distinte. La riconciliazione locale, opt-in, usa la chiave composta per il body e i suoi allegati. Gli allegati superati da una nuova revisione possono essere ritirati singolarmente tramite l'estensione `email_attachment_source_key`, dopo la consegna durevole dei nuovi documenti; con reconciliation spenta le fonti precedenti restano locali.

Un consumer Laravel senza listener può usare l'ingestione normale. Per offrire triage deve registrare il listener sincrono v1 e gestire le ricevute della stessa revisione nel modulo host. [La guida triage](docs/triage.md) contiene l'envelope esatto; [compatibilità host](docs/compatibility.md) descrive listener, dispatch durevole, paths prefissati e cancellazioni richiesti dal bridge.

La suite Testbench verifica il pacchetto con HTTP fake e bridge/listener sintetici. L'integrazione end-to-end AskMyDocs dipende dall'adattatore comune mantenuto separatamente nel lavoro Gmail; il collaudo OAuth su mailbox reali è una verifica distinta. Non viene dichiarato un successo live o una classificazione host completa dai soli test del pacchetto.

## Verifica e manutenzione

Dal repository del pacchetto, con dipendenze di sviluppo installate:

```bash
composer validate --strict
composer test
vendor/bin/pint --test
composer analyse
```

[Il rapporto di verifica locale](docs/verification.md) registra 95 test e 893 asserzioni per ciascuna combinazione collaudata. [La CI](.github/workflows/ci.yml) dichiara Laravel 12/PHP 8.3–8.4 e Laravel 13/PHP 8.4–8.5, con Testbench e PHPUnit abbinati. I test impediscono richieste HTTP non simulate, usano fixture sintetiche e non richiedono una mailbox o una chiamata AI pagata.

Il renderer e il filtro recuperano comportamento dagli helper Apache 2.0 del connettore AskMyDocs IMAP, adattandolo ai DTO Graph senza dipendenza runtime da IMAP, Webklex, OneDrive o classi `App`. [CHANGELOG](CHANGELOG.md) · [Specifica congelata v1](docs/specs/2026-10-14-email-api/STANDARD.md) · [Provenienza delle illustrazioni](docs/readme/ARTWORK.md)
