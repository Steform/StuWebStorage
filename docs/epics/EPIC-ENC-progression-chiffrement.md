# EPIC ENC — Chiffrement progressif, tâches fond & fiabilité gros volumes

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-ENC |
| Statut | Implémenté (v1 partielle) — libellé, jobs tick, ETA, centre tâches, disque preflight |
| Auteur | Stephane H. |
| Date | 2026-11-09 |
| Projet | StuWebStorage (Symfony 8.1 / PHP 8.4 / JS vanilla) |
| Règle associée | `.cursor/rules/crypto-progress-eta.mdc` |
| Lié à | `EPIC-ETA` (`transfer-eta.js`) ; patterns job+tick existants (ZIP extract / archive) |

---

## 1. Contexte et problématique

### 1.1 Flux upload actuel

1. Chunks → `.part` clair  
2. `POST …/finalize` → chiffrement synchrone CVF2  
3. Persist `SharedFile` + suppression `.part`

### 1.2 Constats

| # | Problème |
|---|---|
| P1 | Libellé **« Traitement… »** au lieu de **Chiffrement** |
| P2 | Aucune progression / ETA pendant le chiffrement (critique à 10–100 Go) |
| P3 | Fermer la modal = UX floue (ni vrai fond, ni vrai cancel) |
| P4 | Pas de centre de **tâches en cours** pour reprendre / suivre / annuler |
| P5 | Pas de tests de fiabilité dédiés au chiffrement gros volume (au-delà des smokes download) |

---

## 2. Vision

> Le chiffrement post-upload est une **tâche serveur suivie** : progression + débit + ETA, libellé honnête, **réductible** vers un panneau de tâches (près d’Accueil), **reprenable** par ID, **annulable** avec nettoyage, et **résiliente** à la fermeture du navigateur / PC (suivi au retour + toast si terminé hors session).

### Principes UX figés

1. **Dismiss ≠ cancel** — fermer la modal n’arrête pas le chiffrement sans choix explicite.
2. **Interception de fermeture** pendant le chiffrement → dialogue : **Continuer en arrière-plan** (défaut) | **Annuler**.
3. **Centre de tâches** (bouton à côté d’Accueil, haut droite) → dropdown de **toutes** les actions longues en cours, avec progression.
4. Toute modal d’action longue est **réduisible** vers ce centre et **rouvrable** (même état).
5. Chaque tâche a un **`taskId`** stable ; clic → rouvre la modal / surface de l’action.
6. Coupure brutale (PC éteint, kill navigateur) : au retour, tâches non finies **visibles** dans le centre ; tâches finies hors session → **toast** (succès / échec).
7. **Annuler** = arrêt serveur + **nettoyage** des fichiers devenus inutiles (`.part`, `.dat` partiel, meta job).
8. ETA / débit via `transfer-eta.js` uniquement.
9. Fiabilité gros volumes **testée** (smokes / scénarios comme upload-download déjà amorcés).

### Clarification — quota utilisateur vs place disque

Deux notions distinctes :

| Notion | Règle produit |
|---|---|
| **Quota utilisateur** | On ne compte **que le fichier chiffré final** (`SharedFile` / `.dat` terminé). Le `.part` clair temporaire **n’entre pas** dans le quota. Pas de double comptage pendant le job. |
| **Disque serveur** (`disk_free`) | Pic physique ≈ **2×** la taille (`.part` + `.dat` en cours). Si on sait **avant l’upload** que le disque ne pourra pas absorber ce pic (et/ou la taille annoncée), **refuser avant même l’envoi des chunks** avec un message d’erreur clair. |

**Décisions produit**

1. **Avant upload** (session / sélection fichiers) : si `disk_free` insuffisant pour réaliser upload+chiffrement de la taille annoncée → **erreur immédiate**, pas d’upload.  
2. **Quota** : check « le propriétaire peut-il encore stocker `byteSize` du fichier final ? » avant upload ; à la fin on n’impute que ce fichier chiffré.  
3. **Filet de sécurité mid-job** (course : autre job, disque qui se remplit) : si malgré le check initial l’écriture `.dat` échoue → `failed` + cleanup + message ; **aucun** `SharedFile` fantôme.

---

## 3. Périmètre

| Inclus | Exclu |
|---|---|
| Libellé Chiffrement | Changer algo / format CVF2 |
| Progression + ETA + débit chiffrement | ETA download natif navigateur |
| Jobs serveur découplés de l’onglet | Obligation Messenger (tick HTTP acceptable si fiable) |
| Centre de tâches global (nav) | Refonte complète de toute l’UI |
| Réduire / reprendre modales d’actions longues (chiffrement en tête ; autres actions longues à brancher sur le même contrat) | |
| Annulation + cleanup | |
| Toasts « terminé hors session » | |
| Gestion échec quota / disque pendant job | |
| Tests gros volumes (encrypt finalize / cancel / resume UI) | Remplacer tous les E2E HTTP 100 Go si disque insuffisant — documenter l’échelle testée |

---

## 4. Stories

### US-01 — Libellé « Chiffrement »

**En tant qu’** utilisateur,  
**je veux** lire **Chiffrement…** (plus « Traitement… »),  
**afin de** comprendre la phase.

**Critères**

- [ ] i18n `files.upload.multi.status.processing`, `files.upload.progress_processing` (+ locales)
- [ ] Texte UI = chiffrement ; état JS interne `processing` OK si besoin

---

### US-02 — Progression serveur du chiffrement

**En tant qu’** utilisateur sur un gros fichier,  
**je veux** un % de chiffrement réel,  
**afin de** voir l’avancée après l’upload réseau.

**Critères**

- [ ] Ticks `plainDone` / `plainTotal` pendant encrypt (callback segments 4 Mio)
- [ ] Client poll / SSE documenté (§5)
- [ ] Fréquence ticks utile ≤ ~2 s sur gros volumes
- [ ] `done` à 100 % + persist `SharedFile`

---

### US-03 — ETA + débit pendant le chiffrement

**Critères**

- [ ] `TransferEta` dédié à la phase chiffrement (reset au début de phase)
- [ ] Débit + temps restant sur modal et dans le centre de tâches
- [ ] Pas de formule hors `transfer-eta.js`

---

### US-04 — Règle Cursor crypto ↔ ETA

**Critères**

- [x] `.cursor/rules/crypto-progress-eta.mdc`
- [ ] Étendre la règle : jobs fond / cancel / centre de tâches si ces fichiers sont touchés
- [ ] Toute évolution encrypt **ou** decrypt UI longue → revoir progression + ETA

---

### US-05 — Interception fermeture modal pendant chiffrement

**En tant qu’** utilisateur qui clique fermer / backdrop / Esc pendant le chiffrement,  
**je veux** être prévenu et choisir,  
**afin de** ne pas perdre le travail ni l’annuler par erreur.

**Critères**

- [ ] Intercepter la fermeture Bootstrap (et Esc / backdrop) **uniquement** pendant la phase chiffrement (et assimilés « tâche longue modale »)
- [ ] Dialogue :  
  - **Continuer en arrière-plan** (bouton primaire / défaut, focus)  
  - **Annuler le chiffrement** (secondaire / danger)  
  - optionnel : Retour (rester sur la modal)
- [ ] « Continuer » → réduit la modal, job **continue**, tâche visible dans le centre
- [ ] « Annuler » → confirme si besoin puis abort + cleanup (US-07)
- [ ] i18n dédiées (`files.tasks.dismiss_*` / `files.upload.encrypt.dismiss_*`)

---

### US-06 — Centre de tâches (nav, à côté d’Accueil) — **supersédé par EPIC-OPS**

**En tant qu’** utilisateur connecté,  
**je veux** un bouton tâches en haut à droite près d’Accueil,  
**afin de** suivre tout ce qui tourne en fond.

**Critères**

- [ ] Contrôle dans `components/_storage_nav.html.twig` (ou équivalent), à proximité du lien Accueil / zone droite
- [ ] Badge compteur des tâches `running` (et éventuellement `failed` non acquittées)
- [ ] Dropdown / panneau : liste des tâches avec titre, type, %, débit/ETA si dispo, actions (ouvrir, annuler)
- [ ] **Toutes** les actions longues en cours du compte y apparaissent (chiffrement finalize en premier ; contrat extensible extract ZIP, prepare archive, etc.)
- [ ] Polling léger ou refresh à l’ouverture du dropdown
- [ ] Vide : état « Aucune tâche en cours »

---

### US-07 — Annulation + nettoyage

**En tant qu’** utilisateur,  
**je veux** annuler une tâche (depuis modal ou centre),  
**afin de** libérer disque et quota des fichiers inutiles.

**Critères**

- [ ] Endpoint / signal `cancel` honoré entre segments de chiffrement
- [ ] Cleanup : meta job, `.part`, `.dat` partiel, index sidecar éventuel
- [ ] Pas de `SharedFile` créé si cancel avant fin
- [ ] Statut `cancelled` puis retrait / archivage court de la ligne tâches
- [ ] Annulation depuis le centre de tâches **ou** la modal rouverte

---

### US-08 — `taskId` + rouvrir la modal — **supersédé par EPIC-OPS** (chiffrement + `taskId` restent ; le centre ENC ne fait plus autorité)

**En tant qu’** utilisateur,  
**je veux** cliquer une tâche et retrouver la modal d’origine,  
**afin de** reprendre le suivi détaillé.

**Critères**

- [ ] Chaque tâche a un `taskId` (UUID ou équivalent) + `type` (`upload_encrypt`, …) + payload minimal (fileName, uploadId, …)
- [ ] Clic item → rouvre la modal concernée (upload / autre) sur l’état courant (progression live)
- [ ] Réduire depuis la modal = même `taskId` (pas de doublon)
- [ ] Deep-link optionnel : `?task=<id>` ouvre le panneau / la modal au chargement

---

### US-09 — Résilience coupure navigateur / PC

**En tant qu’** utilisateur qui éteint le PC pendant un chiffrement,  
**je veux** au retour retrouver l’état,  
**afin de** ne rien perdre mentalement.

**Critères**

- [ ] Jobs **persistant côté serveur** (indépendants de l’onglet)
- [ ] Au login / chargement app : hydrater le centre de tâches avec jobs `running` / `failed` récents
- [ ] Si un job est passé `done` depuis la dernière visite → **toast** succès (nom du fichier)
- [ ] Si `failed` depuis la dernière visite → **toast** erreur
- [ ] Mécanisme d’acquittement des toasts (flag `notified_at` / localStorage des taskIds déjà notifiés)

---

### US-10 — Place disque avant upload + quota = fichier final seulement

**En tant qu’** utilisateur,  
**je veux** être bloqué **avant** l’upload s’il n’y a pas la place de chiffrer, et ne voir compter dans mon quota **que** le fichier chiffré fini,  
**afin d’** éviter un upload inutile et un double comptage.

**Critères**

- [ ] **Preflight disque** (création de session upload et/ou validation file) : si l’espace libre serveur est insuffisant pour le pic nécessaire au chiffrement de la taille annoncée → erreur i18n (`disk_full` / équivalent) **sans** démarrer les chunks
- [ ] **Preflight quota** : refuser si le propriétaire n’a plus assez de quota pour **une** copie logique de la taille finale (pas 2×)
- [ ] **Comptage quota** : seul le `SharedFile` / stockage chiffré final consomme le quota ; le `.part` temporaire est hors quota
- [ ] **Filet mid-job** : si le disque ou le quota devient insuffisant malgré le preflight → job `failed` + cleanup + message ; pas de ligne fichier
- [ ] Visible dans le centre de tâches / toast si hors session (cas mid-job uniquement)

---

### US-11 — Tests fiabilité gros volumes

**En tant que** équipe,  
**je veux** des tests/smokes paramétrables (comme `smoke-volume-php.php`),  
**afin de** garantir chiffrement / cancel / reprise UI sur gros fichiers.

**Critères**

- [ ] Smoke PHP (hors HTTP si besoin) : encrypt streaming avec callbacks de progression → assert ticks monotones jusqu’à `plainTotal`
- [ ] Smoke cancel : démarrer encrypt, signal cancel au milieu, assert pas de `.dat` final / pas de SharedFile, artefacts nettoyés
- [ ] Scénario taille **paramétrable** (ex. `64M`, `8G`, `25G`) — pas de taille magique dans le nom du script
- [ ] Documenter dans `tests/e2e/README.md` : espace disque requis ≈ 2× taille pour encrypt (`.part` + `.dat` le temps du job)
- [ ] Contrats UI : présence centre de tâches, `taskId`, clés i18n dismiss / cancel
- [ ] Échelle réellement exécutée documentée (comme EPIC-DL100)

---

## 5. Conception technique (cible)

### Job serveur (obligatoire pour fond + reprise)

```
upload chunks OK
    → create Task(type=upload_encrypt, status=running, taskId=…)
    → encrypt loop (4 MiB) + progress + cancel flag + disk/quota checks
    → done → SharedFile + cleanup .part
       | failed → cleanup + message
       | cancelled → cleanup
```

UI :

```
Modal chiffrement ←── taskId ──→ Centre de tâches (nav)
       │ reduce / dismiss→background
       ▼
  poll GET /tasks or /tasks/{id}/tick
       │
       ▼
  TransferEta.update(done, total)
```

### Contrat tâche (API indicative)

```json
{
  "taskId": "…",
  "type": "upload_encrypt",
  "status": "running|done|failed|cancelled",
  "label": "film.mkv",
  "doneBytes": 0,
  "totalBytes": 0,
  "errorKey": null,
  "modalKey": "files_upload",
  "createdAt": "…",
  "updatedAt": "…"
}
```

### Point d’accroche crypto

`encryptPlainFileToV2Storage(…, ?callable $onProgress = null, ?callable $shouldCancel = null)`  
— après chaque segment : `onProgress($done, $total)` ; si `shouldCancel()` → exception dédiée `encryption.cancelled`.

---

## 6. Parcours UX (référence)

1. Upload terminé → modal passe en **Chiffrement** (% + ETA).  
2. User clique ✕ → dialogue **Continuer en arrière-plan** (défaut) / **Annuler**.  
3. Continuer → modal se ferme ; bouton **Tâches** badge+1 ; dropdown montre la ligne.  
4. Clic ligne → modal upload/chiffrement se rouvre sur le même `taskId`.  
5. Annuler (modal ou dropdown) → cleanup, disparition progressive.  
6. Retour après reboot, job encore `running` → visible dans Tâches.  
7. Retour, job `done` pendant l’absence → toast « *film.mkv* est prêt ».

---

## 7. Plan de validation

| Scénario | Attendu |
|---|---|
| Petit fichier | Chiffrement rapide, pas de friction dismiss |
| Gros fichier + réduire | Badge tâches, progression live hors modal |
| Rouvrir via taskId | Même % / continuité ETA (reset acceptable si estimateur neuf) |
| Annuler à 50 % | Pas de fichier listé, disque récupéré |
| Quota/disque mid-job | `failed` + message + cleanup |
| Reboot simulé | Hydratation tâches + toast si done |
| Smoke `25G` encrypt+cancel | PASS documenté |

---

## 8. Ordre d’implémentation suggéré

1. US-01 (libellé) + US-04 (règle à jour)  
2. US-02 + modèle Task + ticks  
3. US-03 (ETA)  
4. US-05 (intercept dismiss)  
5. US-06 (centre de tâches nav)  
6. US-08 (rouvrir) + US-07 (cancel)  
7. US-09 (toasts hors session) + US-10 (quota/disque)  
8. US-11 (smokes gros volumes)

---

## 9. Hors scope

- Modifier le chiffrement au repos (algo/clé)  
- ETA sur download 100 % natif  
- Remplacer le gestionnaire de téléchargement du navigateur
