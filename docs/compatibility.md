# Compatibilità del consumer e dell'host AskMyDocs

Il pacchetto dipende da connector-base ^1.6 e dal contratto email v1 congelato. Può essere installato in un consumer Laravel senza riferimenti runtime ad `App`. Il suo successo Testbench non equivale all'integrazione end-to-end del listener e della pipeline AskMyDocs, mantenuti separatamente nel lavoro Gmail.

## Requisiti del bridge

Il consumer deve registrare la propria implementazione di `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. Non servono patch ai sorgenti sotto vendor. Questi comportamenti sono necessari:

| Punto del contratto | Comportamento richiesto |
| --- | --- |
| `resolveKbSourcePath()` | Restituire disk, `absolute` da scrivere/eliminare e `relative` da consegnare al dispatch, anche quando il disk usa un prefisso host |
| `redactContent()` | Applicare la policy PII host prima degli hash; deve essere consultabile senza dipendere da un classificatore AI nel download |
| `dispatchIngestion()` | Accettare durevolmente il documento prima di ritornare; propagare errori reali e deduplicare replay della stessa fonte/revisione |
| `emitAudit()` | Ricevere eventi e metriche senza contenuti, credenziali o cursor URL |
| `softDeleteByRemoteId()` | Cercare esattamente il metadata key/value richiesto, con tenant/installazione/progetto autorizzati e scope parent/allegato coerente |

Il ritorno `void` del dispatch è usato dal pacchetto come ricevuta di consegna al bridge. Un consumer che accoda in memoria e ritorna prima di un outbox/queue durevole può perdere documenti dopo il checkpoint: deve rendere durevole la consegna o sollevare un errore. Questa ricevuta non attesta che parser, OCR, indicizzazione o triage siano già completati.

### Caso: prefisso delle fonti

Un bridge può risolvere `connectors/microsoft-mail/.../body.md` come `absolute=sources/connectors/microsoft-mail/.../body.md` e mantenere `relative=connectors/microsoft-mail/.../body.md`. Il pacchetto deve usare il primo per Storage e il secondo per dispatch. Un fake che restituisce lo stesso path per entrambi non verifica questa compatibilità: il test deve usare un prefisso distinto e controllare la posizione del file e il payload consegnato.

### Caso: rimozione composta

Per una cancellazione o uscita dallo scope del messaggio, il pacchetto passa `metadataKey=email_message_key` e la chiave parent composta. Il bridge deve ritirare il body e tutti gli allegati collegati; il raw message ID o il solo tenant non sono identità sufficienti tra installazioni/account.

Per un attachment superato da una revisione, con reconciliation abilitata, il pacchetto passa `metadataKey=email_attachment_source_key`. Il valore è:

```php
$sourceKey = 'microsoft-mail-attachment:'.hash('sha256', json_encode(
    [$emailMessageKey, $attachmentSourcePath],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
));
```

Questa estensione additiva sfrutta i parametri metadata key/value già presenti nel contratto pubblico del base. Un bridge che consente solo una lista fissa di ID IMAP deve aggiungere il lookup esatto per queste due chiavi composte. Non occorre cambiare il manifesto v1, i suoi hash o il collegamento parent dei documenti.

Il caso da verificare è una email con due file omonimi: una nuova revisione elimina uno slot o ne cambia il path. Dopo l'accettazione durevole dei documenti correnti, si ritira soltanto la vecchia fonte indicata dalla source key; body e attachment corrente rimangono attivi. Un errore di cleanup lascia un backlog riprendibile senza completare il messaggio. Con reconciliation disabilitata, le fonti superate vengono conservate localmente.

## Listener triage sincrono

Per offrire triage, l'host registra un listener ordinario di `askmydocs.email.prepared.v1`, con firma `function (array $envelope): void`. Non deve implementare `ShouldQueue`. Valida schema, tenant, installazione, progetto, provider e hash; quando triage è acceso salva manifesto/revisione prima di ritornare. Un'eccezione interrompe il messaggio prima delle write e dei dispatch.

Il caso da verificare è un listener che fallisce sul secondo messaggio di una pagina: non deve esistere una fonte o un dispatch per quel messaggio, né un cursor commit che lo scavalchi. Un replay della stessa revisione deve riusare terminali ed evaluation invece di duplicare AI o riaprire allegati conclusi.

Il modulo host deve mantenere la readiness del body, ricevute attachment per ID/slot/hash, guard dei job obsoleti, snapshot immutabili, profili opt-in, costi, retry e campagne. [La guida triage](triage.md) mostra envelope e algoritmi congelati. L'assenza del listener non impedisce l'ingestione ordinaria, ma quel consumer non offre triage v1.

## PII e strumenti di connessione

La policy PII viene sempre consultata dal bridge, inclusi body e item attachment resi in Markdown. Il contratto pubblico `redactContent()` non espone una preferenza per installazione: la redazione dipende dalla policy host e non ha un toggle o bypass nel pacchetto. Il pacchetto non redige in place il contenuto upstream.

Le capability pubbliche di diagnostica sono `health()`, `listAvailableFolders()` e `folderMapping()`. Il base non offre qui una capability neutra per mostrare un campione email API senza import: non viene dichiarato un probe di messaggio completo. Un host deve aggiungerlo esplicitamente usando le API del provider, senza inoltrare Microsoft Mail a strumenti IMAP, salvare fonti, avanzare cursori o attivare AI.

## Verifiche separate

La suite del pacchetto verifica fixture e fake con richieste mutative/non simulate proibite, prepared prima delle write, replay, storage prefix e identità composte. L'host deve eseguire i propri test parametrizzati IMAP/Gmail/Microsoft Mail con triage on/off, receipts/readiness, ACL, tag, versioning, retry e campagne. Il collaudo live OAuth verifica a parte registrazione, consenso, policy tenant, deleghe e accesso mailbox reali.
