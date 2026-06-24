# Captures d'écran — StuWebStorage

Liste des visuels attendus pour alimenter le `README.md`.  
Place chdir chaque fichier **exactement** dans `./docs/screenshots/` avec le nom indiqué.

## Conventions générales

| Paramètre | Recommandation |
|-----------|----------------|
| Format | PNG (`.png`), fond opaque |
| Résolution desktop | 1440 × 900 px (fenêtre navigateur) |
| Résolution mobile | 390 × 844 px (iPhone 14 Pro ou équivalent) |
| Thème par défaut | Clair (`light`) sauf mention « dark » |
| Langue UI | Français (`fr`) pour la cohérence du README |
| Données | Utiliser des noms fictifs crédibles (pas de vraies adresses e-mail perso) |
| Anonymisation | Flouter ou masquer toute info sensible (IP, clés, e-mails réels) |

---

## 01 — Accueil & authentification

| Fichier | Contenu à capturer | Notes |
|---------|-------------------|-------|
| `01-home-hero-light.png` | Page d'accueil `/` — hero, titre, CTA, cartes statistiques (utilisateur connecté avec quota) | Thème clair, utilisateur avec rôle stockage |
| `01-home-hero-dark.png` | Même vue en thème sombre | Basculer via la bulle thème flottante |
| `01-home-features.png` | Section « Fonctionnalités de la plateforme » (5 cartes) | Scroll léger si nécessaire |
| `02-login-form.png` | Page `/login` — formulaire e-mail / mot de passe | Visiteur non connecté |
| `03-login-totp.png` | Page `/login/totp` — saisie du code reçu par e-mail | Après connexion avec TOTP activé |
| `04-access-gate.png` | Page `/access-gate` — verrou d'accès actif, champ « note d'accès » | Activer le verrou depuis l'admin au préalable |
| `05-setup-wizard.png` | Page `/setup` — création du premier administrateur | Environnement vierge ou restauration de démo |

---

## 02 — Espace fichiers (utilisateur)

| Fichier | Contenu à capturer | Notes |
|---------|-------------------|-------|
| `10-files-table-view-light.png` | `/files` — vue tableau, dossiers + fichiers, barre d'outils complète | Thème clair, racine ou sous-dossier peuplé |
| `10-files-table-view-dark.png` | Même vue en thème sombre | |
| `11-files-grid-view.png` | `/files` — vue grille (cartes) | Basculer via préférences UI ou toggle grille |
| `12-files-folder-tree.png` | Arborescence dossiers ouverte (panneau latéral / accordéon) | Montrer au moins 2 niveaux de profondeur |
| `13-files-search-glob.png` | Résultats de recherche avec motif glob (`*.pdf` ou `rapport*`) | Champ recherche actif avec résultats |
| `14-files-advanced-filters.png` | Modale « Filtres avancés » ouverte | Filtres visibles (type, partage, etc.) |
| `15-files-upload-modal.png` | Modale d'envoi de fichiers (drag & drop ou sélecteur) | Montrer barre de progression si possible |
| `16-files-bulk-selection.png` | Sélection multiple (cases cochées) + barre d'actions groupées | Au moins 3 éléments sélectionnés |
| `17-files-rename-modal.png` | Modale de renommage fichier ou dossier | |
| `18-files-properties-modal.png` | Modale propriétés d'un fichier (taille, dates, partages) | |
| `19-files-folder-properties.png` | Modale propriétés d'un dossier | Inclure stats contenu si affichées |
| `20-files-media-preview.png` | Prévisualisation média (image ou PDF) dans la modale | Fichier image ou PDF dans l'espace |
| `21-files-shared-with-me.png` | Onglet / volet « Partagés avec moi » | Au moins un fichier reçu d'un autre utilisateur |
| `22-files-empty-state.png` | État vide d'un dossier sans contenu | Message d'accueil « dossier vide » |

---

## 03 — Partage

| Fichier | Contenu à capturer | Notes |
|---------|-------------------|-------|
| `30-share-public-modal.png` | Modale partage public — lien, expiration, mot de passe | Fichier ou dossier, lien généré visible |
| `31-share-friends-modal.png` | Modale partage entre amis — recherche utilisateur + liste bénéficiaires | Au moins un destinataire ajouté |
| `32-share-bulk-public.png` | Partage public groupé (sélection multiple) | Modale ou confirmation visible |
| `40-public-file-landing.png` | Landing publique `/p/f/{token}` — téléchargement fichier | Vue visiteur anonyme |
| `41-public-file-landing-password.png` | Landing publique — étape mot de passe de partage | Partage protégé par mot de passe |
| `42-public-file-landing-totp.png` | Landing publique — vérification e-mail (code TOTP) | Étape challenge avant téléchargement |
| `43-public-folder-landing.png` | Landing publique dossier `/p/d/{token}` — liste contenu | Dossier avec plusieurs fichiers |
| `44-public-file-preview-inline.png` | Landing publique avec prévisualisation inline d'un fichier | Optionnel si disponible |

---

## 04 — Administration

| Fichier | Contenu à capturer | Notes |
|---------|-------------------|-------|
| `50-admin-godview-all-users.png` | `/admin/files` — vue Godview « tous les utilisateurs » | Paniers multi-utilisateurs visibles |
| `51-admin-godview-single-user.png` | Godview focalisé sur un utilisateur cible | Barre propriétaire / scope visible |
| `52-admin-users-list.png` | `/admin/users` — liste des utilisateurs | Statuts actif / rôles visibles |
| `53-admin-user-detail.png` | `/admin/users/{id}` — fiche utilisateur | Quota, rôles, actions sécurité |
| `54-admin-user-invite.png` | `/admin/users/invite` — formulaire d'invitation | |
| `55-admin-settings-access-gate.png` | `/admin/settings` — carte verrou d'accès | Verrou activé ou formulaire de config |
| `56-admin-trusted-devices.png` | Section appareils de confiance sur fiche utilisateur | Au moins un appareil listé |

---

## 05 — Profil & préférences

| Fichier | Contenu à capturer | Notes |
|---------|-------------------|-------|
| `60-profile-page.png` | `/profile` — pseudonyme, e-mail, changement mot de passe | Utilisateur standard connecté |
| `61-locale-switcher.png` | Sélecteur de langue ouvert (FR / EN / DE / LT / NO) | Bulle flottante ou dropdown |
| `62-theme-switcher.png` | Basculer clair / sombre — bulle thème visible | Montrer les deux icônes ou l'état actif |

---

## 06 — Responsive & détails UX

| Fichier | Contenu à capturer | Notes |
|---------|-------------------|-------|
| `70-files-mobile-light.png` | `/files` sur viewport mobile | Thème clair |
| `71-home-mobile.png` | Page d'accueil mobile | Hero + stats empilées |
| `72-public-landing-mobile.png` | Landing publique fichier sur mobile | |
| `73-flash-toast-success.png` | Toast de confirmation (action réussie) | Ex. après upload ou partage |
| `74-context-menu-actions.png` | Menu contextuel actions fichier (⋯) ouvert | Renommer, partager, supprimer… |

---

## 07 — Qualité & tests (optionnel mais « wow »)

| Fichier | Contenu à capturer | Notes |
|---------|-------------------|-------|
| `80-phpunit-green.png` | Terminal `./vendor/bin/phpunit` — suite verte | Afficher le résumé « Tests: 267 » |
| `81-phpstan-clean.png` | Terminal `composer analyse` — 0 erreur | Si PHPStan configuré localement |
| `82-architecture-diagram.png` | Schéma architecture (export PNG depuis outil de dessin) | Optionnel : complète le README |

---

## Checklist de livraison

- [ ] 43 fichiers PNG minimum (hors optionnels 44, 80–82)
- [ ] Noms de fichiers **identiques** à ce document (minuscules, tirets)
- [ ] Aucune donnée personnelle réelle visible
- [ ] Cohérence visuelle : même jeu de données fictives entre les captures
- [ ] README mis à jour : les chemins `./docs/screenshots/*.png` s'affichent correctement sur GitHub

## Jeu de données fictif suggéré

Pour un rendu professionnel cohérent entre toutes les captures :

| Élément | Valeur suggérée |
|---------|-----------------|
| Admin | `admin@demo.storage` / « Admin Demo » |
| Utilisateur A | `alice@demo.storage` / « Alice Martin » |
| Utilisateur B | `bob@demo.storage` / « Bob Dupont » |
| Dossiers | `Projets/`, `Projets/2026/`, `Archives/` |
| Fichiers | `rapport-annuel.pdf`, `logo.svg`, `photo-equipe.jpg`, `notes.txt` |
| Quota | 5 Go alloués, ~1,2 Go utilisés |
