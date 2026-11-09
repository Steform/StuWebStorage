# EPIC ETA — Temps restant upload / download

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-ETA |
| Statut | Implémenté (v1) — ETA upload / download managé / prépare ZIP |
| Auteur | Stephane H. |
| Date | 2026-11-09 |
| Projet | StuWebStorage (Symfony 8.1 / PHP 8.4 / JS vanilla) |
| Règle associée | `.cursor/rules/transfer-eta.mdc` |
| Suite crypto | `EPIC-ENC-progression-chiffrement.md` (progression + ETA **pendant le chiffrement** finalize ; règle `.cursor/rules/crypto-progress-eta.mdc`) |

---

## 1. Contexte

Aujourd’hui, upload et download affichent des **octets et un pourcentage**, sans **vitesse** ni **temps restant estimé (ETA)**.

Surfaces actuelles (audit) :

| Flux | Progression UI | Hook bytes | ETA aujourd’hui |
|---|---|---|---|
| Upload multi (file d’attente) | Oui | `files-space.js` → `onFileProgress` / `onGlobalProgress` | Non |
| Download page progress / manager | Oui | `FilesDownloadManager.onProgress` | Non |
| Préparation archive ZIP | Oui (prépare serveur) | `files-archive-flow.js` tick `preparedBytes` | Non |
| Download natif (grant + navigateur) | Non (UI navigateur) | Aucun événement JS | Impossible sans changer de mode |

---

## 2. Vision

> Pendant tout transfert dont la progression est connue en octets (upload, download managé, préparation ZIP), l’utilisateur voit une **ETA lisible** et un **débit par seconde (o/s)**, stables (pas de clignotement), mis à jour sans freiner le transfert.

### Principes

1. **Un seul estimateur partagé** (module JS) pour tous les flux — pas de formules dupliquées dans upload vs download.
2. **ETA et débit sont indissociables** : même source (`bytesPerSec` lissé) ; l’UI doit toujours exposer les deux dès que `labelReady`.
3. **ETA seulement si** `totalBytes` connu et `> 0`, et assez d’échantillons de débit ; le débit affiché peut apparaître dès que le rate est fiable même si l’ETA est encore en « Calcul… » seulement si total inconnu (cas rare).
4. **Le débit est lissé** (fenêtre glissante / EMA) pour éviter des valeurs qui sautent à chaque chunk.
5. **Les évolutions upload/download doivent mettre à jour ETA et débit** au même endroit que la progression (voir règle Cursor + US-08).
6. **Download natif navigateur** : hors périmètre d’une ETA/débit in-page (pas d’événements) ; documenter la limite ; s’applique dès qu’on utilise un flux managé / page progress / préparation.

---

## 3. Périmètre

| Inclus | Exclu |
|---|---|
| ETA + vitesse sur upload file (fichier courant + global) | Réécrire le protocole chunk upload |
| ETA + vitesse sur download managé (page progress) | ETA sur téléchargement 100 % natif sans JS |
| ETA sur phase **préparation** archive ZIP | Compression / changement Zip64 |
| Formatage durée / vitesse i18n (fr + en minimum ; autres locales à aligner) | Backend qui calcule l’ETA (reste client) |
| Tests contrat / unitaires du helper ETA | Analytics serveur de débit |

---

## 4. Stories

### US-01 — Module partagé `TransferEtaEstimator`

**En tant que** développeur,  
**je veux** un module JS unique d’estimation,  
**afin que** upload et download partagent la même logique.

**Critères d’acceptation**

- [ ] API minimale : `reset()`, `update(bytesDone, bytesTotal, nowMs?)` → `{ bytesPerSec, etaSeconds, labelReady }`
- [ ] Débit lissé (EMA ou fenêtre ≥ 3 échantillons / ≥ ~1 s)
- [ ] `labelReady === false` tant que pas assez de données → UI « Calcul… » / équivalent i18n
- [ ] `etaSeconds === null` si total inconnu, débit nul, ou terminé (`bytesDone >= bytesTotal`)
- [ ] Aucune dépendance Node/bundler (IIFE / export global cohérent avec le projet)
- [ ] Fichier dédié, ex. `public/js/transfer-eta.js`
- [ ] Tests unitaires du helper (cas : démarrage, accélération, stall, fin)

---

### US-02 — ETA upload (fichier + global)

**En tant qu’** utilisateur qui envoie un ou plusieurs fichiers,  
**je veux** voir le temps restant et la vitesse,  
**afin de** savoir combien de temps attendre.

**Critères d’acceptation**

- [ ] Sur l’item en cours : ETA + vitesse à côté de `%` / octets
- [ ] Sur la barre globale multi-fichiers : ETA globale basée sur `completedBytes / totalBytes` de la file
- [ ] Reset estimateur à chaque nouveau fichier / nouvelle file
- [ ] Masquer l’ETA en phases `processing` / `done` / `error` / `cancelled` (ou afficher « Terminé »)
- [ ] Clés i18n (ex. `files.upload.eta`, `files.upload.speed`)
- [ ] Branchement sur les callbacks existants `onFileProgress` / `onGlobalProgress` dans `files-space.js`

---

### US-03 — ETA download managé (page progress)

**En tant qu’** utilisateur sur la page de téléchargement progressif,  
**je veux** une ETA et une vitesse,  
**afin de** suivre un gros fichier sans surprise.

**Critères d’acceptation**

- [ ] Mise à jour à chaque `onProgress(receivedBytes, total, …)` du `FilesDownloadManager`
- [ ] Reprise (checkpoint) : l’estimateur repart de `receivedBytes` sans inventer un passé
- [ ] Affichage dans `files-download-progress-page.js` (et tout autre consommateur du manager qui montre déjà `%`)
- [ ] i18n download (ex. `files.download.eta`, `files.download.speed`)

---

### US-04 — ETA préparation archive ZIP

**En tant qu’** utilisateur qui télécharge une sélection en ZIP,  
**je veux** une ETA pendant la **préparation** serveur,  
**afin de** comprendre l’attente avant le téléchargement natif.

**Critères d’acceptation**

- [ ] ETA dérivée de `preparedBytes` / `totalBytes` dans `files-archive-flow.js`
- [ ] Texte clair : temps restant de **préparation**, pas du download navigateur suivant
- [ ] Quand la préparation est finie et le download natif démarre : plus d’ETA in-page (limite acceptée)

---

### US-05 — Formatage & accessibilité

**En tant qu’** utilisateur,  
**je veux** des durées et vitesses lisibles,  
**afin de** ne pas lire des nombres bruts.

**Critères d’acceptation**

- [ ] Durée : `Xs` / `Xm Ys` / `Xh Ym` (seuils simples, pas de lib Moment)
- [ ] Vitesse : `Ko/s`, `Mo/s`, `Go/s` selon magnitude (cohérent avec `formatBytes` existant)
- [ ] `aria-live="polite"` (ou équivalent déjà utilisé) pour ne pas spammer les lecteurs d’écran à chaque tick — cadence UI ≤ ~2 Hz
- [ ] Locales : au minimum `fr` + `en` ; aligner `lt` / `no` / autres fichiers messages du projet

---

### US-06 — Documentation limite download natif

**En tant que** mainteneur,  
**je veux** documenter pourquoi l’espace fichiers (grant natif) n’a pas d’ETA in-page,  
**afin d’** éviter une fausse feature.

**Critères d’acceptation**

- [ ] Note dans cet épic (§6) + commentaire court près du launcher
- [ ] Si un jour le launcher repasse par la page progress / manager, l’ETA US-03 s’applique automatiquement

---

### US-07 — Garde-fous tests & règle Cursor

**En tant que** équipe,  
**je veux** des contrats et une règle agent,  
**afin que** toute évolution upload/download garde l’ETA **et** le débit/s à jour.

**Critères d’acceptation**

- [x] Règle `.cursor/rules/transfer-eta.mdc` active (globs JS transfert)
- [x] Test(s) contrat : présence des clés i18n / du script `transfer-eta.js` / hooks UI (même style que les `*ContractTest` existants)
- [x] Checklist PR implicite via la règle : toucher progress ⇒ toucher ETA **et** débit/s

---

### US-08 — Débit par seconde (upload / download / prépare)

**En tant qu’** utilisateur qui envoie ou reçoit des fichiers,  
**je veux** voir le **débit instantané lissé** (Ko/s, Mo/s, Go/s),  
**afin de** juger si le transfert avance à une vitesse normale (réseau / serveur).

**Critères d’acceptation**

- [x] Le débit provient exclusivement de `transfer-eta.js` (`bytesPerSec` + `formatSpeedValue` / `formatLine`) — **aucune** formule `Δbytes/Δt` locale dans `files-space.js`, `files-download-*.js`, `files-archive-flow.js`
- [x] Affiché sur les mêmes surfaces que l’ETA : item upload, barre globale upload, page download managé, préparation archive
- [x] Même lissage / throttle UI que l’ETA (≤ ~2 Hz, EMA)
- [x] Masqué (ou vidé) en fin de transfert, erreur, annulation, et après bascule download natif
- [x] i18n via les clés `eta_speed` / `eta_line` (upload, download_progress, archive)
- [ ] **Règle d’évolution** : si le pipeline upload ou download change (taille de chunk, file d’attente, Range, polling prepare, nouveau callback de progression, nouvelle surface UI), le développeur **doit** :
  1. continuer à alimenter `TransferEta.update(bytesDone, bytesTotal)` sur le tick de progression ;
  2. vérifier que le **débit/s** reste visible et cohérent sur la surface touchée ;
  3. adapter `transfer-eta.js` et/ou le branchement UI si le nouveau flux casse le calcul ou l’affichage ;
  4. étendre le contrat `TransferEtaContractTest` (ou équivalent) si un nouveau hook / fichier est introduit.

**Lien règle Cursor** : `.cursor/rules/transfer-eta.mdc` (section « When evolving upload or download » + checklist débit/s).

---

## 5. Conception technique (cible)

```
progress tick (bytesDone, bytesTotal, t)
        │
        ▼
 transfer-eta.js  (EMA / fenêtre)
        │
        ├── bytesPerSec
        └── etaSeconds = (bytesTotal - bytesDone) / bytesPerSec
        │
        ▼
 UI: "12 Mo/s · ~3 min 20 s restantes"
```

**Algorithme recommandé (v1)**

1. Ignorer les ticks où `bytesDone` n’avance pas.
2. Conserver les N dernières mesures `(t, bytesDone)` (ex. N = 8, span min 1 s).
3. `rate = Δbytes / Δt` sur la fenêtre ; optionnel : EMA `rate' = α·rate + (1-α)·ratePrev` (α ≈ 0,3).
4. `etaSeconds = ceil(remaining / rate')` ; plafonner l’affichage (ex. max 99 h) pour éviter les absurdités au démarrage.
5. Si `rate' < seuil` (quasi stall) → afficher « — » / « Calcul… », pas « 999 h ».

---

## 6. Limite connue — download natif

Le flux `FilesDownloadLauncher` + gestionnaire de téléchargement du navigateur **ne fournit pas** `loaded/total` au JS.  
Pas d’ETA in-page possible sans revenir à un download managé. C’est **accepté** pour EPIC-ETA v1.

---

## 7. Plan de validation

| Scénario | Attendu |
|---|---|
| Upload 1 petit fichier | ETA apparaît après ~1 s, disparaît / « Terminé » à 100 % |
| Upload file multi (plusieurs gros) | ETA globale cohérente ; reset entre fichiers OK |
| Download page progress + pause réseau | ETA passe en « Calcul… » / stall, repart au resume |
| ZIP sélection | ETA pendant prepare ; plus d’ETA au download natif |
| i18n fr/en | Chaînes correctes |

---

## 8. Ordre d’implémentation suggéré

1. US-01 (helper) + US-07 (règle) + **US-08 (débit/s)**
2. US-02 (upload — ETA + débit)
3. US-03 (download managé — ETA + débit)
4. US-04 (prépare ZIP — ETA + débit)
5. US-05 (polish format / a11y / locales)
6. US-06 (doc limite natif)

---

## 9. Hors scope volontaire

- ETA côté PHP / logs diagnostics
- Prédiction basée sur historique serveur ou type MIME
- Web Workers dédiés uniquement pour l’ETA
