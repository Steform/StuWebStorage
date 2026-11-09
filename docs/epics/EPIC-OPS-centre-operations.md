# EPIC OPS — Centre des opérations, chrome modal unique, téléchargements en modal réductible

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-OPS |
| Statut | Implémenté (v1) — chrome Famille B, centre nav, bus JS, encrypt/extract/archive/download |
| Auteur | Stephane H. |
| Date | 2026-11-09 |
| Projet | StuWebStorage (Symfony 8.1 / PHP 8.4 / JS vanilla / Bootstrap 5) |
| Remplace / complète | Le centre de tâches ENC (`files-background-tasks.js`) n’est **pas** le contrat produit. ENC reste pour le **chiffrement**. OPS est le contrat **UI opérations**. |
| Lié à | `EPIC-ENC` (jobs encrypt tick/cancel), `EPIC-ETA` (`transfer-eta.js`), `EPIC-DL100` (download **natif** Range + grant HMAC) |
| Règle à créer | `.cursor/rules/operations-center.mdc` (après validation de cet épic) |

---

## 0. Pourquoi cet épic existe

Le contrat ENC disait :

> Toute modal d’action longue est **réduisible** vers un centre de tâches (près d’Accueil) et **rouvrable** (même état).

Ce qui a été livré : un dropdown qui ne liste que `upload_encrypt`, un dismiss spécifique à l’upload, un bouton « Ouvrir » qui montre la modal d’upload **sans** réhydrater la progression.

Conséquences visibles aujourd’hui :

| Surface | Réduire | Reprendre live | Annuler fiable | Dans le centre |
|---|---|---|---|---|
| Upload + chiffrement | Partiel (dismiss dédié) | Non | Oui côté serveur si le poll tourne | Oui encrypt seulement |
| Extraction ZIP | Non (✕ = confirm cancel) | Non | Fragile (confirm natif) | Non |
| Préparation archive ZIP | Non (✕ ferme la modal, le tick peut continuer ou pas selon le JS) | Non | Pas de bouton Annuler dédié | Non |
| Téléchargement fichier | N/A : **pas de modal** — clic → grant + `<a download>` natif | N/A | N/A (navigateur) | Non |
| Ancienne page `/files/{id}/progress` | Hors listing | — | Manager JS legacy | Non branché |

**Décision** : on ne « améliore pas le dropdown encrypt ». On pose un **Centre des opérations** unique, un **chrome de modal d’opération** unique, et on y fait entrer **toutes** les opérations longues, y compris **tous** les téléchargements, **sans changer le mécanisme DL100** (grant + Range + téléchargement natif du navigateur).

---

## 1. Vision (une phrase)

> Chaque opération longue se déroule dans **la même famille de modales** (titre, progression, Réduire, Annuler). Réduire l’envoie dans le **Centre des opérations** (bouton à côté d’Accueil). Reprendre rouvre **la même modal, sur le même `taskId`, en progression live**. Annuler arrête vraiment le travail **quand c’est techniquement possible** ; sinon l’UI le dit sans mentir.

---

## 2. Principes figés (non négociables)

### P1 — Deux familles de modales, jamais mélangées

**Famille A — Dialogue** (pas d’opération, pas de réduire, pas de centre)  
Formulaires courts : créer dossier, renommer, supprimer, partager, propriétés, filtres, déplacer, éditeur texte, prévisualisation média, rapport de bug.

**Famille B — Opération** (chrome OPS obligatoire)  
Travail long, état `running`, `taskId`, Réduire / Reprendre / Annuler.

Une modal Famille A **ne doit jamais** afficher Réduire. Une Famille B **doit toujours** l’afficher dès que le travail a démarré.

### P2 — Dismiss ≠ cancel

Fermer / Esc / backdrop pendant une opération **n’annule pas**.  
Ça ouvre le **même** mini-dialogue OPS (pas un `confirm()` natif) :

| Bouton | Rôle | Focus |
|---|---|---|
| **Réduire** (primaire) | Cache la modal, le travail continue, ligne dans le centre | Défaut |
| **Rester ici** | Ferme le mini-dialogue, modal d’opération reste ouverte | Secondaire |
| **Annuler l’opération** | Danger — appelle le cancel **réel** du type (voir §6) | Tertiaire |

Tant que l’opération n’a **pas** démarré (utilisateur encore sur le formulaire : options d’extract, file picker upload), ✕ peut fermer sans ce dialogue.

### P3 — Reprendre = modal pleine, pas un widget

« Plein écran » = **la modal d’opération complète** (même layout, barre, ETA, Annuler), pas une pastille dans le dropdown.

Clic sur une ligne du centre → `show()` de la modal du `modalKey` + **réhydratation** : `taskId`, %, octets, phase, estimateur ETA (reset acceptable).

### P4 — Un registre, plusieurs exécuteurs

Le Centre des opérations ne connaît **pas** encrypt, ZIP ou download. Il connaît un **contrat tâche** (§5).  
Chaque type a un **exécuteur** (JS + éventuellement job serveur) qui implémente : `start`, `tick/progress`, `cancel`, `hydrateModal`.

### P5 — Téléchargement : mécanisme DL100 inchangé, enveloppe UX changée

**On ne revient pas** à OPFS / File System Access / `FilesDownloadManager` comme voie principale (bilan DL100 : reprise fictive, quotas, Worker).

**On garde** : `DownloadGrantService` + `RangeStreamResponder` + `/dl/file/{id}` + `/dl/archive/{token}` + `<a download>` (ou équivalent `FilesDownloadLauncher.startNativeDownload`).

**On change** : plus de clic silencieux. **Toute** taille passe par la **modal d’opération Téléchargement**, réductible.

Conséquence honnête (à afficher, pas à cacher) : le **navigateur** écrit le fichier et gère la reprise HTTP. La page **ne reçoit pas** `loaded/total`. Donc **pas d’ETA in-page** sur le flux octet (déjà noté EPIC-ETA). La modal est un **cockpit** (état, nom, taille, lien reprisable, réduire, quitter le suivi).

### P6 — ETA uniquement via `transfer-eta.js`

Là où des octets **sont** connus de la page (upload chunks, encrypt ticks, extract ticks, archive prepare ticks) : `TransferEta` exclusivement.  
Là où ils ne le sont pas (download natif) : pas de fausse barre à 37 %.

### P7 — Annuler a un sémantique **par type**, documenté, testé

« Un bouton Annuler qui marche » ≠ le même effet partout. Table §6. Interdit : bouton Annuler qui ne fait qu’un `hide()` pendant qu’un job serveur tourne.

---

## 3. Périmètre

### 3.1 Inclus (v1)

| ID | Opération | `type` | Exécuteur existant à brancher |
|---|---|---|---|
| OP-UPLOAD | Envoi + chiffrement | `upload_encrypt` | `FilesUploadEncrypt` + `UploadEncryptJobService` |
| OP-EXTRACT | Extraction ZIP | `zip_extract` | Modal extract + tick/cancel déjà routés |
| OP-ARCHIVE | Préparation ZIP virtuel | `archive_prepare` | `files-archive-flow.js` tick `/files/archives/{token}/tick` |
| OP-DL-FILE | Téléchargement **un** fichier, **toute taille** | `download_file` | `FilesDownloadLauncher` **dans** une modal |
| OP-DL-ARCHIVE | Téléchargement de l’archive une fois `ready` | `download_archive` | Même launcher, URL `/dl/archive/{token}` |

### 3.2 Inclus (chrome / nav)

- Bouton **Centre des opérations** à droite immédiate du brand Accueil (`path('app_home')`), visible dès `ROLE_SHARE` / `ROLE_SHARE_SEND` (même visibilité que le listing fichiers utilisateur ; admin godview : même bouton, owner effectif déjà résolu).
- Chrome commun des modales Famille B (Twig partial + classes CSS + i18n).
- Mini-dialogue unique Réduire / Rester / Annuler.
- Reprise live par `taskId`.
- Badge = nombre de `running` (+ option `failed` non acquittés).

### 3.3 Exclu (v1)

- Transformer rename / share / properties / preview / editor en opérations.
- Delete/move bulk comme jobs tick (restent Famille A ; HTTP unique).
- Recoder le serveur de download (Range, Zip64, grants).
- ETA in-page sur le téléchargement natif.
- Messenger / workers PHP.
- Deep-link `?task=` (v1.1, story optionnelle US-12).
- Landing publique (`public-file-landing.js`) : **hors v1 listing authentifié** ; story US-11 note le follow-up (même chrome si on l’aligne plus tard).
- i18n de/lt/no : v1 = **fr + en** complets ; autres locales alignées dans US-10.

### 3.4 Dette à tuer dans OPS (pas un chantier séparé)

La page `templates/files/download_progress_page.html.twig` + `files-download-manager.js` **ne sont plus la voie listing**.  
US-09 : soit les débrancher / supprimer, soit les laisser orphelines **interdites** d’être réactivées par le listing. **Le listing n’ouvre plus jamais cette page.**

---

## 4. Chrome modal d’opération (contrat visuel — toutes identiques)

Partial Twig unique, ex. `templates/operations/_operation_modal_chrome.html.twig`, utilisé par upload (phase running), extract (phase running), archive, download.

```
┌─────────────────────────────────────────────────────────────┐
│ [icône type] Titre opération                    [Réduire] [✕]│  ← header
│ sous-titre : nom du fichier / « 3 fichiers »                │
├─────────────────────────────────────────────────────────────┤
│ Phase (ex. Chiffrement… / Préparation de l’archive…)        │
│ ████████████░░░░  42 %                                      │
│ 12,4 Go / 25,0 Go                                           │
│ 85 Mo/s · ~4 min restant          ← seulement si octets JS  │
│ [zone spécifique au type : file d’upload, options extract…] │
│ [alerte erreur]                                             │
├─────────────────────────────────────────────────────────────┤
│ [Annuler l’opération]                    [action primaire]  │  ← footer
└─────────────────────────────────────────────────────────────┘
```

### 4.1 Règles chrome

| Élément | Règle |
|---|---|
| Titre | i18n par `type` (`files.ops.types.upload_encrypt`, etc.) |
| Réduire | Visible **uniquement** si `status === running`. Clique = même effet que « Réduire » du mini-dialogue (pas de second confirm). |
| ✕ / Esc / backdrop | Si `running` → empêcher hide, ouvrir mini-dialogue OPS. Sinon → hide normal. |
| Backdrop | `static` dès `running` (pas de fermeture accidentelle). |
| Barre % | Types à octets JS : % réel. Type download natif : barre **indéterminée** (`progress-bar-animated`) + texte §7. |
| ETA | Uniquement si `TransferEta.labelReady`. Sinon i18n « Calcul du temps restant… » **ou** (download natif) pas d’ETA du tout. |
| Annuler | Toujours visible si `running`. Label i18n du type. Comportement §6. Désactivé + spinner pendant la requête cancel. |
| Primaire | Contextuel : « Envoyer », « Extraire », « Télécharger » **avant** start ; **caché ou transformé** après start (on ne relance pas). |
| Largeur | `modal-dialog-centered` ; `modal-lg` si file d’upload. Pas de `modal-fullscreen` CSS : « plein écran » = cette modal, pas une nouvelle page. |

### 4.2 Mini-dialogue OPS (un seul pour toute l’app)

Remplace `_upload_encrypt_dismiss_modal.html.twig` (fusion / rename).  
Textes **paramétrés** par type (`%operation%`).  
IDs stables : `opsDismissModal`, boutons `data-ops-dismiss-reduce|stay|cancel`.

### 4.3 Centre des opérations (nav)

Placement **figé** :

```
[brand Accueil]  [ Centre des opérations ▾  (badge) ]  ……  Fichiers | Admin…
```

Pas en bout de `ms-auto` noyé après d’autres liens. C’est le **premier** contrôle après Accueil.

Dropdown :

```
Centre des opérations
────────────────────────────────
film.mkv          Chiffrement  42%   85 Mo/s
 vacances.zip     Extraction   10%
 3 fichiers       Archive      Préparation
 rapport.pdf      Téléchargement  Navigateur
────────────────────────────────
vide : « Aucune opération en cours »
```

Par ligne `running` : **Reprendre** | **Annuler** (si `cancelSupported === true`).  
Par ligne terminale récente : statut + disparition après ack / TTL (réutiliser `acknowledge` ENC).

---

## 5. Contrat tâche (source de vérité)

Toute ligne du centre **et** toute modal Famille B parle ce JSON (champs stables, camelCase public) :

```json
{
  "taskId": "hex32-ou-uuid",
  "type": "upload_encrypt|zip_extract|archive_prepare|download_file|download_archive",
  "status": "running|done|failed|cancelled|handed_to_browser",
  "label": "film.mkv",
  "phaseKey": "files.ops.phase.encrypt",
  "doneBytes": 0,
  "totalBytes": 0,
  "percent": 42,
  "progressKnown": true,
  "cancelSupported": true,
  "modalKey": "files_upload|files_extract_zip|files_archive|files_download",
  "errorKey": null,
  "notified": false,
  "ownerUserId": 1,
  "createdAt": 0,
  "updatedAt": 0,
  "finishedAt": null,
  "payload": {}
}
```

| Champ | Sens |
|---|---|
| `progressKnown` | `false` pour download natif → UI indéterminée, pas d’ETA |
| `cancelSupported` | `false` après `handed_to_browser` → pas de bouton Annuler mensonger |
| `modalKey` | Quelle modal `show()` au Reprendre |
| `payload` | Type-specific : `uploadId`, `sharedFileId`, `archiveToken`, `downloadUrl`, `grant` (jamais le secret long en log), `extractJobId` |
| `handed_to_browser` | Download natif **démarré**. Le serveur n’a plus de job. Le centre garde la ligne jusqu’à ack utilisateur « Terminé / Masquer » |

### 5.1 Où vit l’état

| Type | Persistance | Conséquence reprise après F5 |
|---|---|---|
| `upload_encrypt` | Serveur `var/tasks/{owner}/` (déjà) | Hydratation GET `/files/operations` (évolution de `/files/tasks`) |
| `zip_extract` | Meta job extract existante | Idem, **même liste** |
| `archive_prepare` | Table `download_archive` / tick existant | Idem |
| `download_file` / `download_archive` | **Client** (sessionStorage) + optionnellement une ligne serveur « intent » si on veut toast cross-device — **v1 = client + centre de l’onglet** | F5 : on **ne peut pas** savoir si le navigateur a fini. Ligne → `handed_to_browser` recréée depuis sessionStorage, CTA « Copier le lien reprisable » / « Masquer » |

API unique list/ack :

- `GET /files/operations` → fusion **serveur** (encrypt, extract, archive running/failed/done récents) + le client **merge** ses downloads locaux.
- `POST /files/operations/ack` — étend `files_tasks_ack`.
- Cancel : **inchangé par type** (routes existantes encrypt/extract/archive). Le centre appelle l’exécuteur, pas une route magique unique qui casserait les CSRF.

Le JS nav actuel `files-background-tasks.js` est **remplacé** par `files-operations-center.js` (un module). Pas deux badges.

---

## 6. Sémantique Annuler (le contrat « ça marche »)

| `type` | Avant start | Pendant `running` | Après succès / `handed_to_browser` |
|---|---|---|---|
| `upload_encrypt` | Fermer modal = abandon file locale, rien serveur | `POST …/encrypt/{id}/cancel` → stop ticks, unlink `.part` + `.dat` partiel, **pas** de `SharedFile` | Bouton absent |
| `zip_extract` | ✕ ferme le formulaire | `POST extract cancel` existant + cleanup job | Absent |
| `archive_prepare` | ✕ | Annuler le tick prepare (endpoint à **exposer clairement** s’il manque : story US-05) ; pas de ZIP partiel disque (virtuel) | Absent |
| `download_file` | ✕ = aucun grant | Si grant demandé mais `<a download>` **pas encore** cliqué : on n’appelle pas `startNativeDownload`. Si déjà cliqué : **on ne peut pas** tuer le flux HTTP du navigateur depuis JS | Annuler **interdit**. CTA « Masquer » + texte : *Annulez depuis la fenêtre de téléchargements du navigateur.* |
| `download_archive` | idem | idem après `ready` + lanceur natif | idem |

**Interdit** : un Annuler extract/encrypt qui ne fait que `modal.hide()`.  
**Interdit** : un Annuler download qui prétend stopper le fichier alors que Chrome/Firefox continue.

Tests d’acceptation par ligne de ce tableau (US-08).

---

## 7. Téléchargement en modal (US-04 détaillée ici car c’est le piège)

### 7.1 Parcours unique, toute taille

1. Clic icône / menu Télécharger (fichier, ou archive déjà `ready`).
2. **Modal OPS Téléchargement** s’ouvre (`modalKey=files_download`), `taskId` client généré, status `running`, `progressKnown=false`.
3. Corps : nom, taille formatée, phrase i18n : *Le fichier sera enregistré par le navigateur. La reprise HTTP (Range) reste disponible via le lien ci-dessous si le transfert est interrompu.*
4. Bouton primaire **Télécharger** → `FilesDownloadLauncher.requestGrantAndDownload` **sans changer** l’URL `/dl/…?g=`.
5. Au succès du grant + déclenchement `<a download>` : status → `handed_to_browser`, primaire disparaît, apparaît **Copier le lien reprisable** (`copyResumableLink` déjà là).
6. **Réduire** à tout moment après ouverture → ligne dans le centre (icône download, label, statut « Navigateur »).
7. **Reprendre** → **même modal**, mêmes boutons, **pas** un nouvel onglet, **pas** `download_progress_page`.

### 7.2 Ce qu’on ne fait pas

- Pas de seuil `downloadManagerUiThresholdBytes` pour changer d’UI (petit vs gros). **Une** UI.
- Pas de `window.location = /files/{id}/progress`.
- Pas de `FilesDownloadManager.download()` sur le listing.
- Pas de barre 0–100 inventée (interdit P6).

### 7.3 Archive (deux phases, deux tâches liées)

Préparer un ZIP de sélection = opération `archive_prepare` (barre **réelle** sur ticks, ETA OK).  
Quand `ready` : **enchaîner** une opération `download_archive` (modal download, mécanisme natif).  
Réduire pendant prepare ≠ perdre le token. Reprendre = modal archive (si encore prepare) ou modal download (si ready).  
`payload.archiveToken` relie les deux.

### 7.4 Multi-téléchargements

v1 : **une** modal download visible à la fois ; N lignes `handed_to_browser` dans le centre (plusieurs `<a download>` natifs sont déjà possibles).  
Reprendre une ligne A alors que B est au premier plan : hide B (réduire auto) puis show A — pas deux Bootstrap modals empilées (hors mini-dialogue).

---

## 8. Harmonisation des opérations existantes

### 8.1 Upload / chiffrement (`upload_encrypt`)

- Réutiliser le job tick actuel.
- Remplacer le dismiss encrypt-only par le mini-dialogue OPS.
- **Reprendre** : obligatoire — réattacher `pollUntilTerminal` (ou GET état + tick) au `taskId`, restaurer file item `processing`, barre globale, `TransferEta` reset.
- Bouton **Réduire** dans le header dès phase `processing` (et dès chunks `uploading` ? **oui** : l’upload réseau est déjà une opération ; réduire pendant les chunks **continue** les XHR dans l’onglet — si l’onglet est tué, l’upload s’arrête : le centre affiche `failed` client. **Ne pas** prétendre que les chunks survivent à un kill navigateur. Seul l’encrypt serveur survit. Texte i18n distinct : *L’envoi réseau s’arrête si vous fermez l’onglet ; le chiffrement déjà lancé continue sur le serveur.*)

### 8.2 Extract ZIP (`zip_extract`)

- Formulaire options = encore Famille A **jusqu’au submit**.
- Au submit → chrome OPS, `running`, ticks existants, **Réduire** au lieu de confirm-cancel sur ✕.
- `confirm()` actuel (`data-msg-cancel-confirm`) **supprimé**.
- Reprendre = show modal extract + reprendre la boucle tick sur `jobId`.

### 8.3 Archive prepare (`archive_prepare`)

- Modal actuelle : pas d’Annuler, ✕ ambigu.
- Brancher chrome OPS + Annuler prepare + Réduire + registre.

---

## 9. Stories

Chaque story est **livrable isolément** avec AC testables. Ordre = §11.

---

### US-01 — Inventaire figé + chrome Twig/CSS/i18n

**En tant que** équipe,  
**je veux** un chrome unique et une liste officielle Famille A / B,  
**afin que** les prochaines modales ne dérivent plus.

**Critères**

- [ ] Tableau §2.P1 recopié dans `.cursor/rules/operations-center.mdc` (Famille A vs B).
- [ ] Partial `_operation_modal_chrome.html.twig` : slots header (titre, réduire, close), body, footer (annuler, primaire).
- [ ] Mini-dialogue unique `opsDismissModal` (Réduire / Rester / Annuler) ; suppression progressive du dismiss encrypt-only.
- [ ] Classes CSS partagées (header actions, progress, footer danger).
- [ ] i18n fr+en : `files.ops.*` (nav, vide, reprendre, réduire, rester, annuler, phases, download handed_to_browser, warning onglet).
- [ ] Contrat PHPUnit : le chrome existe ; les 4 `modalKey` cibles incluent Réduire **dans le template d’opération** (pas les dialogues rename/delete).

**Hors story** : brancher la logique JS.

---

### US-02 — Centre des opérations (nav)

**En tant qu’** utilisateur connecté,  
**je veux** un bouton **Centre des opérations** juste à droite d’Accueil,  
**afin de** voir tout ce qui tourne et y revenir.

**Critères**

- [ ] Placement DOM : après `.navbar-brand`, avant le collapse des autres liens (ou premier item du collapse mobile avec label complet).
- [ ] Badge `running` (et `failed` non ack, style distinct si on les compte — **v1 : running seulement** pour le chiffre, failed restent dans la liste).
- [ ] Dropdown : label, type i18n, % **ou** « Navigateur » si `!progressKnown`, actions Reprendre / Annuler selon flags.
- [ ] Vide i18n.
- [ ] Module unique `files-operations-center.js` ; **retrait** de `files-background-tasks.js` (plus de double badge).
- [ ] `GET /files/operations` agrège encrypt + extract running + archive prepare running (même payload §5). Downloads fusionnés côté client.
- [ ] Polling 5 s **ou** refresh à l’ouverture du dropdown + `upsert` des exécuteurs. Pas les deux agressifs.
- [ ] Accessibilité : `aria-label` du bouton, badge `aria-live="polite"` limité (pas à chaque tick % — seulement changement de compteur).

---

### US-03 — Bus JS exécuteurs + Reprendre live

**En tant qu’** utilisateur,  
**je veux** cliquer Reprendre et retrouver la modal **en cours**,  
**afin de** ne pas perdre le fil.

**Critères**

- [ ] API globale minimale `window.FilesOperations` : `register(type, executor)`, `upsert(task)`, `reduce(taskId)`, `resume(taskId)`, `cancel(taskId)`.
- [ ] `resume(taskId)` : `bootstrap.Modal.show(modalKey)` + `executor.hydrate(task)` **obligatoire** (pas un show vide).
- [ ] Une seule modal Famille B visible ; resume d’une autre = reduce automatique de la courante.
- [ ] Mini-dialogue unique branché sur `hide.bs.modal` de **toutes** les Famille B running.
- [ ] Bouton header Réduire = `reduce` sans mini-dialogue (l’utilisateur a déjà choisi).
- [ ] Test contrat JS : `resume` mentionné pour upload, extract, archive, download ; `window.confirm` **absent** des chemins extract/upload dismiss.

**Executors v1** : stubs + upload_encrypt réel (US-04 extract/archive/download les remplissent).

---

### US-04 — Upload/encrypt conforme OPS (régression ENC interdite)

**Critères**

- [ ] Chrome OPS sur la modal upload dès `uploading` / `processing`.
- [ ] Reduce pendant encrypt : job serveur continue ; centre à jour (% live via ticks **même modal fermée** — le poll **continue dans l’onglet**).
- [ ] Resume : poll rattache, barre et ETA repartent (reset ETA OK).
- [ ] Cancel modal **et** centre : même endpoint encrypt cancel + cleanup (contrats ENC conservés).
- [ ] Plus de `filesUploadEncryptDismissModal` spécifique (alias vers ops dismiss OK le temps de migrer).
- [ ] Scénario manuel : 1 fichier moyen, réduire à 20 % encrypt, reprendre, % ≥ précédent.

---

### US-05 — Extract ZIP conforme OPS

**Critères**

- [ ] Options extract = pas de Réduire. Après submit = chrome OPS.
- [ ] ✕ running → mini-dialogue OPS, plus de `window.confirm`.
- [ ] Reduce : ticks continuent (même onglet). Resume = hydrate jobId + barre/phase.
- [ ] Cancel (modal + centre) : route cancel existante, pas de fichiers extraits partiels selon règles extract actuelles.
- [ ] Ligne visible dans le centre avec type Extraction.

---

### US-06 — Archive prepare conforme OPS

**Critères**

- [ ] Modal archive utilise chrome OPS (Réduire, Annuler, barre, ETA `TransferEta` déjà câblé).
- [ ] Si cancel prepare n’existe pas proprement : l’ajouter (endpoint + flag meta) **dans cette story**, pas en silence.
- [ ] Reduce / resume sur `archiveToken`.
- [ ] `ready` → enchaîne US-07 (`download_archive`) sans forcer un nouvel onglet.

---

### US-07 — Téléchargement fichier + archive en modal (mécanisme natif)

**Critères**

- [ ] Listing : plus d’appel direct `requestGrantAndDownload` sans modal.
- [ ] Toute taille : même modal `files_download`.
- [ ] Après primaire Télécharger : `startNativeDownload` **identique** (grant query `g=`, URL `/dl/file` ou `/dl/archive`).
- [ ] `progressKnown=false`, pas d’ETA, barre indéterminée jusqu’à `handed_to_browser` puis texte i18n « Enregistré par le navigateur — reprenez via le lien si besoin ».
- [ ] Copier lien reprisable fonctionne.
- [ ] Réduire / Reprendre = même modal, même grant/url (re-demander un grant si expiré : message i18n + bouton Réessayer, **pas** de plantage silencieux).
- [ ] Annuler : conforme tableau §6 (pas de fake stop HTTP).
- [ ] Seuil manager / page progress **non utilisés**.
- [ ] Contrats : launcher toujours utilisé ; `download_progress_page` non référencée par `files-space.js` listing.

---

### US-08 — Annuler : matrice testée

**Critères**

- [ ] Table §6 → un test contrat ou smoke par type `running`.
- [ ] Encrypt cancel : déjà tick unit ; garder + contrat UI bouton présent.
- [ ] Extract cancel : plus de confirm natif ; POST cancel appelé (contrat source).
- [ ] Archive cancel : POST appelé, plus de ligne `running`.
- [ ] Download : bouton Annuler **absent** en `handed_to_browser` ; présent avant le clic natif.
- [ ] Interdit : hide-only cancel sur encrypt/extract (grep `hide()` dans handlers cancel).

---

### US-09 — Retrait voie legacy download in-page listing

**Critères**

- [ ] `files-space.js` ne navigue plus vers `files_download_progress`.
- [ ] Décision écrite dans cet épic (faite) : page progress = legacy, pas listing.
- [ ] Soit suppression fichiers orphelins (manager/checkpoint/sinks/page) **si** plus aucun consommateur (vérifier landing publique US-11), soit garde **uniquement** hors listing avec commentaire « do not reattach ».
- [ ] PHPUnit : listing JS n’embed plus `FilesDownloadManager.download`.

---

### US-10 — i18n + a11y chrome

**Critères**

- [ ] fr + en complets pour `files.ops.*`.
- [ ] de/lt/no : au minimum clés présentes (même fr temporaire **interdit** — copier en + traduire ou calquer EN). Préférer traductions réelles.
- [ ] Focus : à l’ouverture mini-dialogue, focus sur **Réduire**. À l’ouverture modal opération, focus titre ou primaire.
- [ ] `aria-busy` sur modal running. Barre avec `aria-valuenow` seulement si `progressKnown`.

---

### US-11 — Landing publique (follow-up, pas bloquant v1 listing)

**Critères**

- [ ] Documenter l’écart : public landing peut encore utiliser manager/seuil.
- [ ] v1.1 : même modal download native **ou** justification écrite si on laisse le landing tel quel.
- [ ] Ne pas bloquer US-01→10.

---

### US-12 — Deep-link `?op=<taskId>` (optionnel v1.1)

**Critères**

- [ ] Au chargement listing, si query présente et tâche connue → `resume`.
- [ ] Sinon ignorer sans erreur.

---

### US-13 — Règle Cursor + épic ENC

**Critères**

- [ ] `.cursor/rules/operations-center.mdc` : toute nouvelle modal longue **doit** Famille B + registre ; interdit nouveau `confirm()` dismiss ; interdit download listing hors modal OPS ; ETA = `transfer-eta.js`.
- [ ] ENC : stories US-06/08 marquées **supersédées par OPS** (le chiffrement reste, le centre ENC ne fait plus autorité).
- [ ] ETA : note « download natif : pas d’ETA in-page » conservée, UI modal cockpit.

---

## 10. Parcours de validation (scénarios figés)

| # | Scénario | Attendu |
|---|---|---|
| S1 | Petit fichier upload | Modal upload → chiffrement rapide → done. ✕ avant start ferme sans dialogue. |
| S2 | Gros upload + réduire pendant chunks | Centre montre Envoi ; kill onglet → pas de miracle (échec/stop). Texte warning visible une fois. |
| S3 | Gros upload + réduire pendant chiffrement | Serveur continue ; badge ≥ 1 ; resume = % live. |
| S4 | ✕ pendant chiffrement | Mini-dialogue ; Réduire défaut ; Annuler cleanup. |
| S5 | Extract ZIP running, réduire, reprendre | Phase/count identiques ; pas de confirm natif. |
| S6 | Extract Annuler depuis le centre | Job cancelled, modal fermée si ouverte. |
| S7 | Sélection → ZIP | Modal prepare + ETA ; réduire ; ready → modal download native. |
| S8 | Télécharger 1 ko | **Modal** download puis natif. Pas de page progress. |
| S9 | Télécharger 25 Go | **Même** modal. Grant + `/dl/file`. Reprise = lien copiable / gestionnaire navigateur. Pas d’ETA in-page. |
| S10 | Réduire download `handed_to_browser`, reprendre | Même nom, bouton copier, pas de second grant forcé si encore valide. |
| S11 | Deux opérations (encrypt + download) | Deux lignes centre ; une modal à la fois. |
| S12 | F5 pendant encrypt | Centre réhydrate depuis serveur. |
| S13 | F5 pendant download natif | Ligne sessionStorage `handed_to_browser` ou vide ; **pas** de % inventé. |
| S14 | Locale EN | Chrome et centre traduits. |

---

## 11. Ordre d’implémentation

1. US-01 chrome + US-13 règle (sinon les stories suivantes divergent).  
2. US-02 centre nav + agrégat API.  
3. US-03 bus + resume.  
4. US-04 encrypt (première opération réelle, on a déjà le job).  
5. US-05 extract.  
6. US-06 archive.  
7. US-07 download modal (cœur demande produit + contrainte DL100).  
8. US-08 matrice cancel.  
9. US-09 legacy.  
10. US-10 i18n/a11y.  
11. US-11 / US-12 plus tard.

**Interdit** : implémenter US-07 en réactivant `FilesDownloadManager` « pour avoir une barre ». Ça viole P5 et DL100.

---

## 12. Risques et décisions déjà tranchées

| Risque | Décision |
|---|---|
| « L’utilisateur croit qu’Annuler stoppe Chrome » | Copy explicite + bouton retiré après `handed_to_browser` |
| « On veut une vraie barre 25 Go in-page » | Non en v1. Contredit DL100. Un futur épic **séparé** devrait démontrer une reprise disque réelle avant d’abandonner le natif |
| Poll encrypt modal fermée | Le JS de l’onglet **doit** continuer à ticker ; reduce ≠ `abort()` XHR/fetch |
| `var/tasks` permissions | Traité par **EPIC-PERM** (`./fix-runtime-permissions.sh` en user PHP/Apache). OPS suppose `var/` writable. |
| Double badge ENC + OPS | Un seul bouton, un seul JS |
| Accueil vs Fichiers | Accueil = brand `app_home`. Le centre est **à sa droite**, pas à côté du lien Fichiers |

---

## 13. Définition de « terminé »

OPS v1 est **fait** quand :

1. Les 5 types §3.1 apparaissent dans le centre s’ils tournent.  
2. Les 4 modales d’opération partagent le chrome (Réduire / ✕ / Annuler).  
3. Reprendre ouvre la **bonne** modal **avec** l’état live (encrypt/extract/archive) ou cockpit download.  
4. Annuler respecte §6 (preuves tests S4, S6, S8/S10).  
5. Aucun téléchargement listing ne bypass la modal.  
6. Aucun `window.confirm` sur dismiss d’opération.  
7. Mécanisme `/dl/*` + grant + Range **inchangé** (contrats DL100 toujours verts).

Ce n’est **pas** fait si on a seulement un dropdown encrypt plus joli.
