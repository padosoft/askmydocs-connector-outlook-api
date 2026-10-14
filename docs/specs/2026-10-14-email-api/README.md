# Creazione dei connettori Gmail e Microsoft Mail

I due pacchetti collegano mailbox via API e riusano il triage email AskMyDocs. L’analisi identifica standard, componenti email disponibili e adattamenti comuni; ogni chat riceve requisiti, confini di modifica e criteri di collaudo.

| Documento | Uso |
| --- | --- |
| [ANALYSIS.md](ANALYSIS.md) | Analisi pacchetti, riuso email e accoppiamenti host |
| [STANDARD.md](STANDARD.md) | Contratto condiviso, triage, isolamento, sync e test |
| [GMAIL.md](GMAIL.md) | Specifica Gmail e responsabilità unica dell’integrazione host |
| [MICROSOFT-MAIL.md](MICROSOFT-MAIL.md) | Specifica Graph Mail e responsabilità del pacchetto Microsoft |

Repository destinazione: `/Users/marco/packages/askmydocs-connector-gmail` e `/Users/marco/packages/askmydocs-connector-microsoft-mail`. Entrambi ricevono copia locale dello standard, dell’analisi e della specifica sotto `docs/specs/2026-10-14-email-api/`.

La chat Gmail modifica il proprio pacchetto e l’host in un worktree isolato; la chat Microsoft modifica soltanto il proprio pacchetto. Gli sviluppi package partono in parallelo. La verifica finale Microsoft attraverso il triage host richiede la consegna dell’adattatore comune assegnato alla chat Gmail.

Stato iniziale: specifiche definite, implementazioni da avviare. Tutti i push e le pubblicazioni remote restano all’utente. Account reali, OAuth/live e attivazione triage non sono dichiarati verificati dai test con fixture.
