# Sincronizzazione, filtri e recovery

Ogni installazione conserva uno stato per mailbox e **per cartella**. La baseline iniziale usa il delta Graph senza filtro receivedDateTime; i giri successivi seguono il deltaLink committed della stessa cartella. La finestra temporale e i filtri vengono applicati al client sul messaggio completo.

Microsoft descrive delta come una sequenza di pagine con continuation opache fino al checkpoint finale. Il connettore non ricostruisce skiptoken né promette query arbitrarie `$search`/`$filter` sul delta. [Delta dei messaggi per cartella](https://learn.microsoft.com/en-us/graph/delta-query-messages).

## Prima esecuzione

Installa il pacchetto, pubblica config/asset ed esegui `php artisan migrate` nel consumer. Le migrazioni caricate dal provider creano:

| Tabella | Contenuto operativo |
| --- | --- |
| `microsoft_mail_sync_runs` | Giro attivo, fingerprint di selezione/config e completamento |
| `microsoft_mail_sync_folders` | Baseline, delta committed, continuation, pagina e indice da riprendere |
| `microsoft_mail_sync_messages` | Identità, folder corrente, hash/proiezione, paths e ricevute di dispatch |

Le tabelle non conservano body, headers o token. Tenant, installation e mailbox delimitano le query e la chiave scope. La cache deve supportare lock condivisi tra i worker che operano sullo stesso database: un giro usa un lease per tenant/installazione, così il cambio di mailbox non permette due worker concorrenti sulla stessa installazione. Lo stato persistito rimane separato per mailbox.

`syncFull($installationId)` avvia una baseline nuova oppure riprende una baseline incompleta. Se è attivo un giro incremental, la richiesta full lo promuove a una nuova baseline. `syncIncremental($installationId, $since)` usa il delta nativo, riprendendo comunque un giro in corso. Il timestamp previsto dall'interfaccia base non sostituisce i cursori Graph. Il risultato contiene i conteggi del **batch**; `syncStatus()` espone il completamento del giro. L'audit `sync_batch_completed` distingue `complete=false` da `complete=true`.

## Cartelle e selezione

`listAvailableFolders()` restituisce una lista di ID case-sensitive come richiesto dal base. `folderMapping()` aggiunge nomi e percorsi leggibili. Discovery attraversa ogni livello e pagina delle cartelle; nomi identici e rename non cambiano le chiavi di selezione. Microsoft restituisce solo le cartelle sotto la root nella prima chiamata: perciò il traversal dei childFolders è necessario. [API mailFolders](https://learn.microsoft.com/en-us/graph/api/user-list-mailfolders?view=graph-rest-1.0).

| Setting in `config_json` | Default e semantica |
| --- | --- |
| `folders.include` | `[]`: tutte le cartelle consentite dal default |
| `folders.exclude` | `[]`: ID esplicitamente esclusi; prevale su include |
| `folders.include_hidden` | `false`: abilita discovery delle cartelle hidden quando scelto |
| `folders.include_deleted` | `false`: include Deleted Items quando non c'è una selezione esplicita |
| `folders.include_junk` | `false`: include Junk Email quando non c'è una selezione esplicita |

Deleted Items e Junk Email sono riconosciute tramite ID ottenuti dai nomi well-known Graph, non dal displayName localizzato. Le loro discendenti sono escluse dal default; una selezione esplicita per ID permette di scegliere una cartella consentita. Un ID incluso che non è disponibile genera errore e richiede nuova discovery, non una lista vuota apparentemente riuscita.

Cambiare la selezione, ampliare la finestra o cambiare la policy semantica invalida il fingerprint e ricostruisce una baseline. Il fingerprint include anche il progetto e nomi/percorsi delle cartelle: un rename rivaluta i metadati preservando gli ID e i paths del messaggio. Modificare soltanto i limiti di batch non cambia la selezione. Le chiavi host, come `email_triage_profile_id`, non partecipano al fingerprint di acquisizione: revisionare un profilo triage non provoca un refetch automatico della mailbox. Una cartella appena inclusa ottiene il proprio giro; la baseline senza filtro data permette di acquisire la storia appena resa eleggibile.

## Limiti e filtri

| Budget default | Valore |
| --- | --- |
| `sync.date_window_days` | `0`: storico completo |
| `sync.max_pages` | `20` pagine delta nuove per batch |
| `sync.max_messages` | `500` elementi elaborati per batch |
| `sync.max_bytes` | `100 MiB` di documenti accettati per batch |
| `sync.time_budget_seconds` | `60` secondi; deadline Graph comune e controllo tra gli elementi |
| `sync.page_size` | `100` elementi richiesti per pagina |
| `http.max_response_bytes` | `50 MiB` per risposta Graph |

Un batch fermato dal budget mantiene pagina e indice. Discovery, richieste Graph e attese di retry condividono la deadline del giro: i timeout HTTP vengono limitati al tempo residuo. Il controllo tra gli elementi delimita anche l'elaborazione locale; una deadline esaurita propaga un errore riprendibile senza scavalcare il messaggio. Un singolo messaggio che supera il budget totale del batch genera un errore esplicito, non viene scavalcato come import riuscito.

I filtri supportano `only_unseen`, `only_flagged`, `skip_auto_generated`, mittenti e destinatari include/exclude e subject include/exclude keywords. Indirizzi e domini vengono confrontati senza distinzione di case; gli ID provider restano case-sensitive. I destinatari includono To/Cc/Bcc disponibili. Le keyword subject sono sottostringhe, senza distinzione di case. Exclude prevale e, quando un filtro richiede un campo mancante, il messaggio viene escluso.

`skip_auto_generated=true` esclude Precedence bulk/list/junk, Auto-Submitted diverso da `no` e List-Unsubscribe. La finestra temporale usa receivedDateTime e non l'ordine di ritorno del delta. Read, flag, category e folder updates vengono nuovamente valutati: se body e manifesto restano identici l'hash di revisione non cambia, così il triage host può riusare la decisione.

## Body e allegati

Il body consegnato è Markdown completo, non bodyPreview o uniqueBody. Il formato predefinito preferisce testo; l'HTML viene ripulito e convertito senza caricare immagini o risorse remote. Le citazioni sono mantenute finché non viene abilitato lo stripping. La redazione del bridge precede hash, prepared-event e persistenza, anche per i messaggi item annidati resi in Markdown. Il bridge viene sempre consultato; la redazione è controllata dalla policy PII host, senza toggle o bypass per installazione nel contratto pubblico. Subject, indirizzi e threading vengono conservati nei metadati e nell'intestazione leggibile. [Proprietà e preferenza del body Graph](https://learn.microsoft.com/en-us/graph/api/message-get?view=graph-rest-1.0).

La discovery degli allegati è paginata anche quando `hasAttachments=false`, così gli inline fanno parte della coverage. La policy predefinita accetta le estensioni documentali elencate nella config, con 25 MiB/file, 20 file, 50 MiB totali per messaggio, profondità 3 e inline esclusi.

| Tipo Graph | Esito |
| --- | --- |
| `fileAttachment` | Bytes contentBytes/base64 o dettaglio; controlli bytes, estensione e MIME |
| `itemAttachment` | Messaggio Outlook annidato reso in Markdown; budget depth/bytes e coverage delle parti osservate |
| `referenceAttachment` | Metadati osservati e motivo `reference_attachment_not_downloaded`; URL mai seguito |
| Inline | Osservato nel manifesto; acquisito solo se policy lo consente |

Tipi item diversi dai messaggi e tipi attachment non supportati vengono esclusi con motivo. Filename sanificati non definiscono l'identità; slot e ID separano gli omonimi. Allegati vuoti, MIME discordanti o oltre policy sono esclusioni esplicite; base64 malformato e contenuto file mancante sono errori di acquisizione. [Tipi e contenuto degli attachment Graph](https://learn.microsoft.com/en-us/graph/api/attachment-get?view=graph-rest-1.0).

Il pacchetto conserva l'originale normalizzato Markdown richiesto dal contratto triage. Non scarica automaticamente una seconda copia RFC822 raw.

## Checkpoint e replay

La pagina persistita contiene soltanto immutable ID e marker di rimozione, non il messaggio completo. Ogni elemento viene riletto, filtrato, preparato e consegnato prima di incrementare l'indice. Un listener fallito, `Storage::put()` falso o un'eccezione del bridge impedisce l'avanzamento oltre l'elemento. Le ricevute per body e ID/slot allegato sono salvate solo dopo il dispatch accettato dal bridge.

Il pacchetto scrive usando `absolute` restituito da `resolveKbSourcePath()` e consegna `relative` al dispatch; un prefisso disk host non può essere aggiunto due volte o omesso. Il bridge deve ritornare da `dispatchIngestion()` dopo una consegna durevole, ad esempio al proprio outbox/queue. Queste ricevute operative non equivalgono all'indicizzazione terminata: le ricevute triage host sono gestite separatamente e devono riferirsi allo stesso hash di revisione.

Un replay della stessa revisione/proiezione riusa i dispatch già confermati; una revisione nuova riceve nuove ricevute. Dopo la pagina completa si avanza la nextLink; solo la fine del giro per cartella committa il deltaLink. Un errore delta scaduto/reset ricrea soltanto la baseline della cartella coinvolta, con un reset finito. Un 404 di messaggio, un errore di cartella e un errore di autenticazione rimangono distinti.

## Move e reconciliation

Tutte le richieste Graph inviano `Prefer: IdType="ImmutableId"`. Gli immutable ID rimangono stabili per move nella stessa mailbox; archive mailbox e export/reimport possono cambiare identità. [Regole Microsoft degli immutable ID](https://learn.microsoft.com/en-us/graph/outlook-immutable-id).

Un `@removed` dalla cartella non è prova sufficiente di cancellazione. Il connettore rilegge il messaggio tramite ID immutabile per risolvere la posizione corrente prima di agire. Un move tra due cartelle selezionate aggiorna la membership mantenendo parent key e path. Un primo lookup 404 per una fonte già acquisita registra un tombstone pendente; la conferma viene ripetuta soltanto dopo il completamento delle cartelle selezionate. Il pending e il cursore della fase di conferma sono durevoli, così un batch incompleto o un errore non elimina il parent prima del possibile import dalla destinazione.

`reconciliation.enabled=false` conserva di default le fonti locali quando un messaggio è cancellato o esce dalla selezione. Con `true`, il bridge può rimuovere body e allegati usando `email_message_key` composta, dopo prova di cancellazione o uscita dallo scope. Il controllo delle assenze di un full scan avviene solo dopo il completamento di tutte le cartelle selezionate. Baseline incompleta, folder discovery fallita e permessi negati non autorizzano un prune.

Con reconciliation attiva, una revisione che elimina o cambia slot/path di un attachment ritira anche le vecchie fonti singolarmente, tramite `email_attachment_source_key`. La chiave è derivata da parent key e path; è un'estensione additiva che non modifica l'identità parent o l'hash v1 del manifesto. Il cleanup avviene soltanto dopo le write e i dispatch durevoli dei documenti correnti; errori di cleanup mantengono un backlog riprendibile e impediscono il checkpoint. Con reconciliation spenta, gli attachment superati rimangono locali secondo la stessa policy di conservazione dei messaggi.

Non vengono eseguiti delete, move, mark-read o altre mutazioni nella mailbox upstream. [Compatibilità del bridge](compatibility.md) descrive il lookup composto e i requisiti di cancellazione.

## Continuation e errori transitori

Una continuation deve mantenere schema HTTPS, host `graph.microsoft.com`, path `v1.0`, mailbox/account e collection iniziale. Viene seguita come URL opaca senza cambiare query o preferenze; credenziali URL, port, fragment, path unsafe, host esterni e cicli sono rifiutati. Redirect HTTP sono disabilitati.

Il trasporto Graph usa timeout 15 s, connect timeout 5 s e due retry di default per connessione, 429 e 5xx. Rispetta Retry-After fino al limite configurato; se il provider chiede più dei 5 s consentiti, interrompe il batch per un retry successivo. [Throttling Microsoft Graph](https://learn.microsoft.com/en-us/graph/throttling).

Nei log e nell'audit usa conteggi e fasi; non scrivere body, indirizzi, subject, token o link con cursor. La verifica live di policy aziendali, latenza, throttling e accesso reali rimane distinta dalla suite sintetica Testbench.
