# EPIC DB-LONGOPS — Connexion MySQL résiliente pour opérations longues

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-DB-LONGOPS |
| Statut | Implémenté (v1) |
| Auteur | Stephane H. |
| Date | 2026-11-10 |
| Projet | StuWebStorage (Symfony 8.1 / PHP 8.4 / Doctrine DBAL) |
| Trigger prod | `prod.log` 2026-11-09 : `upload.uncaught` + `SQLSTATE[HY000]: General error: 2006 MySQL server has gone away` (`Doctrine\DBAL\Exception\ConnectionLost`) sur `files_upload_encrypt_tick` après ~45 min de chiffrement d’un fichier ~24,5 Gio |
| Lié à | `EPIC-ENC` (ticks encrypt), `EPIC-OPS` (extract / archive ticks), instrumentation upload (`UploadDiagnosticLogger`) |
| Hors scope v1 | Messenger / worker CLI async ; changer le format CVF2 ; augmenter seulement `wait_timeout` côté hébergeur comme unique fix |

---

## 0. Pourquoi cet épic existe

Un envoi 25 Gio en prod a **réussi** jusqu’à :

1. `upload.session.created` (chunks 2 Mio, disque/droits OK)
2. `upload.encrypt.created` après ~56 min d’upload
3. ticks de chiffrement pendant ~45 min

Puis un `POST /files/upload/encrypt/{taskId}/tick` a renvoyé une **HTTP 500** JSON (`files.upload.xhr_server_error`) avec :

```text
Doctrine\DBAL\Exception\ConnectionLost
SQLSTATE[HY000]: General error: 2006 MySQL server has gone away
```

Ce n’est **pas** :

- un problème de droits `var/` (déjà couvert par EPIC-PERM)
- un 504 proxy (déjà mitigé par chunks/ticks courts)
- un échec métier encrypt (`upload.encrypt.failed` absent)

C’est une **coupure de connexion MySQL idle / serveur** pendant une opération longue, remontée comme exception non gérée.

### Leçon produit à graver

> Un tick « filesystem only » traverse quand même Symfony (auth, éventuelle session, listeners). **Filesystem tick ≠ zéro infra.** `MySQL gone away` doit être un cas de **première classe**, au même titre que 504 proxy et permissions runtime.

---

## 1. Vision (une phrase)

> Aucun tick Famille B ne doit mourir en 500 opaque parce que MySQL a fermé une connexion idle : on **détecte**, **reconnecte**, **retente une fois**, et on loggue / affiche clairement si ça échoue encore.

---

## 2. Constat code (base de design)

### 2.1 Encrypt ticks aujourd’hui

| Phase du tick | I/O | Doctrine |
|---|---|---|
| Intermédiaire (`complete === false`) | Lit `.part`, écrit cipher, sauve meta JSON sous `var/tasks/` | **Pas** de `flush` |
| Terminal (`complete === true`) | Idem + cleanup session chunk | `persist` + `flush` `SharedFile`, lectures quota / dossier |

Fichier : `UploadEncryptJobService::tick` — EM utilisé surtout en fin de job.

### 2.2 Pourquoi MySQL intervient quand même sur un tick intermédiaire

Chaque requête HTTP `files_upload_encrypt_tick` :

- authentifie l’utilisateur (souvent via session + éventuel hit User/DB selon config)
- peut ouvrir / réutiliser une connexion Doctrine en début de kernel
- si la connexion a été ouverte puis que le travail CPU/FS dure, **ou** si une connexion poolée/stale est réutilisée après `wait_timeout` hébergeur → **2006 / ConnectionLost** au prochain usage SQL

Chez o2switch / MySQL mutualisé, `wait_timeout` est souvent **300–600 s**, bien en dessous d’un chiffrement 25 Gio (~40+ min).

### 2.3 Ce que le log a montré (chrono UTC)

| Heure | Événement |
|---|---|
| 17:00 | `upload.session.created` ~24,5 Gio |
| 17:56 | `upload.encrypt.created` |
| 18:36 | GET `/tick` → Method Not Allowed (**bruit**, ignore) |
| 18:41 | POST tick → `upload.uncaught` + `ConnectionLost` |

Disque OK (~700 Gio libres), `permission_denied: false`.

---

## 3. Principes figés (non négociables)

### P1 — Retry applicatif obligatoire

Augmenter `wait_timeout` MySQL (si possible) est un **complément**, pas le fix. L’app doit survivre à un 2006/2013 / `ConnectionLost`.

### P2 — Lazy, pas ping à chaque tick

Interdit : `SELECT 1` systématique sur **chaque** tick « pour être tranquille » (charge inutile).  
Autorisé : `ensureAlive()` / reconnect **juste avant** un usage Doctrine réel, ou catch + retry autour de cet usage.

### P3 — Un seul retry

`ConnectionLost` récupérable → **1** close + reconnect + rejouer l’opération DB (ou le tick entier si safe).  
Échec du retry → fail explicite loggué + flash dédié, **pas** boucle infinie.

### P4 — Ne pas failer le job encrypt sur un gone-away récupérable

Un tick intermédiaire dont la meta disque est déjà à jour ne doit **pas** passer en `failed` à cause d’une DB morte si on peut reconnecter.  
Tick final : retry ciblé du `flush` / lectures quota-folder ; si échec définitif → `failed` avec clé DB claire + artefacts nettoyés selon règles ENC existantes.

### P5 — Même filet Famille B

Encrypt, extract, archive (tout tick qui peut toucher l’EM) partagent le **même** guardian.

### P6 — Observabilité

Canal log existant `upload` (ou dédié `db`) :

| Event | Sens |
|---|---|
| `db.reconnect` | Connexion fermée puis rouverte avant retry |
| `db.retry_ok` | Opération réussie après retry |
| `db.retry_failed` | Retry épuisé — cause racine conservée |

Contexte : `route`, `task_id`, `owner_user_id`, `exception_class`, `exception_message`.

### P7 — UX honnête

Après retry failed : message i18n dédié (`files.flash.db_connection_lost`), **pas** le libellé générique « erreur serveur » sans clé, et **pas** « serveur injoignable / connexion ».

---

## 4. Périmètre

### Inclus (v1)

- Service `DatabaseConnectionGuardian` (ou équivalent sous `App\Service\Infrastructure`)
- Branchement encrypt tick (priorité prod)
- Branchement extract / archive ticks
- i18n fr/en (+ alignement de/lt/no)
- Logs `db.reconnect` / `db.retry_*`
- Contrats PHPUnit

### Exclu (v1)

- Déplacer le chiffrement dans un worker CLI / Messenger (piste **v2**, §8)
- Changer la taille des ticks / chunks (déjà traité OPS/504)
- Purge OPS, permissions runtime
- Corriger le bruit GET `/tick` Method Not Allowed (hors cause 500)

---

## 5. Design proposé

### 5.1 `DatabaseConnectionGuardian`

```text
ensureAlive(Connection $connection): void
  — si connecté, ping léger OU s’appuyer sur l’exception au premier query
withRetry(callable $operation): mixed
  — try operation
  — catch ConnectionLost|Driver Exception 2006/2013
  — log db.reconnect ; connection->close()
  — retry operation une fois
  — catch → log db.retry_failed ; rethrow (ou wrap RuntimeException métier)
```

Détection : `Doctrine\DBAL\Exception\ConnectionLost` **et** message contenant `gone away` / `2006` / `2013` / `Lost connection`.

### 5.2 Où brancher (encrypt)

1. **Avant** le bloc terminal : lectures `Folder` / quota User + `persist`/`flush` → `withRetry`.
2. Optionnel : wrapper léger en tête de `tick()` seulement si une query auth a déjà échoué (plutôt gérer au niveau kernel/subscriber **ou** laisser le catch du controller).

Controller `uploadEncryptTick` : aujourd’hui catch `RuntimeException` seulement. Étendre pour :

- laisser le guardian absorber `ConnectionLost` récupérable à l’intérieur du service ;
- si `ConnectionLost` ressort encore → `jsonApiError` avec `files.flash.db_connection_lost` + log (ne pas laisser uniquement `JsonApiExceptionSubscriber` → `xhr_server_error`).

### 5.3 Idempotence tick

| Cas | Safe retry ? |
|---|---|
| Tick intermédiaire, meta déjà sauvée, exception DB **après** return path FS | Oui — client peut re-POST le même tick ; serveur relit meta |
| Tick intermédiaire, exception **pendant** encrypt FS | Déjà géré ENC (état cipher / cancel) — hors cet épic sauf si DB involucrée |
| Tick final, `flush` SharedFile échoue après écriture cipher complète | Retry flush ; attention double `SharedFile` → garde unique path/token ou transaction courte |
| Tick final, `flush` OK puis crash avant meta `done` | Déjà un risque ENC ; documenter / story si besoin (hors scope minimal) |

### 5.4 Config / doc hébergeur (US-04)

Documenter dans l’épic ou `docs/` :

- Vérifier `wait_timeout` / `interactive_timeout` MySQL o2switch
- Doctrine prod : pas de silver bullet unique ; le retry app reste la source de vérité
- Ne pas recommander `max_execution_time` comme fix MySQL (déjà 14400) 

---

## 6. Stories

### US-01 — `DatabaseConnectionGuardian`

**En tant que** service d’infra,  
**je veux** un helper unique detect/reconnect/retry,  
**afin que** tous les ticks longs partagent le même comportement.

**Critères**

- Classe finale sous `App\Service\Infrastructure` (nom exact libre si cohérent)
- `withRetry(callable): mixed` — 1 retry max
- Reconnaît `ConnectionLost` + 2006/2013/gone away
- Log `db.reconnect` / `db.retry_ok` / `db.retry_failed`
- Doxygen Stephane H. ; variables/commentaires anglais
- Unit test : exception simulée → close appelé → 2ᵉ appel OK ; double fail → rethrow

**Estimate** : S

---

### US-02 — Encrypt tick résilient

**En tant qu’utilisateur qui chiffre un gros fichier**,  
**je ne veux plus** une 500 `gone away` au bout de 40 min si MySQL a juste coupé l’idle.

**Critères**

- `UploadEncryptJobService::tick` (ou couche immédiatement autour du `flush`/quota/folder) utilise le guardian
- Gone-away récupérable → pas de `fail(..., storage_failed)` ni `upload.uncaught` nominal
- Après retry failed → fail job **ou** erreur JSON avec `files.flash.db_connection_lost` (choix : préférer **ne pas** corrompre un job presque fini — documenter dans l’implémentation ; recommandation : retry flush ; si fail, `failed` + cleanup cipher selon ENC)
- `FilesController::uploadEncryptTick` mappe la clé flash DB
- Log upload conserve `task_id` / `owner_user_id`

**Estimate** : M

---

### US-03 — Extract + archive ticks

**Critères**

- Même guardian sur les chemins tick qui touchent Doctrine
- Contract : présence d’appels / injection du guardian
- Pas de régression cancel OPS

**Estimate** : S–M

---

### US-04 — Doc + options Doctrine / hébergeur

**Critères**

- Section « Exploitation o2switch » dans cet épic ou doc courte : `wait_timeout`, quoi demander au support
- Si une option Doctrine/DBAL claire et supportée par la version du projet existe pour TTL idle, l’ajouter en **option** commentée / documentée — sans casser le test env
- Clarifier : config ≠ remplacement du retry US-01/02

**Estimate** : S

---

### US-05 — i18n + UX

**Critères**

- Clés `files.flash.db_connection_lost` (fr/en + de/lt/no)
- Texte du type : la connexion base a été perdue pendant une opération longue ; réessayez / reprenez l’opération (sans « pas un problème de connexion » creux, sans faire croire à un réseau client)
- Mapping controller + éventuel subscriber si uncaught ConnectionLost sur routes upload

**Estimate** : S

---

### US-06 — Contrats de non-régression

**Critères**

- Contract test : guardian existe ; encrypt tick / flush path référence le guardian
- Contract : `ConnectionLost` n’est plus seulement absorbé en `xhr_server_error` sans clé DB sur la route tick (assert source)
- ENC / OPS contracts existants restent verts

**Estimate** : S

---

## 7. Ordre de livraison

1. US-01 Guardian + unit  
2. US-02 Encrypt tick (valeur prod immédiate)  
3. US-05 i18n  
4. US-03 Extract / archive  
5. US-04 Doc hébergeur  
6. US-06 Contrats  

---

## 8. Hors épic / v2

| Piste | Intérêt | Coût |
|---|---|---|
| Worker CLI / Messenger : chiffrer hors requête HTTP | Élimine 504 proxy **et** réduit la surface « request longue + DB » | Élevé (ops, déploiement, progress polling) |
| Monter `wait_timeout` MySQL chez o2switch | Réduit la fréquence des gone-away | Faible, parfois impossible / non durable seul |
| Session 100 % fichiers + auth sans hit DB sur tick | Moins de chances de toucher MySQL en tick intermédiaire | Moyen ; à auditer security |

v1 = **résilience in-request**. v2 = **sortir le travail long de la request**.

---

## 9. Definition of Done (épic)

- [x] Guardian unique + 1 retry (`DatabaseConnectionGuardian`)
- [x] Encrypt tick : `ensureAlive` + `withRetry` autour du finalize DB ; job reste `running` si retry épuisé (reprise client)
- [x] prod.log : events `db.reconnect` / `db.retry_ok` / `db.retry_failed`
- [x] Extract / archive couverts
- [x] i18n `files.flash.db_connection_lost` (+ subscriber 503)
- [x] Contrats unit + wiring
- [x] Doc exploitation o2switch (section §5.4 / §8 de cet épic)

---

## 10. Recette manuelle (prod-like)

1. Lancer un chiffrement long (ou simuler `ConnectionLost` en test/staging).  
2. Vérifier qu’un tick survit après reconnect (log `db.reconnect` / `db.retry_ok`).  
3. Forcer un double échec → message `db_connection_lost`, job non laissé « running » zombie sans signal.  
4. Confirmer qu’un GET accidentel sur `/tick` reste hors sujet (405) et n’interfère pas.
