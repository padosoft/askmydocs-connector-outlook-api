# OAuth e mailbox Microsoft

Il connettore usa Microsoft identity platform v2, authorization code con PKCE S256, state monouso e nonce. I token sono conservati dal vault cifrato di connector-base per installazione; il client Graph è costruito con il contesto della sola mailbox verificata.

## Registrare l'applicazione server

In Microsoft Entra crea o riusa un'app registration dedicata. Scegli i tipi account coerenti con il servizio: singola directory per un solo tenant aziendale; più directory per Microsoft 365; includi gli account personali per Outlook.com. Aggiungi la callback della tua applicazione come piattaforma **Web**, non SPA. Registra Application (client) ID e il valore del client secret nel server. [Procedura Microsoft di registrazione](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app).

La registrazione e il consenso reali sono operazioni dell'operatore. Il pacchetto non crea app Entra o consensi amministrativi durante test, health o sync.

| Variabile server | Uso |
| --- | --- |
| `MICROSOFT_MAIL_CLIENT_ID` | Application (client) ID |
| `MICROSOFT_MAIL_CLIENT_SECRET` | Valore del client secret, nel server consumer |
| `MICROSOFT_MAIL_REDIRECT_URI` | Callback Web registrata esattamente in Entra |
| `MICROSOFT_MAIL_AUTHORITY` | `common`, `organizations`, `consumers`, GUID directory |

Il root pubblicato è `connector-microsoft-mail`. `authority` è il segmento tenant degli endpoint Microsoft fissi: non è un URL configurabile. Per limitare la connessione a un tenant preciso usa il suo GUID. I domini tenant non sono accettati, così la directory firmata dell'ID token può essere confrontata esattamente con l'authority configurata. La directory OAuth Microsoft è diversa dal tenant AskMyDocs.

### Redirect Herd

Nell'host locale AskMyDocs il dominio e lo schema verificati sono `https://askmydocsdev.test`; il redirect è:

```text
https://askmydocsdev.test/api/admin/connectors/microsoft-mail/oauth/callback
```

Mantieni `APP_URL` dell'host coerente con il sito Herd e registra lo stesso URL in Entra. Per un altro consumer o ambiente registra la sua callback effettiva, distinta da quella locale. Inizia OAuth dalla UI host con sessione, tenant attivo e installazione pending: la callback non crea una connessione da sola.

## Scope e tipo di account

| Scope delegato richiesto | Motivo nell'implementazione |
| --- | --- |
| `Mail.Read` | Body, headers e allegati della mailbox personale |
| `User.Read` | Risolvere l'ID persistente dell'account con `/me?$select=id` |
| `offline_access` | Ottenere e usare il refresh token |
| `openid` | Ricevere un ID token firmato per verificare directory, nonce e identità OAuth |
| `Mail.Read.Shared` | Aggiunto solo quando `mailbox.target_id` sceglie una mailbox condivisa |

`Mail.Read.Shared` è valido per account work/school. `Mail.ReadBasic` non include il contenuto necessario. Anche con scope delegati concessi, l'organizzazione può applicare policy di consenso o di accesso mailbox. [Riferimento Microsoft dei permessi](https://learn.microsoft.com/en-us/graph/permissions-reference#mailreadshared).

Il connettore non chiede scope di scrittura, invio o OneDrive. App-only/client credentials e cloud Microsoft nazionali non fanno parte del trasporto di questa consegna: l'origin Graph accettato è `https://graph.microsoft.com/v1.0`.

## Scegliere il target

Con `mailbox.target_id=null` viene usato `/me`. L'identità persistita è la combinazione della directory verificata nell'ID token e dell'ID Graph restituito da `/me`; email e UPN sono dati di presentazione.

Per una mailbox condivisa Microsoft 365 imposta in `config_json` l'ID utente persistente del target **prima di iniziare OAuth**:

```php
'mailbox' => [
    'target_id' => '11111111-2222-4333-8444-555555555555',
],
```

Il pacchetto richiede un GUID. Con `User.Read` non può risolvere liberamente l'indirizzo di un altro utente: ottieni il target ID dall'amministratore. Il consenso `Mail.Read.Shared` e i diritti di accesso delegato alla mailbox devono essere entrambi disponibili. L'accesso target viene verificato tramite le cartelle, senza aggiungere permessi directory-wide. [Accesso Microsoft a cartelle condivise o delegate](https://learn.microsoft.com/en-us/graph/outlook-share-messages-folders).

Un target negato solleva un errore di permessi; non viene sostituito con `/me`. Outlook.com rimane personale. Cambiare target dopo la connessione richiede una riconnessione prima di riusare lo stato; il nuovo account/mailbox ottiene una baseline separata.

## Callback, refresh e disconnect

Lo state scade dopo dieci minuti ed è legato a tenant, installazione e configurazione OAuth/target. Mancanza, scadenza, replay, installazione diversa e cambi di configurazione sono rifiutati. Lo state viene consumato prima dello scambio del codice. L'ID token è verificato con le chiavi Microsoft, firma RS256, audience, issuer, scadenza, nonce e directory; claim non verificati non definiscono una mailbox.

La callback verifica gli scope Graph realmente concessi, il refresh token iniziale e l'accesso al target prima di attivare la connessione. OIDC/offline non devono necessariamente essere ripetuti nel campo `scope` della risposta: l'implementazione verifica le capability Graph e il refresh token. Il protocollo e le regole di redirect sono descritti nella [guida Microsoft authorization code](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow).

Il refresh è serializzato per installazione. Una rotazione sostituisce il refresh token; una risposta senza sostituto conserva quello precedente. Le altre chiavi `extra_json` sono preservate. I cursori di sync appartengono alle tabelle del pacchetto, non al vault.

`health()` verifica identità e accesso alla mailbox senza scaricare messaggi; la discovery elenca le cartelle senza avanzare cursori. Entrambe possono aggiornare credenziali scadute tramite refresh. `disconnect()` elimina credenziali e stato locale con audit; non revoca sessioni globali o consensi di altri prodotti.

## Diagnostica

| Errore concreto | Passo operativo |
| --- | --- |
| Server config incompleta o redirect non corrispondente | Verificare app ID, valore del secret, URI Web e authority |
| Consenso negato, grant/scopes insufficienti o token respinto | Riconnettere l'installazione e verificare le policy del tenant Microsoft |
| Shared target senza GUID, Outlook.com o accesso negato | Usare il GUID corretto e account work/school con consenso e diritti mailbox |
| Target cambiato | Riconnettere prima di health/discovery/sync |
| Provider temporaneamente indisponibile o throttling | Ripetere il batch secondo i retry finiti dell'host |

Non copiare token, authorization code, cursor URL o secret in log e ticket. I test del pacchetto usano state, grant e chiavi sintetici: non attestano il consenso di una directory reale o l'accesso live a una mailbox.
