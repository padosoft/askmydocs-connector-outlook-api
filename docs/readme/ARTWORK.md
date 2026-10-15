# Risorse del README

Le quattro PNG sono risorse editoriali del pacchetto, generate separatamente con il tool integrato `image_gen` il 14 ottobre 2026. Il riferimento canonico del robot della skill `editorial-readme` è stato usato come riferimento di identità e stile; non è distribuito come immagine del progetto.

| File | Concetto verificato | Prompt specifico della scena |
| --- | --- | --- |
| `header.png` | Acquisizione read-only del contenuto email nel sistema documentale | Un solo robot presenta una busta aperta con fogli; accanto una pila di buste e una vaschetta documentale. |
| `scene-concept.png` | Discovery ricorsiva e selezione per cartella | Il robot esamina con una lente tre cartelle annidate contenenti buste. |
| `scene-workflow.png` | Batch finiti e checkpoint dopo consegna | Il robot applica un segnalibro a un gruppo di documenti vicino a tre buste con allegati. |
| `scene-outcome.png` | Body, attachment distinti e manifesto per il bridge host | Il robot presenta busta/fogli collegati e una clipboard con tre slot senza testo. |

Per ogni scena il brief comune richiede: stesso robot bianco con testa CRT arrotondata, schermo scuro con esattamente due occhi blu senza bocca, due braccia e una sola monoruota centrale senza gambe; contorni neri a pennarello irregolari, bianco dominante, blu cobalto e tratteggi grigi sparsi; composizione aperta, silhouette completa e padding; alpha realmente trasparente; assenza di testo, logo, watermark, fondale, pavimento e ombra; nessun 3D, gradiente, realismo fotografico o finitura vettoriale.

Le PNG finali sono state ispezionate e conservate con la loro alpha originale. Il logo separato `logo.svg` è un marchio originale del pacchetto: busta e nodi documentali, senza wordmark o loghi Microsoft. `resources/icons/microsoft-mail.svg` usa uno stile stroke coerente con shadcn/Lucide, `currentColor` e nome accessibile; non richiede una libreria di icone runtime.

Verifica della trasparenza e dei riferimenti README:

```bash
python3 /percorso/skills/editorial-readme/scripts/validate_readme_visuals.py README.md
```
