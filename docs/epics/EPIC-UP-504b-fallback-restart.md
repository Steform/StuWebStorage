# EPIC UP-504b — Upload rapide par défaut + fallback restart après 504

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-UP-504b |
| Statut | **Implémenté** (2026-11-10) |
| Auteur | Stephane H. |
| Date | 2026-11-10 |
| Projet | StuWebStorage |
| Parent | `EPIC-UP-504` (v1 adaptatif permanent trop lent sur ligne normale) |
| Trigger produit | 504 observée surtout via **VPN gratuit** ; le shrink/adaptatif permanent à 1 Mio rend l’upload « vraiment trop long » sur une bonne ligne |
| Hors scope | tus / parallel chunks / hausse o2switch seule |

---

## 0. Pourquoi

La v1 UP-504 a mis **tout le monde** en mode prudent (1 Mio + adaptation continue).  
Sur une ligne correcte, le surcoût HTTP est ressenti ; le vrai besoin est :

> Aller **vite** en conditions normales (2 Mio comme avant), et ne basculer en mode lent **que** si le proxy coupe (504/502/503).

---

## 1. Vision

> Mode normal = 2 Mio fixe et rapide. Après échec gateway, **une** reprise depuis zéro en session fallback 1 Mio (adaptatif autorisé uniquement dans ce mode).

---

## 2. Principes

### P1 — Normal rapide

- `app.upload_chunk_bytes: 2097152`
- Pas de shrink mid-session en mode normal
- Retries courts **même taille** (ex. 2 tentatives) puis bascule

### P2 — Fallback = nouvelle session

- Abort best-effort de l’ancienne session (`previous_upload_id`)
- Nouvelle session avec `fallback=1` → `chunk_size_bytes` = 1 Mio
- Upload du fichier **depuis l’offset 0**
- Une seule bascule fallback par fichier (pas de boucle)

### P3 — Adaptatif seulement en fallback

- En fallback : shrink /2 + budget temps OK (VPN / ligne pourrie)
- Plancher `app.upload_chunk_bytes_min` (256 KiB)

### P4 — Observabilité

- Conserver `upload.chunk.gateway_timeout` (client-event)
- Log session : `fallback: 1` + éventuel `previous_upload_id`

---

## 3. Stories

### US-01 — Config dual plafond

**Critères**

- `app.upload_chunk_bytes: 2097152` (normal)
- `app.upload_chunk_bytes_fallback: 1048576`
- garder min + time budget
- Contract services.yaml

**Estimate** : S

---

### US-02 — Session `fallback` + abort précédent

**Critères**

- `POST /files/upload/session` accepte `fallback=1` et `previous_upload_id`
- Abort best-effort de l’ancienne session avant création
- Payload : `chunk_size_bytes`, `chunk_size_min_bytes`, `chunk_time_budget_ms`, `fallback` (bool)
- Log `upload.session.created` avec `fallback`

**Estimate** : S

---

### US-03 — Client normal puis restart fallback

**Critères**

- Mode normal : taille fixe session, retries sans shrink
- Après épuisement 502/503/504 (ou network transient) **et** pas déjà en fallback → restart fichier en fallback
- UI soft : message reprise / fallback
- Pas de 2e restart si fallback échoue encore → message 504 final

**Estimate** : M

---

### US-04 — i18n

**Critères**

- Clé `files.upload.chunk_fallback_restart` (fr/en + de/lt/no)
- Garder `chunk_retrying` pour retries intra-fallback
- Garder `xhr_gateway_timeout` comme échec final

**Estimate** : S

---

### US-05 — Contrats

**Critères**

- `UploadGatewayTimeoutContractTest` reflète 2 Mio + fallback 1 Mio + restart client
- Plus d’obligation d’adaptatif en mode normal

**Estimate** : S

---

## 4. Ordre de livraison

1. US-01 config  
2. US-02 session serveur  
3. US-03 client  
4. US-04 i18n  
5. US-05 contrats  

---

## 5. Definition of Done

- [x] Ligne normale : chunks 2 Mio (vitesse d’avant)
- [x] Après 504 épuisée : restart from zero en 1 Mio fallback
- [x] Fallback unique ; échec final = message gateway existant
- [x] Contrats verts (`UploadGatewayTimeoutContractTest`, 40 assertions)
- [ ] Recette manuelle (bonne ligne + throttle/VPN)

---

## 6. Recette

1. Ligne OK : upload gros fichier → chunks ~2 Mio, pas de message fallback.  
2. Throttle / VPN : provoquer 504 → UI « reprise en mode stable » → nouvelle session `fallback:1` → chunks 1 Mio (puis shrink si besoin).  
3. `prod.log` : `upload.chunk.gateway_timeout` puis `upload.session.created` avec `fallback: 1`.  
