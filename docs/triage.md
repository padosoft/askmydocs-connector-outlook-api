# Bridge email e triage v1

Microsoft Mail consegna l'acquisizione al modulo triage già esistente nell'host. Non implementa profili, un secondo classificatore, carte o campagne. Lo standard congelato è [STANDARD.md](specs/2026-10-14-email-api/STANDARD.md).

## Sequenza del messaggio

1. Recuperare messaggio completo, headers e tutti gli attachment osservati.
2. Applicare selezione, filtri e policy; rendere il body definitivo e consultare la redazione host.
3. Ordinare gli attachment per identità case-sensitive e preparare manifesto, body hash e revision hash.
4. Emettere **sincronicamente** `askmydocs.email.prepared.v1` con un solo envelope.
5. Scrivere body e attachment accettati, verificarne il risultato e passare gli stessi metadati al bridge di ingestione.
6. Salvare ricevute operative e completare elemento/pagina solo dopo i dispatch riusciti.

Il listener non deve implementare `ShouldQueue`. Se triage è abilitato, deve salvare il manifesto prima di ritornare; una sua eccezione si propaga e impedisce write, dispatch e avanzamento del checkpoint per quell'elemento. Con triage spento la registrazione è un no-op.

## Binding del consumer

Ogni applicazione Laravel consumer deve associare la propria implementazione a `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. Il bridge risolve paths/disk, redazione, dispatch durevole, audit e cancellazioni locali. Il pacchetto non referenzia `App`, job host o modelli di documenti.

Un consumer che offre triage registra il listener ordinario per nome evento:

```php
use Illuminate\Support\Facades\Event;

Event::listen('askmydocs.email.prepared.v1', function (array $envelope): void {
    // Validare schema, tenant, installazione, progetto, provider e hash.
    // Se triage è acceso, salvare manifesto e revisione nella stessa chiamata.
    // Se spento, ritornare. Gli errori reali devono essere propagati.
});
```

Il codice mostra la firma del contratto, non un listener pronto per la produzione. L'host deve mantenere autorizzazione, ACL, idempotenza, snapshot e stato delle ricevute; la suite del pacchetto verifica un listener/bridge fake conforme. In assenza di listener, l'ingestione normale resta disponibile ma il consumer non offre triage v1.

## Envelope sincrono

Il pacchetto invoca il dispatcher Laravel ordinario così:

```php
Event::dispatch('askmydocs.email.prepared.v1', [[
    'schema_version' => 1,
    'tenant_id' => $tenantId,
    'installation_id' => $installationId,
    'project_key' => $projectKey,
    'metadata' => $bodyMetadata,
    'email_date' => $isoDateOrNull,
    'manifest' => $manifest,
]]);
```

`tenant_id` e `project_key` sono stringhe esplicite dell'installazione; `installation_id` è intero. Il listener confronta il contesto autorizzato con l'envelope e i metadati, senza fidarsi del contenuto dell'email per scegliere il tenant.

### Metadati su body e attachment

| Campo | Contenuto |
| --- | --- |
| `connector`, `email_provider` | `microsoft-mail` |
| `installation_id`, `email_schema_version` | ID intero dell'installazione; versione `1` |
| `email_mailbox_id` | Directory verificata e ID persistente account/target |
| `email_message_id` | ID nativo immutabile, stringa case-sensitive |
| `email_message_key` | Chiave parent composta |
| `email_folder_ids`, `email_folder_names` | ID e nomi delle cartelle correnti |
| `email_date` | receivedDateTime ISO8601 oppure null |
| `email_attachment_count` | Tutti gli slot attachment osservati, inclusi gli esclusi |
| `email_body_hash` | SHA256 del Markdown definitivo dopo redazione |
| `email_revision_hash` | Hash del body e manifesto semantico |

Il builder source-aware usa la sorgente `microsoft_mail`; metadati nativi e segnali `_derived` sono proiettati in `converter_hints`. Preserva headers RFC Message-ID, In-Reply-To e References, sender/From, To/Cc/Bcc disponibili, date Graph, conversationId, folder, categories, importance, flag e isRead. Gli ID provider non diventano tag di ricerca automatici.

Ogni attachment mantiene i tre campi parent key/body hash/revision hash e aggiunge `email_attachment_id`, `email_attachment_slot`, `email_attachment_hash`, `attachment_filename` e `attachment_of_message_id`. L'estensione additiva `email_attachment_source_key` identifica parent key e path di una singola fonte attachment per il cleanup locale; non entra nel calcolo congelato della revisione. `microsoft_mail_doc_key` è un alias della parent key.

### Chiave composta congelata

```php
$identity = [$tenantId, $installationId, 'microsoft-mail', $mailboxId, $nativeMessageId];
$emailMessageKey = 'microsoft-mail:'.hash('sha256', json_encode(
    $identity,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
));
```

Tenant, connector key, mailbox e native ID sono stringhe; installation è intero. Folder, subject, indirizzi, category, read e flag non partecipano alla chiave. La cancellazione usa `email_message_key`, mai un ID Graph raw. I paths dipendono da hash e installazione: rename e move nella stessa mailbox non creano un nuovo body; gli omonimi mantengono ID/slot distinti.

## Manifesto e revisione

Il manifesto è una lista ordinata di slot `{slot, id, name, mime, hash, status, reason}`. Prima di assegnare slot gli attachment sono ordinati per ID con confronto stringa case-sensitive. Per un ID nativo mancante viene usato un part path sintetico deterministico; parti annidate mantengono la propria identità gerarchica.

| Esito | Hash e stato preregistrati |
| --- | --- |
| Attachment accettato | SHA256 dei bytes che saranno persistiti; `status=pending`, `reason=null` |
| Attachment escluso | `hash=null`, `status=excluded`, motivo esplicito |

Esempi di motivo sono `inline_attachment`, `extension_not_allowed`, `attachment_size_limit`, `attachment_count_limit`, `message_attachment_byte_limit`, `attachment_depth_limit`, `reference_attachment_not_downloaded`, `unsupported_nested_item`, `mime_mismatch` e `empty_attachment`.

Il calcolo della revisione è identico per Gmail e Microsoft Mail:

```php
$manifestIdentity = array_map(static fn (array $f): array => [
    $f['slot'], $f['id'], $f['name'], $f['mime'], $f['hash'],
    $f['status'] === 'excluded' ? $f['reason'] : null,
], $manifest);
$emailRevisionHash = hash('sha256', json_encode(
    [$emailBodyHash, $manifestIdentity],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
));
```

L'hash non include snapshot path o stati saved/refused. Un aggiornamento dei flag/cartella non cambia la revisione se body e manifesto sono identici. Il listener deve conservare stati terminali ed evaluation della stessa revisione; una revisione diversa non riusa ricevute di attachment con contenuto precedente.

## Ricevute, readiness e replay

Le ricevute body/attachment nell'host devono conservare parent key, body hash e revision hash. Il bridge scrive sul path `absolute` risolto e passa il path `relative` al job/outbox, inclusi gli eventuali prefissi del disk host. Una ricevuta attachment si associa a ID/slot e hash, non al filename. Readiness richiede il body e tutti gli slot accettati terminali; il solo numero degli allegati non è una prova sufficiente.

Un attachment salvato, escluso dall'estrazione o rifiutato esplicitamente da OCR è un terminale con coverage visibile. Un errore reale di fetch, write o dispatch blocca il batch. Le ricevute obsolete devono essere rifiutate e i job di una revisione vecchia non possono proiettare sul nuovo originale.

Il replay riusa i dispatch già confermati e il listener non riapre terminali/evaluation della stessa revisione. Il bridge host è responsabile della deduplica della decisione su contenuto/manifesto/profilo e del riuso dopo un errore di indicizzazione. La ricevuta operativa di dispatch del pacchetto non attesta che il job host sia già terminato.

Con reconciliation attiva, le fonti attachment superate vengono ritirate dopo che i documenti correnti sono stati accettati durevolmente. Il bridge deve supportare il lookup esatto per `email_attachment_source_key` oltre al parent `email_message_key`. Con reconciliation spenta le fonti precedenti restano locali; il prepared-event e il manifesto della revisione corrente mantengono la semantica v1. [Compatibilità host](compatibility.md) riporta i casi concreti da verificare.

## Responsabilità host e limiti della verifica

Profili opt-in, assistente di configurazione, regole Jev, carta e tag, snapshot immutabili, queue dedicata, retry, costi, campagne retroattive, pause/resume, ACL e versioning restano nel modulo email comune AskMyDocs. La provenienza del connettore è `UntrustedExternal` per default; un login OAuth o il dominio del mittente non promuovono l'autorità delle email. Gli attachment ereditano scope e ACL del parent.

Il pacchetto rende disponibile il contratto completo e lo verifica in Testbench con fixture sintetiche, senza mailbox, triage attivato o AI pagata. L'adattatore reale AskMyDocs per Gmail/Microsoft Mail e i test host end-to-end sono mantenuti nel lavoro separato Gmail. La presenza di questa documentazione non attesta che tale integrazione sia già stata eseguita nel checkout host.
