# Changelog

## Unreleased

### Added

- Pacchetto `padosoft/askmydocs-connector-microsoft-mail`, discovery Laravel e root config `connector-microsoft-mail`.
- Microsoft identity platform v2 delegato con PKCE, state monouso, ID-token verification, refresh serializzato e vault cifrato.
- Mailbox personale Outlook.com/Microsoft 365 e target condiviso Microsoft 365 esplicito con permessi delegati.
- Graph v1.0 read-only, immutable ID, continuation scoped, retry e budget finiti.
- Discovery cartelle ricorsiva paginata, selezione per ID e mapping leggibile.
- Baseline storica/delta per cartella, checkpoint e ricevute persistiti, recovery e move reconciliation opt-in.
- Body completo, headers, filtro/renderer email e policy file/item/reference/inline attachment.
- Metadati email v1 e prepared-event Laravel sincrono per il triage comune host.
- Chiave additiva `email_attachment_source_key` per ritirare fonti attachment superate dopo la consegna durevole della revisione corrente, con reconciliation opt-in.
- Suite PHPUnit/Testbench con fake HTTP, bridge/listener sintetici, Pint, PHPStan e CI Laravel 12/13.
- README illustrato e guide OAuth, sync, triage e compatibilità del bridge host.

### Integration status

- Il contratto del pacchetto è collaudato separatamente dall'adattatore comune AskMyDocs mantenuto nel lavoro Gmail.
- OAuth/consenso/accesso su account e mailbox reali richiedono un collaudo live dell'operatore.
- Non è dichiarata una release o pubblicazione remota.
