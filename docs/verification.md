# Verifica locale del pacchetto

Collaudo concluso il 15 ottobre 2026 sul repository `askmydocs-connector-microsoft-mail`, branch `codex/microsoft-mail-api`. Il commit funzionale è `d19d024`. Tutti i casi sono sintetici: HTTP fake con `preventStrayRequests`, database SQLite in memoria, filesystem fake, bridge con prefisso distinto e listener sincrono v1. Non sono stati letti account reali o eseguite classificazioni AI.

## Matrice eseguita

| PHP | Laravel | Testbench | PHPUnit | Esito |
| --- | --- | --- | --- | --- |
| 8.3.33 | 12.70.0 | 10.12.0 | 11.5.57 | 95 test, 893 asserzioni; PASS |
| 8.4.25 | 12.70.0 | 10.12.0 | 11.5.57 | 95 test, 893 asserzioni; PASS |
| 8.4.25 | 13.36.0 | 11.3.0 | 12.5.39 | 95 test, 893 asserzioni; PASS |
| 8.5.10 | 13.36.0 | 11.3.0 | 12.5.39 | 95 test, 893 asserzioni; PASS |

La dipendenza effettiva è `padosoft/askmydocs-connector-base v1.6.0`, risolta dal repository pubblico dichiarato nel manifest. Per Laravel 12 è stato usato un manifest locale di collaudo con vincoli Laravel 12/Testbench 10/PHPUnit 11 e platform PHP 8.3.33; manifest, lock e vendor di collaudo non sono versionati. Le stesse quattro combinazioni sono dichiarate nella [CI](../.github/workflows/ci.yml). La CI remota non è stata avviata: non sono stati eseguiti push o pubblicazioni.

## Comandi e controlli

```bash
composer validate --strict
composer test
vendor/bin/pint --test
composer analyse
```

I runtime Herd `php83`, `php84` e `php85` sono stati usati per eseguire `vendor/bin/phpunit` con le dipendenze della rispettiva riga. Composer validate, Pint e PHPStan livello 5 sui sorgenti sono verdi. `git diff --cached --check` è passato prima dei commit. Il validator della skill editorial-readme ha verificato header più tre scene separate e alpha trasparente; i link Markdown locali e gli SVG accessibili sono stati controllati.

## Garanzie esercitate

- OAuth PKCE/state monouso, directory OIDC firmata, scope realmente concessi, refresh con rotazione e preservazione delle extra key; installazioni/tenant/target condivisi isolati.
- Graph readonly, ImmutableId su ogni GET, continuation HTTPS/account/cartella, cursori persistiti e ID case-sensitive, paginazione/cicli, JSON e bytes bounded, retry e deadline.
- Discovery ricorsiva paginata, omonimi/cartelle nascoste, default Deleted Items/Junk, allowlist sostituita integralmente, ampliamento della finestra e rename.
- Baseline multipagina, delta per cartella, pagina/indice durevoli, errore a metà pagina, cursor reset, limiti batch e replay senza salti.
- Move con lookup 404 transitorio, pending tombstone fino al completamento delle destinazioni, conferme bounded riprendibili, cancellazioni composte e nessun prune da scan incompleto.
- Body completo, HTML ripulito, headers/threading, allegati file/item/reference/inline, omonimi, limiti bytes/depth, MIME discordante e coverage esclusa.
- Prepared-event prima delle fonti/dispatch, hash dei bytes redatti, manifesto ordinato e ricevute ID/slot/hash. Listener/put/dispatch/cleanup falliti bloccano il completamento; flag e folder updates conservano la revisione quando il contenuto è invariato.
- Gli aggiornamenti di `email_triage_profile_id` e altre impostazioni host non avviano un refetch. Il listener fake esercita readiness e riuso dei terminali, senza replicare il classificatore host.

## Verifiche esterne ancora necessarie

Il consenso OAuth live richiede app registration, account e accesso mailbox reali. L'integrazione AskMyDocs end-to-end dipende dal listener/bridge comune mantenuto nel lavoro Gmail: profili, carte/tag, snapshot, queue/retry/costi e campagne vanno verificati nel relativo host. Il pacchetto consegna e collauda il contratto congelato; non dichiara completate queste verifiche esterne. [Compatibilità del bridge](compatibility.md).
