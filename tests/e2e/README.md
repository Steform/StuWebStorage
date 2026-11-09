# E2E — Téléchargements 100 Go (EPIC-DL100)

## Prérequis

- Instance joignable (`E2E_BASE_URL`, ex. `http://storage.loc:90`)
- Compte utilisateur avec quota suffisant (`E2E_USER` / `E2E_PASSWORD`)
- Outils : `bash`, `curl`, `php`, `sha256sum`, `fallocate`/`truncate`, `unzip`/`7z`, `python3`
- Espace disque : voir tableau ci-dessous

## Variables

| Variable | Défaut | Rôle |
|---|---|---|
| `E2E_BASE_URL` | `http://storage.loc:90` | Base URL |
| `E2E_WORKDIR` | `/tmp/e2e-dl100` | Données locales de test |
| `E2E_USER` / `E2E_PASSWORD` | — | Compte de test |
| `E2E_SIZE` | `10G` | Taille scénario fichier unique |
| `E2E_MODE` | `sparse` | `sparse` ou `real` |

Tous les artefacts créés côté plateforme portent le préfixe `e2e-dl100-<run-id>`.

## Scénarios

```bash
tests/e2e/bin/generate-test-files.sh --size 10G --mode sparse --count 1
tests/e2e/scenarios/01-single-file.sh 10G sparse
tests/e2e/scenarios/02-zip-multi.sh 5 2G sparse
tests/e2e/scenarios/03-resume-file.sh 10G 4G
tests/e2e/scenarios/04-resume-zip.sh 5 2G 4G
```

## Capacité locale vs 100 Go

Ce poste dispose typiquement de ~50 Go libres : l'échelle locale validée est **10 Gio** (fichier) / **5×2 Gio** (ZIP).  
Pour 100 Go réels, prévoir ≥ 250 Go libres et lancer les mêmes scripts avec `SIZE=100G`.

## Smoke PHP local (hors HTTP) — taille obligatoire en argument

Chiffrement v2 + download multi-range + ZIP virtuel Zip64, sans serveur HTTP.  
Aucune taille par défaut : `<size>` est obligatoire ; `[cut]` (offset resume) vaut `size/4` s’il est omis.

```bash
php tests/e2e/bin/smoke-volume-php.php 64M
php tests/e2e/bin/smoke-volume-php.php 25G 4G
php tests/e2e/bin/smoke-volume-php.php 100G 8G
```

Prévoir ~2× `<size>` d’espace libre. Les gros artefacts sous `var/test/e2e-dl100-volume/` sont **toujours** effacés à la sortie (succès ou échec).

## Nettoyage

Chaque scénario enregistre `trap cleanup EXIT`. Nettoyage manuel :

```bash
tests/e2e/bin/cleanup.sh <run-id>
```
