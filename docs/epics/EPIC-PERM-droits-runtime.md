# EPIC PERM — Droits runtime (PHP / Apache) sur tout `var/` métier

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-PERM |
| Statut | Implémenté |
| Auteur | Stephane H. |
| Date | 2026-11-09 |
| Projet | StuWebStorage |
| Incident origine | Upload 25 Go OK jusqu’aux chunks ; `finalize` → `mkdir(): Permission denied` sur `var/tasks/{userId}` ; UI « Le serveur est injoignable » |
| Lié à | `EPIC-ENC` (jobs `var/tasks`), `EPIC-OPS` (risque mkdir) |

---

## 1. Problème

PHP-FPM / Apache (`www-data` sur Debian) doit **créer et écrire** sous `var/` :

| Chemin | Usage |
|---|---|
| `var/cache` | Cache Symfony |
| `var/log` | `dev.log` / `prod.log` |
| `var/sessions` | Sessions fichier si configuré |
| `var/shared/{owner}` | Fichiers chiffrés `.dat` |
| `var/chunk_upload/{owner}` | Assemblage `.part` |
| `var/tasks/{owner}` | Jobs chiffrement (meta JSON + flag cancel) |
| `var/extract/{owner}` | Jobs extraction ZIP |
| `var/download_prepare` | Legacy prepare download |
| `var/bug-reports` | Captures rapport de bug |
| `var/config` | `locale_configuration.json` |

Un dossier oublié (`var/tasks` en `775` propriétaire déployeur) → **500** au finalize, message client **mensonger** (réseau).

**Hors scope** : chmod du code source (`src/`, `config/`, `public/` lecture Apache suffit). Pas de `777` mondial.

---

## 2. Vision

> Un script **à la racine**, lancé **en tant que l’utilisateur PHP/Apache**, crée les répertoires runtime manquants, pose `2775` + setgid, **prouve** l’écriture, et échoue **lisiblement** si le propriétaire bloque (cas prod : dossier à `chown` en root une fois).

---

## 3. Stories

### US-01 — Inventaire figé des chemins runtime

- [x] Liste unique dans le script (et contrat PHPUnit) alignée sur le code (`ChunkedUploadService`, `UploadEncryptJobService`, `ZipExtractService`, `BugReportScreenshotStorage`, `LocaleConfigurationService`, Symfony cache/log/sessions).
- [x] `.gitignore` + `.gitkeep` pour les arbres pas encore versionnés (`extract`, `bug-reports`).

### US-02 — Script racine `fix-runtime-permissions.sh`

- [x] Usage `--help`, `--check`, `--web-user` (root seulement).
- [x] Mode normal : utilisateur courant = PHP/Apache → `mkdir` + `chmod 2775` + probe write.
- [x] Mode root `--web-user www-data` : `chown` puis mêmes modes (déblocage ownership).
- [x] Exit ≠ 0 si un chemin n’est pas inscriptible ; message d’action (sudo chown / relancer).
- [x] Pas de `chmod -R 777` ; pas de toucher à `src/` `vendor/` `public/` hors `var/`.

### US-03 — Documentation

- [x] README : section Runtime permissions (commande type Debian `www-data`).
- [x] `.env.exemple` : pointeur vers le script.
- [x] En-tête du script = source de vérité usage.

### US-04 — Contrats automatiques

- [x] PHPUnit : script présent, shebang, chemins listés, flags `--check` / `--web-user`, README cite le script.

---

## 4. Validation

| Scénario | Attendu |
|---|---|
| `./fix-runtime-permissions.sh --help` | Usage, exit 0 |
| `./fix-runtime-permissions.sh --check` (user qui peut écrire) | OK / exit 0 |
| `sudo -u www-data ./fix-runtime-permissions.sh` | dirs 2775, probe OK **ou** erreur ownership claire |
| `sudo ./fix-runtime-permissions.sh --web-user www-data` | chown + 2775, ensuite www-data écrit `var/tasks` |
| PHPUnit contrat | vert |

---

## 5. Hors scope

- Corriger le message UI « serveur injoignable » (reste ENC/OPS : mapper 500 mkdir → flash stockage).
- ACL NFS / SELinux spécifiques hébergeur (documenter en commentaire si besoin, pas d’automatisation).
