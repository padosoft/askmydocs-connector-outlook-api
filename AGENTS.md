# Istruzioni del pacchetto

Questo repository contiene il connettore Microsoft Mail. Modifica esclusivamente questo pacchetto; la chat Gmail possiede le modifiche comuni AskMyDocsDev. Non modificare host, altri connettori o vendor.

Leggi prima `docs/specs/2026-10-14-email-api/ANALYSIS.md`, `STANDARD.md` e `MICROSOFT-MAIL.md`. Le specifiche fissano key, namespace, envelope metadata e hook triage v1. Il triage e le campagne AI restano nell’host.

- Completa implementazione, test, CI e documentazione senza fermarti allo scaffolding.
- Il bootstrap contiene specifiche, non una libreria già implementata: non dichiarare il pacchetto pronto finché i criteri di accettazione non sono soddisfatti.
- Esegui commit locali atomici dopo avere ispezionato il diff; escludi segreti, vendor, output generati e modifiche preesistenti.
- L’utente gestisce tutti i push. Non fare push, tag, PR o pubblicazioni senza richiesta esplicita nel task corrente. Non riscrivere/amendare commit esistenti.
- Nessuna mailbox reale, invio messaggi o attivazione triage/AI pagata è necessaria ai test; usa fixture sintetiche e fake HTTP.
- Host locale Laravel usa Herd `askmydocsdev.test` con lo schema esistente. Non avviare artisan serve e non cambiare APP_URL.
- Non modificare il contratto v1 unilateralmente o i sorgenti sotto vendor.
- Riferisci commit, test, repository/worktree e limiti di OAuth/live o integrazione ancora da verificare.
