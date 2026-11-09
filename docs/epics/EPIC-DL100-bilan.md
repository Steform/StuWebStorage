# Bilan EPIC-DL100 — Refonte téléchargement 100 Go

| Champ | Valeur |
|---|---|
| Date | 2026-11-02 |
| Statut | Implémentation livrée — validation E2E à échelle locale exécutée |

## Problèmes identifiés (rappel)

- ZIP synchrone en RAM (`decryptFromStorage` + `addFromString`) + `/tmp` tmpfs → échec silencieux / OOM.
- Reprise in-page (OPFS / File System Access) non durable avant `close()`, Worker obligatoire, quotas navigateur.
- Pas d'ETag / If-Range → le gestionnaire natif ne peut pas reprendre.
- Jeton court (2 h) et optionnel ; timeouts muraux ~3600 s ≈ 4 Go.

## Choix retenus

1. Téléchargement **natif** du navigateur + serveur RFC 9110 (`RangeStreamResponder`).
2. Jetons HMAC longue durée (`DownloadGrantService`, TTL 7 j) sans session sur `/dl/*`.
3. Archives **ZIP virtuelles Zip64/STORE** (`VirtualZipLayout`) servies par plage, 0 octet sur disque.
4. Empreintes CRC32 + SHA-256 à l'upload + commande de backfill.

## Modifications principales

- Migration `Version20261102190000` : `content_crc32` / `content_sha256` + table `download_archive`.
- Package `App\Service\Download\*` (grant, range, ZIP virtuel, archives).
- Routes `/dl/file/{id}`, `/dl/archive/{token}`, API `/files/archives*`.
- Frontend : `files-download-launcher.js`, `files-archive-flow.js` (suppression du chemin OPFS comme voie principale).
- Scripts E2E : `tests/e2e/`.

## Stories

| Story | Statut |
|---|---|
| US-01 Empreintes | Fait |
| US-02 Index / lecture Range | Fait (lazy index) |
| US-03 RangeStreamResponder | Fait |
| US-04 Grants | Fait |
| US-05→08 Archives virtuelles | Fait |
| US-09→11 Lanceur + ZIP UI + public | Fait (public fichier natif) |
| US-13/14 Config / .htaccess | Fait (diagnostics enrichis partiellement via extra JSON) |
| US-16/17 E2E | Fait à échelle locale |
| US-15 limite concurrente | Non (P2 optionnel) |
| US-12 SHA visible UI propriétés | Non (P2) |
| Migration v1→v2 commande dédiée | Partiel (index build existant ; migration v1 non livrée en CLI dédiée) |

## Tests exécutés

| Test | Volume | Résultat |
|---|---|---|
| PHPUnit Download/Grant/Layout + contrats | — | OK (16+ tests ciblés) |
| Smoke 01 single-file | 32 Mio (aussi 8 / 64 / 512 Mio) | OK — SHA-256 + `unzip -t` |
| Smoke 02 zip-multi | 3×4 Mio | OK — extraction + SHA par entrée |
| Smoke 03 resume (3 plages) | 32 Mio | OK |
| Smoke 04 zip | 2×4 Mio | OK |
| E2E HTTP navigateur 100 Go | — | **Non exécuté** : ~47 Go libres sur `/`, pas de parcours auth HTTP automatisé (TOTP) sur cette instance |

## Limitations restantes

- Les scénarios E2E 100 Go réels nécessitent ≥ 250 Go libres et un compte de test authentifiable ; scripts paramétrables prêts (`SIZE=100G`).
- La page `download_progress` et les anciens JS manager/checkpoint/sinks existent encore (non branchés sur le listing) — nettoyage cosmétique possible.
- `FolderZipService` legacy (RAM) n'est plus appelé par les routes ZIP authentifiées, mais le fichier de service n'a pas été supprimé.
- Pas de test HTTP `curl -C -` bout-en-bout sur cette machine (base locale sans table `user` exploitable via la CLI au moment du bilan).

## Contraintes d'infrastructure

- Apache `Timeout`, FPM `request_terminate_timeout`, LiteSpeed : documentés dans `.env.exemple` / Epic ; reprise Range attenue les coupures.
- Ne jamais compresser `/dl/*` (`.htaccess` mis à jour).
