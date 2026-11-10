# EPIC UP-504 — Éviter les HTTP 504 pendant l’upload par chunks

| Champ | Valeur |
|---|---|
| Identifiant | EPIC-UP-504 |
| Statut | Implémenté v1 (2026-11-10) — **stratégie remplacée par `EPIC-UP-504b`** (normal 2 Mio + fallback restart) |
| Auteur | Stephane H. |
| Date | 2026-11-10 |
| Projet | StuWebStorage |
| Trigger prod | `prod.log` 2026-11-10 : uniquement `upload.session.created` (~24,5 Gio, `chunk_size_bytes: 2097152`) puis silence ; UI : `Delai depasse (HTTP 504)` — **aucun** `encrypt.created` / `upload.failed` / `db.*` |
| Lié à | Chunk upload (`ChunkedUploadService`, `files-space.js`), retries gateway déjà partiels, `EPIC-DB-LONGOPS` (autre mur, post-finalize) |
| Complète | Mitigation 504 sur **ticks encrypt** (budgets 8 Mio) — **ne couvre pas** la phase chunks |
| Hors scope v1 | Worker / tus protocol complet ; changer o2switch sans levier app ; Messenger |

---

## 0. Pourquoi

Sur un 25 Gio en prod lente, l’upload **meurt pendant les chunks** avec une **HTTP 504** (passerelle), **avant** tout chiffrement.

Preuve log :

1. `upload.session.created` OK (disque, droits, session)
2. **Rien ensuite** dans `prod.log`
3. UI : message gateway timeout (correct)

Interprétation :

> La requête `POST /files/upload/chunk` a été **coupée par le reverse proxy** (timeout typique hébergeur ~30–60 s) **avant** qu’une réponse PHP ne soit écrite. L’app ne logge donc **aucun** échec.

Ce n’est **pas** :

- MySQL gone away (`EPIC-DB-LONGOPS`)
- permissions `var/`
- finalize / encrypt

C’est le **mur proxy sur un chunk trop long à monter** (2 Mio sur uplink lent / saturé).

### Leçon produit

> Un chunk « petit pour le PHP » (2 Mio &lt; `upload_max_filesize`) peut rester **trop gros pour le timeout passerelle** si le débit effectif est faible. **Taille chunk × débit = durée requête** ; la durée doit rester **largement sous** le timeout proxy.

---

## 1. Vision (une phrase)

> L’upload chunké adapte (et borne) la taille des morceaux et les retries pour qu’**aucune** requête chunk ne reste exposée assez longtemps pour déclencher une 504 proxy, même sur une ligne ~2× plus lente qu’en local.

---

## 2. Constat code actuel

| Élément | État |
|---|---|
| `app.upload_chunk_bytes` | **1 Mio** (`1048576`) plafond initial |
| `app.upload_chunk_bytes_min` | **256 KiB** (`262144`) |
| `app.upload_chunk_time_budget_ms` | **25000** (budget soft client) |
| Clamp PHP | `resolveAdvertisedChunkBytes()` (upload/post max) |
| Retry client | `CHUNK_MAX_ATTEMPTS = 4` + **shrink /2** sur 502/503/504 + `onerror` |
| Idempotence serveur | `chunkIndex < expectedIndex` → OK (retry safe) |
| Log succès chunk | **Non** (volontaire, anti-flood) |
| Log 504 proxy | `POST /files/upload/chunk-client-event` → `upload.chunk.gateway_timeout` |
| Adaptation débit | **Présente** : mesure durée → réduit `chunkSz` sous le budget |

---

## 3. Principes figés

### P1 — Budget temps par requête chunk

Cible app : chaque `POST /chunk` doit viser **&lt; 50 %** du timeout proxy connu (hypothèse doc **60 s** → budget **≤ 25–30 s**).  
Si le timeout réel o2switch est inconnu, documenter la valeur mesurée / annoncée et paramétrer.

### P2 — Taille adaptative, pas seulement plus petite en dur

- Plancher config (ex. **256 KiB** ou **512 KiB**)
- Plafond config (ex. **2 Mio**, actuel)
- **Adaptation runtime** : après N chunks, si `durée_upload / taille` implique un prochain chunk &gt; budget → **réduire** la taille des suivants (sans changer `upload_id` / index)

### P3 — Retry ≠ suffisant seul

Retry idempotent **conservé**.  
Après 504/502/503 sur un chunk : **réduire la taille** avant la prochaine tentative du **même** index (ou du suivant si le serveur a déjà avancé — déjà géré).

### P4 — Observabilité sans flood

- Toujours **pas** de log info par chunk OK
- Log **warning** serveur optionnel : non (proxy ne passe pas)
- Côté client : compteur / phase UI « reprise après délai serveur » (i18n)
- Option : beacon `POST /files/upload/chunk-timeout` **sans body** (méta : `upload_id`, `chunk_index`, `chunk_bytes`, `duration_ms`, `http_status`) pour remplir `prod.log` — **US dédiée**, opt-in

### P5 — Ne pas casser l’idempotence

Réduire la taille mid-session = le client envoie un blob **plus petit** pour `chunk_index` courant. Le serveur append tel quel — OK tant que l’ordre est respecté.  
Interdit : réenvoyer un index déjà accepté avec un **autre** contenu (déjà protégé par `chunkIndex < expected` → no-op succès).

### P6 — Hors app : timeout proxy

Documenter la demande o2switch / Apache `ProxyTimeout` / LiteSpeed.  
L’épic **ne dépend pas** d’une hausse hébergeur pour être « Done », mais la note d’exploitation est obligatoire.

---

## 4. Périmètre

### Inclus (v1)

- Config : `app.upload_chunk_bytes` + `app.upload_chunk_bytes_min` (nouveaux paramètres)
- Client : mesure durée/chunk, shrink sur lenteur ou sur 504, retries améliorés
- i18n hint / toast soft sur reprise gateway
- Contrats PHPUnit / static JS
- Doc exploitation timeout proxy

### Exclu (v1)

- Protocole tus / resumable standard externe
- Parallel multi-chunk (complexe + charge serveur)
- Changer les ticks encrypt (déjà 8 Mio)
- `EPIC-DB-LONGOPS` (déjà livré)

---

## 5. Design proposé

### 5.1 Paramètres

```yaml
# services.yaml (exemples)
app.upload_chunk_bytes: 1048576        # plafond initial conseillé (1 Mio) — ou garder 2 Mio
app.upload_chunk_bytes_min: 262144     # plancher 256 KiB
app.upload_chunk_time_budget_ms: 25000 # budget soft client
```

Session JSON peut aussi renvoyer `chunk_size_min_bytes` + `chunk_time_budget_ms` pour piloter le JS sans hardcode.

### 5.2 Algo client (cœur)

Au moment de `nextChunk` / après chaque chunk OK :

1. Mesurer `elapsedMs` et `bytesSent` du chunk
2. Estimer `bytesPerMs = bytesSent / max(elapsedMs, 1)`
3. `nextSize = min(currentSize, floor(bytesPerMs * timeBudgetMs * 0.8))`
4. Clamp `[minBytes, maxBytes]`
5. Sur 504/502/503 : `currentSize = max(minBytes, floor(currentSize / 2))` puis retry **même index**

### 5.3 Serveur

- Advertiser `chunk_size_bytes` (max), `chunk_size_min_bytes`, éventuellement budget
- Pas d’obligation de connaître la taille exacte suivante (le client slice)
- Garder idempotence index

### 5.4 UI

- Pas d’alarme agressive sur un seul retry
- Après échec définitif : message 504 existant + éventuellement « Essayez une connexion plus stable » (clé i18n courte)

---

## 6. Stories

### US-01 — Config min/max + payload session

**Critères**

- `app.upload_chunk_bytes_min` (+ wiring `ChunkedUploadService`)
- `createSession` renvoie `chunk_size_bytes`, `chunk_size_min_bytes` (et optionnel `chunk_time_budget_ms`)
- Clamp : `min ≤ advertised ≤ php caps`
- Contract services.yaml + source

**Estimate** : S

---

### US-02 — Client adaptatif + shrink sur 504

**Critères**

- `files-space.js` : mesure durée, ajuste `chunkSz` dynamiquement
- Sur `isUploadTransientStatus` : halve size (floor min) puis retry
- Ne pas descendre sous `chunk_size_min_bytes`
- Pas de régression idempotence (serveur déjà OK)
- Contract : présence algo / constantes budget

**Estimate** : M

---

### US-03 — Plafond initial plus prudent (prod lente)

**Critères**

- Décision produit documentée : ex. défaut **1 Mio** ou **512 KiB** si 2 Mio reste trop agressif sur o2switch
- Mettre à jour `UploadGatewayTimeoutContractTest`
- Commentaire dans `services.yaml` / `.user.ini` aligné

**Estimate** : S

---

### US-04 — Observabilité client → prod.log (optionnelle mais recommandée)

**Critères**

- Endpoint léger `POST /files/upload/chunk-client-event` (CSRF) : status, upload_id, chunk_index, chunk_bytes, duration_ms, attempt
- Log canal `upload` niveau warning : `upload.chunk.gateway_timeout`
- Rate-limit / max 1 log par (upload_id, chunk_index, attempt) pour éviter flood
- Ou, si trop lourd v1 : reporter en **US-04b** et se contenter des compteurs UI

**Estimate** : S–M

---

### US-05 — i18n

**Critères**

- Clé soft : reprise après délai (`files.upload.chunk_retrying` optionnel)
- Garder `xhr_gateway_timeout` comme échec final
- fr/en + alignement de/lt/no

**Estimate** : S

---

### US-06 — Doc exploitation o2switch

**Critères**

- Section dans cet épic : timeout proxy typique, quoi demander (ex. 120–300 s pour uploads)
- Clarifier : hausse proxy **et** chunks adaptatifs = défense en profondeur
- Lien avec `RequestReadTimeout` Apache / LiteSpeed si applicable

**Estimate** : S

---

### US-07 — Contrats de non-régression

**Critères**

- Contract UP-504 : min/max, adaptive shrink, session fields
- Retries + idempotence toujours assertés
- ENC/OPS/DB-LONGOPS contracts inchangés en intention

**Estimate** : S

---

## 7. Ordre de livraison

1. US-01 config + session  
2. US-03 plafond initial prudent  
3. US-02 client adaptatif (valeur prod)  
4. US-05 i18n  
5. US-06 doc  
6. US-04 observabilité (si temps)  
7. US-07 contrats  

---

## 8. Definition of Done (épic)

- [x] Config min/max + budget + payload session
- [x] Après une 504, le client **réduit** la taille et retente (idempotent)
- [x] `prod.log` : session + `upload.chunk.gateway_timeout` via client-event
- [x] Doc timeout proxy (section 11)
- [x] Contrats UP-504 mis à jour
- [ ] Recette manuelle ligne lente / 25 Gio prod (à valider en exploitation)

---

## 9. Recette

1. Throttle navigateur / réseau à un débit où 1 Mio &gt; ~timeout proxy.  
2. Sans fix (historique) : 504 rapide, log = session only.  
3. Avec fix : chunks diminuent, upload continue, finalize atteint → `encrypt.created`.  
4. Vérifier qu’un chunk déjà accepté + retry 504 ne double pas les octets (idempotence).  
5. Sur 504 : présence de `upload.chunk.gateway_timeout` dans `prod.log`.

---

## 10. Hors épic / suivi

| Piste | Note |
|---|---|
| Parallel 2–3 chunks | Gain débit, risque 504×N et complexité ordre — v2 |
| Remonter timeout o2switch seul | Aide, insuffisant sur mobile/très lent |
| `EPIC-DB-LONGOPS` | Toujours requis **après** finalize pour le chiffrement long |

---

## 11. Exploitation o2switch / proxy (US-06)

**Contexte** : même avec des chunks adaptatifs, un timeout proxy trop bas (souvent **30–60 s** chez l’hébergeur) reste un mur si l’uplink est très lent.

**Demande type support** :

- Augmenter le timeout reverse proxy / LiteSpeed / Apache (`ProxyTimeout` / `RequestReadTimeout`) pour les chemins `/files/upload/*` vers **120–300 s**.
- Ne pas réduire `max_execution_time` / `max_input_time` app (déjà longs côté PHP pour encrypt).

**Défense en profondeur** :

1. App : chunks 1 Mio → plancher 256 KiB + shrink sur 504 + budget 25 s.
2. Hébergeur : timeout proxy relevé.
3. Post-finalize : `EPIC-DB-LONGOPS` (reconnect MySQL sur ticks longs).

Mesurer le timeout réel : si `prod.log` montre `upload.session.created` puis `upload.chunk.gateway_timeout` avec `duration_ms` ~30–60k et `http_status: 504`, le proxy coupe avant PHP.
