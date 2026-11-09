# EPIC OPS-PURGE — Tout arrêter et tout supprimer (centre des opérations)

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-OPS-PURGE |
| Statut | Implémenté (v1) |
| Auteur | Stephane H. |
| Date | 2026-11-09 |
| Projet | StuWebStorage |
| Dépend de | `EPIC-OPS` (centre, bus `FilesOperations`, cancel par type) |
| Hors scope | Changer la sémantique cancel unitaire ; recoder DL100 ; Messenger |

---

## 0. Pourquoi

Après un gros upload / un 504 / un onglet laissé ouvert, le **Centre des opérations** peut se remplir de lignes `running` zombies, `failed`, ou downloads `handed_to_browser` que l’utilisateur ne veut plus voir. Aujourd’hui il faut **annuler une par une** (quand le bouton existe) ou attendre TTL / ack.

Besoin produit :

> En bas de la liste du centre : un action **« Tout arrêter et tout supprimer »** qui **dégage d’un coup** toutes les tâches **non terminées avec succès** (en cours, bloquées, échouées, annulées encore listées, suivis download locaux).

---

## 1. Vision (une phrase)

> Un seul bouton, en bas du dropdown Centre des opérations, **arrête ce qui peut l’être** et **retire du centre** tout ce qui n’est pas un `done` réussi, sans mentir sur ce que le navigateur continue éventuellement d’écrire.

---

## 2. Principes figés

### P1 — Cibles = tout sauf `done` réussi

| `status` | Action purge |
|---|---|
| `running` | Cancel **réel** du type (même sémantique qu’Annuler unitaire OPS §6) + retrait centre + cleanup artefacts |
| `failed` | Suppression meta / retrait liste (plus d’ack manuel nécessaire) |
| `cancelled` encore visible | Suppression meta / retrait liste |
| `handed_to_browser` | Retrait centre + sessionStorage **seulement** ; **pas** d’abort du téléchargement Chrome |
| `done` | **Non ciblé** — reste jusqu’à ack / TTL existants (c’est « terminé ») |

« Bloquée » = `running` sans progrès perceptible **ou** `failed` resté affiché : même bouton, pas de statut serveur dédié.

### P2 — Confirm obligatoire (destructif)

Un clic ne purge pas directement. Mini-dialogue (Famille A ou réutilisation pattern OPS dismiss) :

| Bouton | Rôle |
|---|---|
| **Tout arrêter et tout supprimer** (danger, primaire confirm) | Exécute la purge |
| **Annuler** | Ferme le confirm, rien ne change |

Pas de `window.confirm()` natif (contrat OPS).

### P3 — Un endpoint agrégé + exécuteurs locaux

- **Serveur** : une route `POST /files/operations/purge` (CSRF) qui, pour l’owner effectif (godview inclus) :
  - cancel + cleanup tous les jobs `upload_encrypt` / `zip_extract` / `archive_prepare` encore `running` ;
  - **supprime** les metas terminales non-`done` (`failed`, `cancelled`) encore listées ;
  - archives `preparing` / `failed` : cancel / expire selon API archive existante.
- **Client** : après succès (ou en parallèle contrôlée) :
  - stoppe tous les polls encrypt/extract/archive de l’onglet ;
  - ferme / reset les modales Famille B ouvertes liées à ces `taskId` ;
  - purge les tâches `download_*` du registre JS + sessionStorage ;
  - re-fetch `GET /files/operations` et re-render (liste vide ou seulement `done`).

Interdit : N clics JS aveugles sans endpoint si le serveur garde des `.part` / jobs tournants.

### P4 — Honnêteté download natif

Si une ligne est `handed_to_browser`, le libellé du confirm rappelle :

> Les téléchargements déjà démarrés dans le navigateur ne peuvent pas être stoppés ici ; seules les lignes du centre sont retirées.

### P5 — Placement UI figé

Dans le dropdown `#files-operations-center-menu` :

```
[ lignes d’opérations… ]
────────────────────────────────
[ Tout arrêter et tout supprimer ]   ← bas, danger outline, full width
```

- Visible dès qu’il existe **au moins une** tâche éligible (P1).
- Caché / désactivé si la liste est vide **ou** ne contient que des `done`.
- Ne remplace pas Annuler unitaire par ligne.

### P6 — Idempotence et droits

- Double-clic / retry : safe (déjà cancelled / meta absente → no-op).
- Même auth que list/ack (`ROLE_SHARE` / owner résolu godview).
- Logs audit upload existants : un event `upload.ops.purge` (et équivalents extract/archive si logger présent) avec `owner_user_id` + compteurs `cancelled` / `removed`.

---

## 3. Périmètre

### Inclus

- Bouton + confirm i18n (fr/en + alignement de/lt/no).
- `POST /files/operations/purge`.
- Purge serveur encrypt / extract / archive.
- Purge client downloads + stop polls + refresh centre.
- Contrats PHPUnit (wiring route, i18n, bouton, pas de `window.confirm`).

### Exclu

- Purger les `done` réussis (ack reste le chemin).
- Abort HTTP Range déjà lancé par Chrome.
- « Undo » après purge.
- Cron serveur hors requête utilisateur.

---

## 4. Stories

### US-01 — Contrat API `POST /files/operations/purge`

**En tant que** client du centre,  
**je veux** un endpoint unique CSRF qui purge les tâches non-`done` de l’owner,  
**afin que** disque et metas ne restent pas zombies.

**Critères**

- Route nommée `files_operations_purge`, methods `POST`.
- Body : `_csrf_token` (+ context owner godview comme list).
- Réponse JSON : `{ status: ok, purged: { cancelled: n, removed: n }, tasks: [...] }` (liste post-purge, même forme que GET).
- Pour chaque `upload_encrypt` `running` : même cleanup que `cancel` (`.part`, cipher partiel, meta `cancelled` puis **unlink meta** ou retention 0 pour purge).
- Pour `failed` / `cancelled` listés : **suppression fichier meta** (plus dans GET).
- `zip_extract` : `cancelJob` si running + rollback ; suppression metas failed/cancelled.
- `archive_prepare` `running`/`failed` : cancel/expire existant ; plus listé si terminal non-done.
- `done` intacts.
- 403 CSRF / 400 owner invalide → JSON erreur existante.
- Log structuré compteurs (canal upload ou ops).

**Estimate** : M

---

### US-02 — Services : `purgeNonDoneForOwner` par type + agrégateur

**En tant que** mainteneur,  
**je veux** la logique dans `OperationsListService` (ou `OperationsPurgeService`) qui délègue aux services existants,  
**afin d’éviter** un monolithe dans le controller.

**Critères**

- `UploadEncryptJobService::purgeNonDoneForOwner(int $ownerId): array{cancelled:int,removed:int}`
- `ZipExtractService::purgeNonDoneForOwner(...)` idem
- Archive : méthode claire sur `DownloadArchiveService` (cancel preparing + drop failed visibles)
- Pas de duplication du code cancel : **réutiliser** `cancel` / `cancelJob` puis delete meta
- Unit / contract : running → cancelled+removed ; failed → removed ; done → untouched

**Estimate** : M

---

### US-03 — UI centre : bouton + confirm

**En tant qu’utilisateur**,  
**je veux** en bas du dropdown « Tout arrêter et tout supprimer » avec confirmation,  
**afin de** vider le centre d’un geste.

**Critères**

- Twig/JS : footer fixe sous la liste (`ops-center-purge`).
- Label i18n `files.ops.purge_all` ; confirm titre/body `files.ops.purge_all_confirm_*` (mention download natif).
- Bouton visible seulement si ≥1 tâche éligible.
- Pendant la requête : disabled + spinner ; pas de second submit.
- Succès : liste rafraîchie ; badge à jour ; toasts optionnels non bloquants.
- Échec HTTP : message via `FilesHttpError` / flash JSON, liste inchangée sauf re-fetch.

**Estimate** : S–M

---

### US-04 — Client : stop polls, registre, modales

**En tant qu’utilisateur au milieu d’un chiffrement**,  
**je veux** que « Tout arrêter » coupe aussi les polls de **cet** onglet et ferme les modales liées,  
**afin de** ne pas relancer un tick sur une task déjà purgée.

**Critères**

- `FilesOperations.purgeAll()` (ou équivalent) :
  1. POST purge
  2. pour chaque exécuteur : `abortLocal(taskId)` / stop `pollUntilTerminal` / extract / archive
  3. clear `download_*` sessionStorage
  4. `hide()` modales Famille B dont `taskId` était ciblé
  5. merge réponse + `render()`
- CSRF tick ultérieur sur task morte : déjà 403/not_found — acceptable ; ne pas spammer retries 504 après purge.
- Dismiss modal ouverte : après purge, plus de mini-dialogue pour une task absente.

**Estimate** : M

---

### US-05 — i18n + accessibilité

**Critères**

- Clés fr/en (+ de/lt/no alignés) : `purge_all`, `purge_all_confirm_title`, `purge_all_confirm_body`, `purge_all_done` (optionnel).
- Bouton `type="button"`, focusable, `aria` du confirm.
- Pas de formulation « ce n’est pas un problème de connexion ».

**Estimate** : S

---

### US-06 — Contrats de non-régression

**Critères**

- Contract test : route `files_operations_purge`, bouton `data-ops-purge-all`, confirm sans `window.confirm`.
- Contract : `done` non listé dans les ids purgés du service (fixture source ou static assert sur code paths).
- OPS existant (reduce/resume/cancel unitaire) inchangé.
- Règle Cursor OPS : une ligne « Purge all = non-done only ; downloads handed_to_browser = UI only ».

**Estimate** : S

---

## 5. Ordre de livraison

1. US-02 (services)  
2. US-01 (route)  
3. US-03 + US-05 (UI + i18n)  
4. US-04 (client orchestration)  
5. US-06 (contrats + règle)

---

## 6. Definition of Done (épic)

- [ ] Bouton en bas du centre, confirm, i18n
- [ ] `POST /files/operations/purge` cancel+delete non-`done` serveur
- [ ] Polls/modales/downloads locaux nettoyés
- [ ] `done` préservés
- [ ] Download natif : texte honnête, pas de faux abort
- [ ] Contrats verts

---

## 7. Hors épic / suivi

- Si o2switch 504 pendant purge d’**énormément** de jobs : paginer cancel serveur (v1.1). V1 = boucle synchrone owner typique (< dizaines de tâches).
- Purge aussi des sessions `chunk_upload` orphelines sans job encrypt : **option US-02b** — si un `.part` n’a plus de task, le purge peut les garbage-collect via `ChunkedUploadService` (recommandé dans US-02 pour éviter 25 Gio orphelins).
