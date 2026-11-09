# EPIC DL-100 — Refonte du système de téléchargement (objectif 100 Go)

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-DL100 |
| Statut | Proposé — en attente de validation avant implémentation |
| Auteur | Stephane H. |
| Date | 2026-11-02 |
| Projet | StuWebStorage (Symfony 8.1 / PHP 8.4 / MariaDB / Apache mod_php) |

---

## 1. Contexte et problématique

### 1.1 État des lieux technique (audit Phase 1)

**Stack**

| Couche | Technologie constatée |
|---|---|
| Frontend | Twig + Bootstrap, JavaScript vanilla (IIFE, pas de bundler, pas de Node). Fichiers `public/js/*.js`. |
| Backend | Symfony 8.1, PHP 8.4.27 NTS, Doctrine ORM 3 / MariaDB 11. Pas de Messenger, pas de worker ; les tâches longues utilisent un pattern « job + tick HTTP » piloté par le navigateur (`ZipExtractService`). |
| Stockage | Fichiers chiffrés au repos (AES-256-CBC) dans `var/shared/<ownerId>/<hex>.dat`. Deux formats : **v1** (base64 d'un blob unique, non streamable) et **v2 « CVF2 »** (segments de 4 MiB, IV par segment, en-tête 16 octets) + sidecar d'index `.cvf2idx` (offsets chiffrés par segment). Pas de stockage objet. |
| Serveur web (dev) | Apache 2.4 **mpm_prefork + mod_php**, `Timeout 300`, `mod_deflate` actif (types texte uniquement), `MaxRequestWorkers 150`. `.htaccess` : `memory_limit 1024M`, `max_execution_time 3600`. |
| Serveur web (prod) | Hébergement mutualisé (`/home/xete5775/…`, `storage.stunivers.com`) : `.user.ini` prévu pour PHP-FPM/CGI (`max_execution_time 3600`). Serveur HTTP exact (Apache/LiteSpeed), `request_terminate_timeout`, proxy/buffering : **non vérifiables depuis ce poste**. |
| Machine de dev | 30 Go RAM, 22 cœurs, **47 Go libres** sur `/` (ext4, 885 Go, 95 % utilisé), `/tmp` = **tmpfs 16 Go (RAM)**. |

**Cheminement actuel des octets (fichier unique)**

```
disque (chiffré v2) → PHP fread 4 MiB → openssl_decrypt → php://output → Apache → navigateur
```
Pas de X-Sendfile possible (chiffrement). Le backend est obligatoirement sur le chemin des données : c'est acceptable (CPU AES ≈ 0,5–1 Go/s par cœur) mais impose que la couche HTTP soit irréprochable (Range, validateurs, détection d'abandon).

**Téléchargement authentifié aujourd'hui**

1. Clic sur « Télécharger » → si `byteSize > 200 Mio`, redirection vers `/files/download/{id}/progress` (page dédiée).
2. La page JS (`files-download-progress-page.js`) demande un jeton HMAC (`POST /files/{id}/download/token`, TTL 2 h), fait un `HEAD`, puis télécharge par **tranches Range de 16 MiB via `fetch()`** (`files-download-manager.js`).
3. Les tranches sont écrites dans un « sink » navigateur (`files-download-sinks.js`) : File System Access API (`showSaveFilePicker`) ou OPFS (`createSyncAccessHandle`), avec un checkpoint IndexedDB (`files-download-checkpoint.js`).
4. Le serveur (`EncryptedStreamDeliveryService`) répond en `206` avec `Content-Range`, `Accept-Ranges`, en déchiffrant la plage via l'index de segments.

**Téléchargement groupé (ZIP) aujourd'hui** — `FilesController::downloadSelectionZip`, `FolderZipService`, `PublicFolderZipService`

```php
$plain = $this->fileEncryptionService->decryptFromStorage($path); // tout le fichier en RAM (plafond 50 Mio pour v2)
$zip->addFromString($entryName, $plain);                            // ZipArchive bufferise en RAM jusqu'à close()
$zip->close();                                                      // écriture dans sys_get_temp_dir() = /tmp (tmpfs)
return $this->file($zipPath, $zipName);                             // BinaryFileResponse, synchrone, pas de nettoyage
```

### 1.2 Dysfonctionnements constatés et causes racines identifiées

| # | Symptôme | Cause(s) racine(s) identifiée(s) dans le code / l'infra |
|---|---|---|
| P1 | Téléchargement > 2 Go aléatoire, arrêt vers 4 Go | (a) `ignore_user_abort(true)` + aucune détection d'abandon : un client déconnecté laisse PHP déchiffrer jusqu'au bout → saturation des workers prefork. (b) Aucun validateur fort (`ETag`/`Last-Modified`) ni `If-Range` : le gestionnaire de téléchargement du navigateur **ne peut pas reprendre** une interruption et repart de zéro ou échoue. (c) Sur l'hébergement prod (FPM/LiteSpeed) la limite murale `max_execution_time = 3600 s` / `request_terminate_timeout` coupe la requête : à ≈1,1 Mo/s, 3600 s ≈ **4 Go** — cohérent avec le symptôme. (d) Fichiers **v1** et v2 sans sidecar : lecture séquentielle depuis le début à chaque Range (coût O(n²) sur un fichier tranché en 16 MiB). (e) Jeton de 2 h : toute reprise au-delà de 2 h est refusée (403). (f) `isValidForRequest()` retourne `true` quand le jeton est absent : le jeton n'apporte aucune sécurité et n'est pas utilisable sans session. |
| P2 | ZIP multiple ne fonctionne pas | (a) `decryptFromStorage` lève `file.encryption.v2_use_streaming` au-delà de 50 Mio → le fichier est **silencieusement ignoré** (`catch … continue`). (b) Archive construite intégralement en RAM (`addFromString`) puis sur `/tmp` **tmpfs** (16 Go, en RAM) : limite ≈ `memory_limit` (1 Go) puis ≈ 16 Go. (c) Génération synchrone dans la requête HTTP : `Timeout`, `max_execution_time`, aucun feedback utilisateur, aucun nettoyage du fichier temporaire. (d) Pas de Zip64 : impossible > 4 Gio ou > 65 535 entrées. (e) Pas de Range sur l'archive → aucune reprise. |
| P3 | La reprise ne fonctionne pas | (a) `createSyncAccessHandle()` **n'existe que dans un Web Worker** ; appelé depuis le thread principal il échoue dans tous les navigateurs → le sink OPFS est inutilisable. (b) `FileSystemWritableFileStream` écrit dans un **fichier d'échange** et ne commite qu'au `close()` : un onglet fermé perd 100 % des octets ; le checkpoint IndexedDB indique donc une progression **fictive** (c'est exactement le piège « la progression affichée n'est pas une preuve d'écriture disque »). (c) La reprise FS Access (`createWritable({keepExistingData:true})`) **copie** le fichier partiel à chaque réouverture : inexploitable à l'échelle de dizaines de Go. (d) `showSaveFilePicker` n'existe que sur Chromium (pas Firefox/Safari). (e) Quotas OPFS : Firefox ≈ 10 Gio sans persistance, Chromium ≈ 60 % du disque ; un 100 Go exige 100 Go d'OPFS **puis** une copie de 100 Go vers « Téléchargements » (2× disque, temps doublé). (f) Jeton 2 h + nécessité d'une session : reprise après redémarrage impossible. (g) Verrou inter-onglet en `sessionStorage` (local à l'onglet) : inopérant. |

**Conclusion de l'audit sur le mécanisme de reprise existant : non récupérable.** Il repose sur des API navigateur dont la sémantique (non-durabilité avant `close()`, Worker obligatoire, quotas, compatibilité) est incompatible avec l'objectif. Il doit être remplacé.

### 1.3 Autres constats

- `var/download_prepare/` : résidus d'un mécanisme « prepare » supprimé (commit `080dc19`) ; à nettoyer.
- `DownloadDiagnosticEvent` existe (table + vue admin) mais ne journalise ni l'offset de la plage, ni les octets réellement envoyés, ni l'abandon client, ni la durée.
- Aucune empreinte de contenu (CRC32/SHA-256) n'est stockée : impossible de vérifier l'intégrité côté client ni de construire une archive sans relire les fichiers.
- Le dépôt contient des données utilisateur réelles (`var/shared` ≈ 9,2 Go) : **aucun test ne doit les toucher**.

---

## 2. Vision et objectif

> Un utilisateur doit pouvoir télécharger un fichier unique de 100 Go ou une archive ZIP contenant jusqu'à 100 Go, interrompre à plusieurs Go, revenir plus tard (onglet fermé, navigateur redémarré, machine redémarrée) et **reprendre sans repartir de zéro**, avec une **garantie d'intégrité**.

### 2.1 Principes directeurs

1. **Le serveur est la source de vérité de la reprise** : il expose des ressources HTTP strictement conformes RFC 9110 (`Accept-Ranges`, `206`, `Content-Range`, `ETag` fort, `Last-Modified`, `If-Range`, `416`), stables dans le temps (URL + jeton de longue durée), sans dépendre d'une session.
2. **Le navigateur télécharge nativement** : on s'appuie sur le gestionnaire de téléchargement du navigateur (écriture directe sur disque, pause/reprise, survie à la fermeture de l'onglet, reprise après redémarrage dans Chrome/Edge/Firefox) au lieu de réimplémenter un gestionnaire en JavaScript limité par les quotas et les API. Aucun octet de contenu n'est manipulé par le JS de la page.
3. **Les archives sont virtuelles** : un ZIP (Zip64, méthode STORE) est entièrement **déterministe** si l'on connaît noms, tailles, horodatages et CRC32 des entrées. Il peut donc être servi **à la volée et par plage d'octets**, sans jamais exister sur disque ni en mémoire. Génération = calcul d'un manifeste (instantané) + éventuel calcul des CRC32 manquants (job reprenable).
4. **Zéro chargement intégral en mémoire**, côté fichier comme côté archive : tampons bornés à un segment (4 Mio).
5. **Intégrité vérifiable** : SHA-256 et CRC32 calculés au moment de l'upload (flux), exposés à l'utilisateur, utilisés pour l'ETag et la validation E2E.
6. **Pas de régression** pour les petits fichiers, pas de contournement des ACL existantes.

### 2.2 Objectif de validation

- 100 Go fichier unique, 5 × 20 Go en ZIP, reprise après interruption à ≥ 4 Go, archive reprenable, hash SHA-256 identique à la source.
- Les scénarios E2E sont exécutés **réellement** ; les volumes effectivement testés et les limites de l'environnement sont documentés sans les masquer (voir §9 et §11).

---

## 3. Périmètre fonctionnel

| Inclus | Exclu / hors périmètre |
|---|---|
| Téléchargement d'un fichier (espace authentifié : possédé, partagé « pour moi », vue admin) | Modification du chiffrement au repos (algorithme, clé) |
| Téléchargement d'un fichier via lien public (TOTP e-mail ± mot de passe) | Upload (hors script E2E) |
| Téléchargement groupé : sélection de fichiers et/ou dossiers → archive ZIP | Prévisualisation média (conserve la couche Range existante, bénéficie des validateurs) |
| Téléchargement d'un dossier possédé / partagé / public → archive ZIP | Compression des archives (STORE uniquement, justifié §6.4) |
| Reprise après interruption réseau, fermeture d'onglet, fermeture/redémarrage du navigateur ou de la machine | Gestionnaire de téléchargement « in-page » (abandonné, voir §6.6) |
| Intégrité : empreintes SHA-256/CRC32, ETag fort, invalidation si la source change | |
| Observabilité, diagnostics admin, purge | |
| Scripts de génération de données de test et scénarios E2E | |

---

## 4. Périmètre technique

| Domaine | Éléments concernés |
|---|---|
| Backend PHP | `FilesController` (routes download/zip), `PublicDownloadController`, `EncryptedStreamDeliveryService` (remplacé par une couche de livraison générique), `FileEncryptionService` (empreintes au chiffrement), `V2SegmentIndexService`, `DownloadTokenService` (remplacé par `DownloadGrantService`), nouveaux services `Download\*` (ressource, répondeur Range, archive virtuelle, layout Zip64, préparation), nouvelles entités `DownloadArchive`, colonnes `SharedFile.content_crc32`, `SharedFile.content_sha256`, migrations, commandes CLI. |
| Frontend | `files-space.js` (actions download/sélection), `public-file-landing.js`, nouveau `files-download-launcher.js` et `files-archive-flow.js`, templates Twig (modale d'archive, propriétés), traductions fr/en/de/lt/no. Suppression de `files-download-manager.js`, `files-download-sinks.js`, `files-download-checkpoint.js`, `files-download-progress-page.js`, `download_progress_page.html.twig`. |
| Stockage | Inchangé pour les données ; ajout d'empreintes et garantie d'index pour tous les fichiers v2 ; migration des fichiers v1 vers v2 (commande). Plus aucune écriture dans `sys_get_temp_dir()`. |
| Infrastructure | `.htaccess` (no-gzip / no-transform sur les routes de téléchargement, `output_buffering`), documentation des prérequis d'hébergement (FPM `request_terminate_timeout`, LiteSpeed, proxy buffering, `Timeout`), paramètres `.env`. |
| Protocoles | HTTP/1.1 Range (RFC 9110 §14), validateurs forts, ZIP APPNOTE 6.3.x (Zip64, UTF-8 bit 11, STORE). |

---

## 5. Analyse des risques

| Risque | Impact | Mitigation retenue |
|---|---|---|
| Mémoire serveur (fichier ou ZIP en RAM) | OOM, 500 | Streaming segment par segment (≤ 4 Mio + en-têtes ZIP ≤ quelques Ko) ; interdiction de `decryptFromStorage`/`addFromString` dans les chemins de téléchargement ; test fonctionnel mesurant `memory_get_peak_usage()` lors d'un range sur un fichier de plusieurs Gio. |
| Bande passante / CPU | Saturation workers prefork (150) | Détection d'abandon (`connection_aborted()`) entre segments ; `ignore_user_abort(false)` ; limite configurable de flux simultanés par acteur (P3). |
| Timeouts HTTP (Apache `Timeout`, FPM, LiteSpeed, proxy) | Coupure à N Go | Flux continu (pas d'idle), `set_time_limit(0)`, documentation hébergeur ; **et surtout** reprise garantie par Range : une coupure n'est plus fatale. |
| Concurrence (plusieurs onglets, plusieurs Range sur la même ressource) | Corruption | Ressources en lecture seule et sans état ; chaque Range est indépendant ; pas de fichier temporaire partagé. |
| Espace disque serveur | Archive de 100 Go impossible à matérialiser | Archive virtuelle : **0 octet** sur disque. Préparation = CRC32 uniquement (lecture, pas d'écriture). |
| Stockage temporaire | tmpfs 16 Go, fuites | Plus aucun fichier temporaire de téléchargement ; purge des archives expirées (entrées DB uniquement). |
| Interruptions réseau | Repartir de zéro | Range + ETag fort + `If-Range` ; jeton de longue durée (défaut 7 j, configurable) valable sans session. |
| Intégrité | Fichier corrompu silencieusement | ETag dérivé de l'empreinte ; `410 Gone` si une entrée d'archive change ; SHA-256 exposé ; CRC32 ZIP exact → `unzip -t` valide. |
| Source modifiée pendant le téléchargement | Mélange de versions | Les fichiers sont immuables sauf l'éditeur texte (`SharedFileContentUpdateService`) : il recalcule les empreintes → ETag change → le navigateur reçoit `200`/`412` et redémarre proprement ; archives → `410`. |
| Expiration de session / jeton | Reprise refusée | Jeton signé stateless re-vérifiant les droits à chaque requête ; TTL long ; pas de dépendance au cookie. |
| Sécurité (jeton dans l'URL) | Fuite de lien | Jeton lié à (ressource, acteur, expiration, version d'empreinte) ; droits re-vérifiés à chaque requête (révocation immédiate) ; journalisation hachée ; TTL configurable ; aucun contournement des ACL. |
| Compatibilité navigateur | Reprise non disponible | Comportement natif documenté par navigateur (§6.6) ; lien reprenable copiable utilisable avec curl/aria2/gestionnaire externe. |
| Fichiers v1 (legacy) | Pas de Range | Commande de migration v1 → v2 (re-chiffrement en flux) ; en attendant, téléchargement complet sans Range mais sans RAM. |
| Environnement de test insuffisant (47 Go libres) | 100 Go non démontrable localement | Scénarios paramétrés par taille ; exécution locale à échelle réduite + procédure 100 Go pour un environnement dimensionné (§9.6). |

---

## 6. Architecture cible

### 6.1 Vue d'ensemble

```
                         ┌──────────────────────────────────────────────────────────────┐
  Navigateur             │ Backend Symfony                                              │
  ───────────            │                                                              │
  clic « Télécharger »   │  POST /files/{id}/download/grant ──► DownloadGrantService    │
        │                │        (ACL ▸ jeton HMAC longue durée)                       │
        ▼                │                                                              │
  <a download href=      │  GET|HEAD /dl/file/{id}?g=…  ──► DownloadResourceResolver    │
   /dl/file/{id}?g=…>    │        │  (jeton ▸ acteur ▸ ACL ▸ FileDownloadResource)      │
        │                │        ▼                                                     │
  gestionnaire natif ◄───┼── RangeStreamResponder (ETag, If-Range, 206/416, abort)      │
  (pause/reprise/Range)  │        ▼                                                     │
                         │  EncryptedRangeReader (index segments ▸ seek ▸ decrypt 4 Mio)│
                         │                                                              │
  sélection + « ZIP »    │  POST /files/archives ──► DownloadArchiveService             │
        │                │        (ACL par entrée ▸ manifeste ▸ CRC manquants ?)        │
        ▼                │        │                                                     │
  modale progression ◄───┼── POST /files/archives/{token}/tick (CRC32 manquants, borné) │
        │  ready         │        ▼                                                     │
  <a download href=      │  GET|HEAD /dl/archive/{token}?g=… ──► ArchiveDownloadResource│
   /dl/archive/{t}?g=…>  │        ▼                                                     │
                         │  VirtualZipLayout (Zip64/STORE) ▸ RangeStreamResponder       │
                         └──────────────────────────────────────────────────────────────┘
```

### 6.2 Couche de livraison HTTP (`App\Service\Download`)

- `DownloadResourceInterface` : `getSize()`, `getStrongEtag()`, `getLastModified()`, `getFileName()`, `getMimeType()`, `readRange(int $start, int $length, callable $sink): void` (émet des morceaux ≤ 4 Mio).
- `RangeStreamResponder` : construit la réponse à partir d'une ressource et d'une requête :
  - `HEAD` et `GET` ; `Accept-Ranges: bytes` ; `Content-Length` exact ; `ETag` **fort** ; `Last-Modified` ; `Cache-Control: private, no-transform, no-store` ; `Content-Disposition: attachment; filename=…; filename*=UTF-8''…` ; `X-Content-Type-Options: nosniff`.
  - `Range` simple (`bytes=a-b`, `a-`, `-n`) → `206` + `Content-Range` ; multi-plages → `200` complet (autorisé par la RFC) ; invalide/insatisfaisable → `416` + `Content-Range: bytes */size`.
  - `If-Range` (ETag ou date) : non concordant → `200` complet (comportement attendu par les navigateurs pour redémarrer proprement). `If-None-Match` → `304`. `If-Match` non concordant → `412`.
  - Flux : `set_time_limit(0)`, `ignore_user_abort(false)`, `session_write_close()` avant émission, `ob_*` purgés, `flush()` après chaque morceau, arrêt immédiat si `connection_aborted()`.
  - Journalise : `download_id`, `resource_kind`, `range_start`, `range_end`, `bytes_sent` (compteur réel), `duration_ms`, `client_aborted`, `http_status`, `error_code`.
- `FileDownloadResource` : ressource d'un `SharedFile` v2 (lecture par index de segments → `fseek` O(1)). ETag = `"f-<id>-<sha256 tronqué 32>"` si l'empreinte existe, sinon `"f-<id>-<size>-<mtime>"` (fort car les fichiers sont immuables hors éditeur texte qui met à jour `updatedAt`).
- `EncryptedRangeReader` : extrait des primitives Range de `FileEncryptionService` ; garantit l'index (création paresseuse **uniquement** si le fichier est < seuil configurable, sinon erreur explicite et commande de backfill), fichiers v1 → flux complet sans Range (`Accept-Ranges: none`).

### 6.3 Jetons de téléchargement (`DownloadGrantService`)

- Payload signé HMAC-SHA256 (`kernel.secret`) : `kind` (file|archive|public-file|public-archive), `resourceId`, `actorType` (user|public-challenge), `actorId`, `issuedAt`, `expiresAt`, `fingerprint` (ETag au moment de l'émission). Encodage base64url, paramètre `g`.
- TTL par défaut **7 jours** (`APP_DOWNLOAD_GRANT_TTL_SECONDS`), minimum 1 h.
- Résolution **sans session** : le jeton identifie l'acteur ; les droits sont **re-vérifiés à chaque requête** (`canUserDownloadSharedFile`, grants amis, partage public actif, challenge TOTP vérifié). Révoquer un partage invalide donc immédiatement les liens.
- Le jeton n'est **jamais** optionnel sur les routes `/dl/*` (correction du `return true` actuel). Les anciennes routes `files_download` (session) restent pour les petits usages (prévisualisation, compat) mais délèguent à la même couche.
- Public : remplace le ticket PSR-6 one-shot (`pub_dl_tkt.*`) par un jeton signé réutilisable (le cache n'est plus requis pour la reprise). Le challenge doit rester `verified` ; l'expiration du challenge n'invalide pas le jeton (le jeton a son propre TTL), mais la désactivation du partage oui.

### 6.4 Archives virtuelles Zip64 (`DownloadArchive`, `VirtualZipLayout`)

- **Entité `DownloadArchive`** : `token` (32 hex), `kind` (selection|folder|shared-folder|public-folder), `actorType/actorId`, `ownerUserId` (sujet), `fileName`, `status` (preparing|ready|failed|expired), `entriesJson` (liste ordonnée `{fileId, entryName, size, mtime, crc32|null}`), `totalBytes`, `layoutVersion`, `etag`, `preparedBytes`, `createdAt`, `readyAt`, `expiresAt`, `lastAccessAt`, `errorCode`. Pas de blob.
- **Création** (`POST /files/archives`, CSRF) : résout la sélection exactement comme aujourd'hui (fichiers, dossiers possédés + sous-arbre, dossier partagé, contexte admin), applique les ACL **par entrée**, nomme les entrées (`ZipEntryNameSanitizer` + suffixes uniques), calcule le layout. Si toutes les entrées ont un `crc32` → `ready` immédiatement. Sinon `preparing`.
- **Préparation** (`POST /files/archives/{token}/tick`, pattern existant des jobs d'extraction) : calcule les CRC32 (et SHA-256) manquants par lecture déchiffrée en flux, **bornée en temps par tick** (défaut 20 s) et **reprenable au fichier près** (progression persistée dans `SharedFile.content_crc32` : un fichier calculé l'est pour toujours, pour toutes les archives). Concurrence : une préparation par archive (verrou DB `SELECT … FOR UPDATE` / colonne `preparing_until`). Les empreintes des **nouveaux uploads** sont calculées pendant le chiffrement (`hash_init('crc32b')`, `hash_init('sha256')`), donc la préparation ne concerne que l'existant ; une commande `app:storage:compute-fingerprints` permet le backfill global en CLI/cron.
- **Layout Zip64 STORE** (`VirtualZipLayout`) : pour chaque entrée, local header (30 o + nom UTF-8 + extra Zip64 fixe 20 o), données brutes, puis central directory (46 o + nom + extra Zip64 28 o), `Zip64 EOCD record` (56 o), `Zip64 EOCD locator` (20 o), `EOCD` (22 o). Les champs 32 bits sont forcés à `0xFFFFFFFF` avec extra Zip64 **systématique** : layout uniforme, calcul d'offset O(log n) par bisection sur les offsets cumulés. Horodatage DOS depuis `SharedFile.updatedAt`. Bit 11 (UTF-8). Pas de data descriptor (CRC connu à l'avance). Méthode STORE : aucune compression (données déjà hétérogènes/chiffrées à la source ; la compression rendrait la taille et les offsets non déterministes et interdirait le Range).
- **Lecture par plage** : `readRange(start, len)` → itère les « régions » (header / data / central dir / fin) chevauchant la plage ; les régions d'en-têtes sont générées en mémoire (≤ Ko), les régions data délèguent à `EncryptedRangeReader` du fichier correspondant. Mémoire bornée quel que soit le nombre d'entrées.
- **Validité à la livraison** : à chaque requête `/dl/archive/{token}` : statut `ready`, non expirée, acteur du jeton = acteur de l'archive, **chaque entrée re-vérifiée** (existe, taille/crc/mtime inchangés, ACL OK). Toute divergence → `410 Gone` + événement diagnostic `archive_invalidated` ; l'UI propose de recréer l'archive.
- **Expiration/purge** : `expiresAt = createdAt + APP_DOWNLOAD_ARCHIVE_TTL_SECONDS` (défaut 7 j), prolongée à chaque accès (glissant, plafonné 30 j). Commande `app:download-archives:purge`. Limites configurables : `APP_DOWNLOAD_ARCHIVE_MAX_TOTAL_BYTES` (défaut 0 = illimité ; 100 Gio recommandé en prod), `APP_DOWNLOAD_ARCHIVE_MAX_ENTRIES` (défaut 10 000).
- **Dossiers publics** : `PublicFolderZipService` (limite 500 Mo, RAM) est remplacé par une archive virtuelle de type `public-folder` ; la limite devient configurable et non liée à la RAM.

### 6.5 Observabilité

- Table `download_diagnostic_event` enrichie : `resource_kind`, `archive_id`, `range_start`, `range_end`, `client_aborted`, `grant_issued_at`, `is_resume` (range_start > 0). Événements : `grant_issued`, `stream_start`, `stream_range`, `stream_end`, `stream_abort`, `range_invalid`, `etag_mismatch`, `archive_created`, `archive_prepare_tick`, `archive_ready`, `archive_failed`, `archive_invalidated`, `archive_purged`.
- Canal Monolog `download` (JSON) avec `download_id` et `request_id`, sans identité en clair (hachage HMAC existant conservé).
- Vue admin existante étendue : filtre par `download_id`, timeline d'un téléchargement (séquence des plages → preuve de reprise), durée de préparation des archives.

### 6.6 Côté navigateur : choix du téléchargement natif

| Critère | Gestionnaire in-page (OPFS / FS Access) — **actuel, abandonné** | Téléchargement natif + serveur RFC — **retenu** |
|---|---|---|
| Durabilité des octets | FS Access : rien n'est sur disque avant `close()`. OPFS : OK mais Worker obligatoire. | Le navigateur écrit directement le fichier partiel (`.crdownload` / `.part`). |
| Espace disque client | 2× (OPFS + copie finale) | 1× |
| Quotas | Firefox ≈ 10 Gio, Chromium ≈ 60 % disque | Aucun |
| Compatibilité | FS Access : Chromium seul | Tous |
| Survie fermeture onglet | Non (JS arrêté) | Oui (gestionnaire du navigateur) |
| Reprise après redémarrage | Impossible (données perdues) | Chrome/Edge/Firefox : « Reprendre » si le serveur renvoie 206 + validateur concordant et si l'URL est encore autorisée (d'où le jeton 7 j) |
| Vérification SHA-256 | Possible en JS | Hors navigateur (`sha256sum`) ; valeur affichée dans les propriétés |
| Complexité / risque | Élevée | Faible ; toute la fiabilité est testable côté serveur |

Le navigateur reste responsable de son propre comportement de reprise (il varie : Chrome reprend automatiquement après une coupure réseau courte et propose « Reprendre » sinon ; Firefox « Réessayer/Reprendre » ; Safari reprise limitée). Pour couvrir les cas où l'utilisateur veut une reprise garantie hors navigateur, l'UI expose un **lien reprenable** (copier) valable `TTL` jours, exploitable avec `curl -C -`, `wget -c`, aria2, etc. Les scénarios E2E utilisent `curl -C -` comme client de référence déterministe **et** un navigateur réel (Firefox installé ; Chromium si Playwright est installable) pour le parcours UI.

### 6.7 Parcours utilisateur cibles

1. **Fichier** : clic « Télécharger » → `POST grant` → ancre `download` déclenchée → le téléchargement apparaît dans le gestionnaire du navigateur. Toast : « Si le téléchargement s'interrompt, utilisez « Reprendre » dans votre navigateur ; lien valable X jours · Copier le lien ». Aucune page de progression bloquante.
2. **Sélection/ZIP** : cases à cocher → « Télécharger (ZIP) » → `POST /files/archives` → modale : récapitulatif (N fichiers, taille totale, nom de l'archive), barre de préparation si nécessaire (CRC manquants, reprenable au rechargement de la page : l'archive est côté serveur), puis bouton « Télécharger l'archive » (natif) + « Copier le lien reprenable ». Une section « Archives récentes » (liste côté serveur, 7 j) permet de retrouver/reprendre/retélécharger une archive sans la recréer.
3. **Public** : après TOTP (± mot de passe), même lanceur natif ; petits fichiers (≤ 3 Mio) conservent l'inline base64 existant.

---

## 7. User Stories

Priorités : **P0** indispensable à l'objectif 100 Go · **P1** nécessaire à la robustesse/complétude · **P2** confort/exploitation. Estimation en points relatifs (1–8). Les dépendances sont indiquées.

### Lot A — Fondations serveur

#### US-01 (P0, 3 pts) Empreintes de contenu à l'upload et en backfill
*En tant que* système, *je veux* connaître le CRC32 et le SHA-256 du contenu en clair de chaque fichier *afin de* servir des archives déterministes, produire des ETag forts et permettre la vérification d'intégrité.
- Colonnes `shared_file.content_crc32` (unsigned int, nullable), `content_sha256` (char 64, nullable) + migration.
- `encryptPlainFileToV2Storage` calcule les deux empreintes en flux pendant le chiffrement (aucune relecture) et les retourne ; `ChunkedUploadService`, upload simple, extraction ZIP et éditeur texte les persistent.
- Commande `app:storage:compute-fingerprints [--owner=] [--limit=] [--max-seconds=] [--dry-run]` : backfill reprenable (ne traite que les lignes à `NULL`), lecture en flux.
- **Critères d'acceptation** : (1) après upload d'un fichier de test, `content_sha256` = `sha256sum` local et `content_crc32` = `crc32b` local ; (2) `memory_get_peak_usage()` < 64 Mio pendant le calcul d'un fichier de 2 Gio ; (3) la commande traite 1 000 fichiers manquants sans erreur et est idempotente ; (4) tests unitaires sur les helpers d'empreinte.

#### US-02 (P0, 3 pts) Index de segments garanti et migration des fichiers v1
- Tout fichier v2 possède un sidecar `.cvf2idx` valide : création à l'upload (existant), vérification/création paresseuse si taille < `APP_DOWNLOAD_LAZY_INDEX_MAX_BYTES` (défaut 1 Gio), sinon erreur explicite `download.index_missing` + log ; commande existante `app:storage:build-segment-index` conservée.
- Commande `app:storage:migrate-v1 [--owner=] [--dry-run]` : re-chiffre les fichiers v1 en v2 (flux, fichier temporaire **dans `var/`**, rename atomique, empreintes calculées, mise à jour `storage_path` si nécessaire).
- **AC** : (1) un Range sur un fichier de 4 Gio sans index ≥ seuil renvoie `503` + code d'erreur et l'événement diagnostic ; (2) après `build-segment-index`, le même Range répond en < 100 ms (hors transfert) ; (3) après migration v1, le fichier est v2, son SHA-256 est inchangé, l'ancien fichier est supprimé ; (4) test unitaire `V2SegmentIndex::resolveCipherOffset` aux bornes (0, dernier segment, taille non multiple).

#### US-03 (P0, 8 pts) Couche de livraison HTTP conforme Range/validateurs
- `DownloadResourceInterface`, `RangeStreamResponder`, `FileDownloadResource`, `EncryptedRangeReader` (§6.2). Routes `GET|HEAD /dl/file/{id}` (jeton obligatoire) ; `files_download` et `download_public_file` délèguent à la même couche (compat).
- **AC** : (1) `HEAD` renvoie `200`, `Content-Length` exact, `Accept-Ranges: bytes`, `ETag` fort (sans `W/`), `Last-Modified`, pas de `Content-Encoding` ; (2) `Range: bytes=0-0` → `206`, `Content-Range: bytes 0-0/N`, corps 1 octet ; `bytes=N-1-` → dernier octet ; `bytes=-100` → 100 derniers ; `bytes=N-` → `416` + `Content-Range: bytes */N` ; `bytes=5-2`, `bytes=a-b`, `bytes=0-1,3-4` → `416` ou `200` conforme RFC (documenté/testé) ; (3) `If-Range: <etag>` concordant → `206`, non concordant → `200` complet ; `If-None-Match` concordant → `304` ; (4) `Content-Disposition` avec `filename*=UTF-8''` pour un nom accentué ; (5) un client qui coupe à 10 % d'un fichier de 1 Gio fait terminer le processus PHP en < 2 s (test fonctionnel avec `connection_aborted` simulé + test E2E `curl --max-time`) ; (6) pic mémoire < 32 Mio sur un Range de 512 Mio ; (7) octets reçus par `curl -r a-b` = `dd`/`cmp` sur la source pour 20 plages aléatoires d'un fichier de 3 Gio ; (8) `curl -C - -O` après coupure reprend à l'offset exact (`range_start` journalisé = taille du fichier partiel) et le SHA-256 final est identique ; (9) aucune régression : téléchargement complet d'un fichier de 1 Ko → `200`.

#### US-04 (P0, 5 pts) Jetons de téléchargement longue durée, sans session
- `DownloadGrantService` (§6.3), `POST /files/{id}/download/grant` (CSRF, ACL), `POST /download/public/grant` (challenge vérifié), résolution d'acteur sans session sur `/dl/*`, TTL `APP_DOWNLOAD_GRANT_TTL_SECONDS` (défaut 604800). Suppression de `DownloadTokenService` et du ticket PSR-6.
- **AC** : (1) `/dl/file/{id}` sans jeton → `403` ; jeton altéré → `403` ; expiré → `403` + code `grant_expired` ; (2) jeton valide **sans cookie de session** → `206`/`200` ; (3) retrait du partage ami → `403` immédiat avec le même jeton ; désactivation d'un partage public → `404` ; (4) le jeton d'un utilisateur A ne donne pas accès via le compte B (acteur lié) ; (5) les événements `grant_issued` sont journalisés sans identité en clair ; (6) tests fonctionnels couvrant owner, admin godview, partage ami, public ± mot de passe.

### Lot B — Archives virtuelles

#### US-05 (P0, 5 pts) Modèle d'archive et création depuis une sélection
- Entité/migration `download_archive` (§6.4), `DownloadArchiveService::createFromSelection(actor, ids[], folderIds[], context)` reprenant exactement la résolution actuelle (`downloadSelectionZip`, `downloadFolderZip`, `sharedFolderDownloadZip`, godview) et les ACL par entrée ; `POST /files/archives` (CSRF) → JSON `{token, status, totalBytes, entryCount, fileName, preparedBytes}` ; `GET /files/archives/{token}` (état) ; `GET /files/archives` (archives récentes de l'acteur) ; `DELETE /files/archives/{token}`.
- **AC** : (1) une sélection de 3 fichiers + 1 dossier (avec sous-dossiers) produit un manifeste avec les mêmes chemins relatifs que l'implémentation actuelle (test de parité sur un jeu de données) ; (2) un fichier non autorisé dans la sélection est **exclu** et compté dans `skippedCount` ; aucune entrée → `400` ; (3) limites `MAX_TOTAL_BYTES`/`MAX_ENTRIES` → `413` avec message ; (4) l'archive est visible uniquement par son acteur (`404` pour un autre) ; (5) noms d'entrées uniques et sûrs (tests existants `ZipEntryNameSanitizer` réutilisés).

#### US-06 (P0, 8 pts) Moteur ZIP virtuel Zip64/STORE à accès aléatoire
- `VirtualZipLayout` (calcul des offsets, en-têtes, Zip64 systématique, UTF-8) et `ArchiveDownloadResource::readRange()` (§6.4).
- **AC** : (1) archive de 3 petits fichiers générée intégralement → `unzip -t`, `7z t`, `ZipArchive::open` OK, contenus identiques ; (2) la concaténation de 1 000 Range aléatoires (tailles 1 o → 20 Mio, chevauchements interdits, couverture complète) produit un fichier identique octet à octet à la génération intégrale ; (3) noms UTF-8 (accents, CJK, espaces) extraits correctement par `unzip` et `7z` ; (4) 10 000 entrées : création du layout < 500 ms, `readRange` sur la fin du central directory < 50 ms ; (5) entrée > 4 Gio **et** archive > 4 Gio extraites par `unzip` (≥ 6.0) et `7z` avec CRC OK (test E2E §9) ; (6) `memory_get_peak_usage()` < 32 Mio lors de la lecture d'une plage de 1 Gio ; (7) horodatages DOS et attributs lisibles (`zipinfo`).

#### US-07 (P1, 5 pts) Préparation reprenable des empreintes manquantes
- `POST /files/archives/{token}/tick` (budget `APP_DOWNLOAD_ARCHIVE_TICK_SECONDS`, défaut 20 s), progression `{preparedBytes, totalBytes, currentEntry}`, verrou anti-concurrence, passage `ready`, erreurs → `failed` + code ; les CRC calculés sont persistés sur `shared_file` (bénéfice global). Option CLI `app:download-archives:prepare <token>` et cron `app:storage:compute-fingerprints`.
- **AC** : (1) archive de 5 fichiers sans empreintes → `preparing`, puis `ready` après N ticks, chaque tick < budget + 2 s ; (2) interruption (page rechargée) puis reprise : aucun fichier recalculé (compteur de lectures) ; (3) deux ticks concurrents → un seul travaille (`409` ou no-op) ; (4) fichier illisible → `failed` + `errorCode` + événement diagnostic ; (5) nouvel upload ⇒ `ready` immédiat.

#### US-08 (P0, 5 pts) Livraison et cycle de vie des archives
- `GET|HEAD /dl/archive/{token}` via `RangeStreamResponder` (jeton d'archive), validation par entrée à chaque requête (`410`), expiration glissante, `app:download-archives:purge`, `archive_*` diagnostics.
- **AC** : (1) `HEAD` → `Content-Length` = `totalBytes` calculé, `ETag` fort stable entre deux requêtes ; (2) `curl -C -` après coupure à 60 % reprend et `unzip -t` OK ; (3) suppression d'un fichier membre → `410` ; modification via éditeur texte → `410` ; retrait d'un droit → `410`/`403` ; (4) archive expirée → `410` + purge supprime la ligne ; (5) archives de deux acteurs distincts ne sont pas interchangeables ; (6) 4 téléchargements simultanés de la même archive renvoient des octets identiques (test de concurrence).

### Lot C — Expérience utilisateur

#### US-09 (P0, 3 pts) Lanceur de téléchargement natif pour les fichiers
- `files-download-launcher.js` : obtient le grant, déclenche l'ancre `download`, affiche le toast d'aide + « Copier le lien reprenable » ; suppression de la page de progression, des sinks/checkpoint/manager et des tests contractuels associés ; `data-download-manager-threshold` supprimé ; parité espace possédé / partagé / admin.
- **AC** : (1) clic → une seule requête `POST grant` puis un téléchargement natif (vérifié via navigateur réel : fichier dans « Téléchargements », taille exacte) ; (2) aucun `fetch` de contenu dans le JS de la page (revue + test contractuel « ne contient pas `arrayBuffer()`/`showSaveFilePicker`/`getDirectory` ») ; (3) petits fichiers : comportement identique à aujourd'hui (téléchargement immédiat) ; (4) lien copié fonctionnel avec `curl -C -` ; (5) textes traduits dans les 5 locales.

#### US-10 (P0, 5 pts) Flux UI d'archive (création, préparation, téléchargement, archives récentes)
- `files-archive-flow.js` + modale Twig ; bouton « Télécharger la sélection » appelle `POST /files/archives` ; progression de préparation (polling `tick`), reprise après rechargement (état serveur), bouton de téléchargement natif, lien reprenable, liste « Archives récentes » (statut, taille, expiration, actions télécharger/supprimer) ; dossiers possédés/partagés passent par le même flux (suppression des routes synchrones `download-zip`).
- **AC** : (1) sélection de N fichiers via cases à cocher → archive créée et téléchargée nativement (navigateur réel) ; (2) rechargement de la page pendant la préparation → la modale/liste retrouve l'archive et la préparation continue ; (3) archive `410` → message + bouton « Recréer » ; (4) accessibilité : focus, `aria-live` sur la progression ; (5) tests contractuels Twig/JS + tests fonctionnels des endpoints.

#### US-11 (P1, 3 pts) Téléchargement public aligné (fichiers et dossiers)
- `public-file-landing.js` et landing dossier : lanceur natif + grant public ; dossiers publics via archive `public-folder` (préparation si nécessaire, affichage progression) ; suppression de `PublicFolderZipService` et de la limite 500 Mo codée en dur (limite configurable commune).
- **AC** : (1) parcours TOTP → téléchargement natif d'un fichier de 3 Gio avec reprise `curl -C -` ; (2) dossier public de 6 Gio téléchargé et validé `unzip -t` ; (3) partage désactivé pendant le téléchargement → la reprise échoue en `404` et l'événement est journalisé ; (4) ≤ 3 Mio : inline base64 inchangé.

#### US-12 (P2, 2 pts) Intégrité visible par l'utilisateur
- Propriétés d'un fichier : SHA-256 (copiable) et indication « vérifiez avec `sha256sum` / `certutil` » ; archive : liste des empreintes des entrées (export `.sha256` téléchargeable).
- **AC** : (1) SHA-256 affiché = `sha256sum` du fichier téléchargé ; (2) export `.sha256` validé par `sha256sum -c` après extraction.

### Lot D — Observabilité, configuration, exploitation

#### US-13 (P1, 3 pts) Diagnostics et logs structurés
- Extension de `DownloadDiagnosticEvent` (§6.5), canal Monolog `download`, vue admin : timeline par `download_id`, filtres (`resource_kind`, `is_resume`, `client_aborted`), durées de préparation ; purge existante conservée.
- **AC** : (1) un téléchargement interrompu puis repris produit une séquence `stream_start → stream_abort(bytes_sent=X) → stream_range(range_start=X) → stream_end` consultable dans l'admin ; (2) aucune donnée sensible en clair (e-mail, IP, UA hachés) — test ; (3) un `416` produit `range_invalid` avec la valeur de l'en-tête tronquée à 128 caractères.

#### US-14 (P1, 2 pts) Configuration, infrastructure et nettoyage
- Paramètres `.env` documentés (`APP_DOWNLOAD_GRANT_TTL_SECONDS`, `APP_DOWNLOAD_ARCHIVE_TTL_SECONDS`, `APP_DOWNLOAD_ARCHIVE_MAX_TOTAL_BYTES`, `APP_DOWNLOAD_ARCHIVE_MAX_ENTRIES`, `APP_DOWNLOAD_ARCHIVE_TICK_SECONDS`, `APP_DOWNLOAD_LAZY_INDEX_MAX_BYTES`, `APP_DOWNLOAD_MAX_CONCURRENT_STREAMS_PER_ACTOR` défaut 0) ; suppression de `APP_DOWNLOAD_DIRECT_MAX_BYTES`/`app.download_manager_ui_threshold_bytes` ; `.htaccess` : `SetEnvIf Request_URI ^/dl/ no-gzip=1 dont-vary`, `Header set Cache-Control`… ; README : section « Téléchargements volumineux » + **prérequis hébergeur** (`request_terminate_timeout=0` ou ≥ durée, LiteSpeed `noabort`/`LSAPI_MAX_PROCESS_TIME`, désactivation du buffering proxy, `Timeout` Apache, `output_buffering`) ; suppression de `var/download_prepare`, de `public/.user.ini` obsolète si applicable ; cron suggérés.
- **AC** : (1) `php bin/console lint:container` et `debug:config` OK ; (2) `curl -sI` sur `/dl/*` ne contient jamais `Content-Encoding` ni `Vary: Accept-Encoding` ; (3) README à jour (revue).

#### US-15 (P2, 3 pts) Limitation des flux simultanés par acteur (optionnel)
- Compteur en cache (`cache.app`) avec bail de 60 s renouvelé par segment ; au-delà de `MAX_CONCURRENT_STREAMS_PER_ACTOR` → `429` + `Retry-After`.
- **AC** : (1) 5 flux simultanés avec limite 4 → le 5ᵉ reçoit `429` ; (2) la coupure brutale d'un flux libère le jeton en < 90 s ; (3) désactivé par défaut (0).

### Lot E — Validation E2E et résilience

#### US-16 (P0, 3 pts) Outillage de données de test
- `tests/e2e/bin/generate-test-files.sh` : tailles/nombre paramétrables, modes `sparse` (`truncate`) et `real` (`/dev/urandom` ou motif pseudo-aléatoire rapide via `openssl enc`), calcul SHA-256 parallèle (`pv`/`tee`), manifeste JSON (`name,size,sha256,mode`), répertoire de travail isolé `E2E_WORKDIR`, préfixe de nommage `e2e-dl100-` **obligatoire**.
- `tests/e2e/bin/upload.sh|py` : login (TOTP e-mail de test → nécessite `MAILER_DSN` fichier/null et lecture du code ; sinon compte de test dédié `E2E_USER`), CSRF, upload par chunks via `/files/upload/session|chunk|finalize`, retourne `fileId` ; dossier de test dédié `e2e-dl100-<run-id>/`.
- `tests/e2e/bin/cleanup.sh` : supprime **uniquement** les fichiers/dossiers/archives portant le préfixe `e2e-dl100-<run-id>` (par API `files_delete_bulk` + vérification DB), les fichiers locaux du `E2E_WORKDIR`, et vérifie l'espace libéré ; invoqué via `trap EXIT` dans tous les scénarios.
- **AC** : (1) `generate-test-files.sh --size 100G --mode sparse` termine en < 5 s et occupe < 1 Mio ; `--mode real --size 2G` écrit 2 Gio réels et produit le SHA-256 ; (2) upload d'un fichier de 2 Gio → `SharedFile` créé, `content_sha256` identique ; (3) `cleanup.sh` sur un run factice ne touche aucun fichier hors préfixe (test avec un fichier témoin non préfixé) ; (4) documentation `tests/e2e/README.md` (prérequis, variables, exécution, nettoyage).

#### US-17 (P0, 8 pts) Scénarios E2E 1 à 4
- `tests/e2e/scenarios/01-single-file.sh SIZE`, `02-zip-multi.sh N SIZE`, `03-resume-file.sh SIZE CUT_AT`, `04-resume-zip.sh N SIZE CUT_AT` : exécutent génération → upload → téléchargement (client de référence `curl` + parcours **navigateur réel** pour 01/02 via Playwright-Firefox/Chromium si installable, sinon via le navigateur intégré et documenté comme tel) → interruption (`kill -9` de curl / coupure réseau simulée par `timeout`/`--limit-rate` ou `iptables` si disponible) → reprise (`curl -C -`, navigateur « Reprendre ») → vérification taille, `sha256sum`, `unzip -t`, extraction et hash par entrée → nettoyage garanti → rapport Markdown (`tests/e2e/reports/<run-id>.md` : volumes, durées, débits, offsets de reprise, diagnostics serveur).
- **AC** : (1) les 4 scénarios passent localement à l'échelle maximale permise par le disque (cible locale : 01 = 10 Gio, 02 = 5 × 2 Gio, 03 = 10 Gio coupé à 4 Gio, 04 = ZIP 10 Gio coupé à 4 Gio ; au moins une entrée > 4 Gio pour valider Zip64) ; (2) pour chaque reprise, `range_start` journalisé = taille du fichier partiel ± 0 et les octets ≥ offset ne sont pas retéléchargés (compteur `bytes_sent` total = taille − offset) ; (3) les SHA-256 sont identiques ; (4) l'espace disque après nettoyage = espace avant ± 1 % ; (5) le rapport est produit même en cas d'échec ; (6) la même suite avec `SIZE=100G` est exécutable sans modification sur un environnement disposant de ≥ 250 Go libres (procédure §9.6).

#### US-18 (P1, 5 pts) Tests de résilience et de charge
- Suite `tests/e2e/resilience/*.sh` + tests fonctionnels PHPUnit : coupures répétées (10 coupures aléatoires sur 5 Gio), débit instable (`--limit-rate` variable), `timeout` pendant la réponse, Range avec offsets variés/invalides/multi-plages, source modifiée (éditeur texte) pendant un téléchargement → `410`/`200`, 8 téléchargements simultanés (fichiers + archives), 3 préparations d'archives simultanées, disque plein simulé pour la préparation (répertoire `var/` en lecture seule → erreur propre, aucune corruption), expiration de jeton (TTL forcé à 60 s) → `403` puis nouveau grant reprend à l'offset, téléchargement proche de la limite configurée (`MAX_TOTAL_BYTES` − 1 o accepté, +1 o → `413`), actualisation de page pendant la préparation.
- **AC** : chaque cas a un résultat attendu explicite et vérifié automatiquement ; aucune fuite de fichier temporaire ni de processus PHP orphelin (vérification `ps`) ; rapport joint.

#### US-19 (P0, 2 pts) Bilan et documentation
- Rapport final `docs/epics/EPIC-DL100-bilan.md` (problèmes, choix, modifications, stories, tests exécutés et résultats, volumes et durées réels, reprises testées, limitations, contraintes infra) ; README et `.env.exemple` à jour ; `docs/runbook-telechargements.md` (diagnostic d'un échec via `download_id`).

### Ordre d'implémentation et dépendances

```
US-01 ─┬─► US-03 ─► US-04 ─► US-09 ─► US-17 (scénarios 01, 03)
US-02 ─┘      │
              └─► US-05 ─► US-06 ─► US-08 ─► US-10 ─► US-17 (scénarios 02, 04)
                     └─► US-07 ──────┘
US-13, US-14 en parallèle dès US-03 · US-11 après US-08/US-09 · US-12, US-15 après US-10 · US-16 dès le début · US-18 après US-10 · US-19 en clôture
```

---

## 8. Critères d'acceptation transverses (Epic)

1. Aucune route de téléchargement ne lit un fichier entier en mémoire (`decryptFromStorage`, `addFromString`, `file_get_contents` sur le stockage) — vérifié par test contractuel statique sur `src/Controller` et `src/Service/Download`.
2. Pic mémoire PHP < 64 Mio pour toute réponse de téléchargement, quelle que soit la taille (mesuré en test fonctionnel sur ≥ 2 Gio).
3. Toute ressource `/dl/*` est reprenable avec `curl -C -` à n'importe quel offset et produit le SHA-256 attendu.
4. Aucune écriture dans `sys_get_temp_dir()` par le sous-système de téléchargement.
5. Les ACL existantes sont appliquées à chaque requête d'octets (tests négatifs owner/ami/admin/public).
6. Les petits fichiers (< 1 Mio) se téléchargent en une requête `200` comme aujourd'hui.
7. Les scénarios E2E 1–4 sont exécutés et documentés avec les volumes réellement atteints.

---

## 9. Stratégie de tests

### 9.1 Tests unitaires (PHPUnit, `tests/Unit/Service/Download`)
`HttpByteRange` (étendu : suffixe, multi-plages, bornes 64 bits), `DownloadGrantService` (signature, expiration, liaison acteur), `VirtualZipLayout` (offsets, Zip64, UTF-8, DOS time, bisection), `ArchiveDownloadResource::readRange` (couverture par plages aléatoires vs génération complète), empreintes (`crc32b`/`sha256` en flux), `RangeStreamResponder` (matrice `Range` × `If-Range` × `If-None-Match` → statut/en-têtes).

### 9.2 Tests d'intégration / fonctionnels (WebTestCase)
Routes `/dl/file`, `/dl/archive`, `/files/archives*`, `/download/public/*` : statuts, en-têtes, corps (comparaison `cmp`), ACL, `410`, `416`, `304`, pic mémoire, abandon client. Jeux de données générés dans `var/test/` (pas dans `var/shared` des utilisateurs réels), nettoyés en `tearDown`.

### 9.3 Tests contractuels (existants dans le projet)
Présence/absence de chaînes dans JS/Twig/PHP (ex. interdiction de `showSaveFilePicker`, présence de `data-files-archive-create-url`), parité des templates owned/shared/admin.

### 9.4 Tests E2E (réels, §7 US-16/17)
Client de référence `curl` (déterministe, offsets contrôlés) + navigateur réel pour le parcours UI. Vérifications : taille exacte, SHA-256, `unzip -t`/`7z t`, extraction, hash par entrée, logs serveur (`range_start`, `bytes_sent`), nettoyage garanti (`trap`).

### 9.5 Tests de résilience et de charge (US-18)
Décrits au §7. Outils : `curl --limit-rate/--max-time`, `kill -9`, `timeout`, `xargs -P` pour la concurrence, `chmod`/`mount -o ro` simulé pour le disque plein (ou répertoire sans droits), variables d'environnement de test pour TTL courts.

### 9.6 Capacités de l'environnement et procédure 100 Go

**Environnement local (constaté)** : 47 Go libres, `/tmp` tmpfs 16 Go, pas de Node/Chromium/Playwright (Firefox présent), hôte Apache prefork + mod_php.

Un test de **100 Go réels** nécessite côté serveur : fichier source (100 Go réels, ou sparse ≈ 0) + fichier `.part` d'assemblage (100 Go) + stockage chiffré (100 Go) au pic de l'upload, puis fichier téléchargé (100 Go) ⇒ **≥ 250 Go libres** (source sparse, `.part` supprimé après finalisation, marge). Ce n'est **pas réalisable sur ce poste**. Il ne sera donc **pas affirmé** que 100 Go ont été téléchargés tant que ce test n'a pas été exécuté.

Procédure pour un environnement adapté (scripts identiques, seule la taille change) :
```bash
export E2E_BASE_URL=https://storage.example.test E2E_USER=… E2E_WORKDIR=/data/e2e
tests/e2e/scenarios/01-single-file.sh 100G real      # ≈ 100 Go générés, 1 passe sha256, upload, download, sha256
tests/e2e/scenarios/02-zip-multi.sh 5 20G real
tests/e2e/scenarios/03-resume-file.sh 100G 20G       # coupure à 20 Go, reprise curl + navigateur
tests/e2e/scenarios/04-resume-zip.sh 5 20G 20G
```
Durées indicatives à 1 Go/s disque / 0,5 Go/s AES : génération 100 Go ≈ 3–10 min, upload ≈ 15–30 min, téléchargement ≈ 5–15 min.

**Échelle locale retenue** (même code, mêmes mécanismes, Zip64 exercé) : 01 = 10 Gio, 02 = 5 × 2 Gio (archive 10 Gio > 4 Gio) + variante 1 × 5 Gio (entrée > 4 Gio), 03 = 10 Gio coupé à 4 Gio, 04 = archive 10 Gio coupée à 4 Gio. Variantes « fermeture du navigateur » et « redémarrage machine » : exécutées si un navigateur pilotable est disponible ; sinon simulées par arrêt/redémarrage du client `curl` (sémantique identique côté serveur : nouvelle connexion, `Range` à l'offset du fichier partiel) et **documentées comme telles**.

---

## 10. Definition of Done

- [ ] Toutes les stories P0 et P1 livrées, revues, avec tests unitaires/fonctionnels verts (`php vendor/bin/phpunit`), `phpstan` sans nouvelle erreur.
- [ ] Les anciens mécanismes structurellement défaillants sont supprimés (manager OPFS/FS Access, ZIP synchrone en RAM/`/tmp`, ticket one-shot, `DownloadTokenService`), ainsi que leurs tests contractuels et résidus (`var/download_prepare`).
- [ ] Aucune lecture intégrale en mémoire dans les chemins de téléchargement (test contractuel) ; pic mémoire mesuré < 64 Mio.
- [ ] Scénarios E2E 1–4 exécutés à l'échelle maximale locale, rapports produits dans `tests/e2e/reports/`, hash vérifiés, espace disque restitué, données de test supprimées (et seulement elles).
- [ ] Suite de résilience exécutée, résultats consignés.
- [ ] Diagnostics admin permettent d'expliquer un échec à partir d'un `download_id` sans reproduction.
- [ ] README, `.env.exemple`, runbook et bilan (`EPIC-DL100-bilan.md`) à jour, incluant la procédure 100 Go et les prérequis hébergeur.
- [ ] Traductions complètes (fr, en, de, lt, no) pour les nouveaux textes.
- [ ] Pas de régression sur les petits fichiers, la prévisualisation, l'upload et l'extraction ZIP (suite existante verte).

---

## 11. Décisions d'architecture (ADR synthétiques)

| ADR | Décision | Alternatives écartées | Raison |
|---|---|---|---|
| ADR-1 | Téléchargement natif du navigateur + serveur RFC 9110 | Gestionnaire in-page OPFS/FS Access (actuel) ; Service Worker « StreamSaver » | Durabilité réelle sur disque, pas de quota, 1× disque, compat totale, reprise après redémarrage possible, complexité et risques bien moindres (§6.6). |
| ADR-2 | Archives ZIP **virtuelles** Zip64/STORE servies par plage | Génération sur disque (`var/`) par job puis service du fichier ; streaming non reprenable (`zipstream`) | 0 Go de stockage temporaire, démarrage instantané, reprise identique aux fichiers, pas de perte d'archive en cours, pas de nettoyage de gros fichiers ; coût = CRC32 (calculé à l'upload désormais, backfill une fois pour l'existant). |
| ADR-3 | Empreintes CRC32 + SHA-256 persistées sur `shared_file` | Calcul à la demande | Déterminisme de l'archive, ETag fort, vérification d'intégrité, coût amorti. |
| ADR-4 | Jeton HMAC stateless longue durée re-vérifiant les ACL | Session uniquement ; ticket cache one-shot | Reprise après expiration de session/redémarrage ; révocation immédiate conservée ; aucune table supplémentaire. |
| ADR-5 | Préparation par « ticks » HTTP bornés + commandes CLI | Messenger/worker permanent | Cohérent avec l'existant (`ZipExtractService`) et compatible hébergement mutualisé sans processus résident ; CLI/cron disponible pour le backfill massif. |
| ADR-6 | Méthode STORE, pas de compression | DEFLATE | Déterminisme des offsets (Range), CPU nul, données utilisateur rarement compressibles ; compression = non-objectif. |

---

## 12. État de référence de la suite de tests (avant chantier)

`php vendor/bin/phpunit` (2026-11-02) : **367 tests, 34 erreurs, 14 échecs, 16 ignorés**. Aucun échec ne concerne le téléchargement ; les causes sont préexistantes et hors périmètre : base `stuwebstorage_test` absente (11 tests fonctionnels avec BDD), classes `final` non doublables (`FolderAncestorService`, `BugReportScreenshotStorage`), signatures de constructeurs obsolètes dans des tests (`ShareAuthorizationService`, `UserController`), assertions de contrat de templates périmées. Les tests de téléchargement existants (`FilesDownloadRangeHttpTest`, `FilesPreviewRangeHttpTest`, `PublicDownloadRangeTest`, `EncryptedStreamDeliveryServiceTest`, `FileEncryptionServiceV2RangeTest`, `V2SegmentIndexServiceTest`, `HttpByteRangeTest`, contrats JS) sont **verts** et serviront de filet pendant la refonte ; ceux qui décrivent les mécanismes supprimés (manager OPFS, page de progression) seront remplacés, pas simplement effacés. Le chantier ne vise pas à corriger les 48 échecs préexistants (hors périmètre), mais ne doit pas en ajouter.

## 13. Hypothèses et points à confirmer

1. L'hébergement de production tolère des réponses HTTP longues (plusieurs dizaines de minutes) ou, à défaut, la reprise par Range compense les coupures : **à confirmer** (type de serveur, `request_terminate_timeout`, LiteSpeed).
2. Les navigateurs cibles du projet sont les versions récentes de Chrome/Edge/Firefox/Safari (comportement de reprise natif documenté §6.6).
3. Les fichiers v1 restants sont peu nombreux ; la migration v1→v2 peut être lancée en CLI par l'administrateur.
4. Le compte/l'environnement de test E2E (utilisateur dédié, `MAILER_DSN` permettant de lire le code TOTP) peut être créé sur l'instance locale `storage.loc:90` ; les données de test sont préfixées `e2e-dl100-` et isolées dans un dossier dédié.
5. Un navigateur pilotable (Playwright + Firefox/Chromium) peut être installé localement ; sinon le parcours UI sera validé avec le navigateur intégré et le client `curl` servira de référence pour la reprise.
