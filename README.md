# CoolShare — Balises de partage pour PrestaShop

> Ce que Facebook, LinkedIn, WhatsApp et X affichent quand on partage une page de votre boutique : un titre, une description, une image. CoolShare les écrit proprement, sur chaque page.

*Open Graph and X (Twitter) cards for PrestaShop. Free & open source (OSL 3.0).*

![PrestaShop 8 → 9](https://img.shields.io/badge/PrestaShop-8%20%E2%86%92%209-blue) [![Version](https://img.shields.io/github/v/release/zenmod40/coolshare)](https://github.com/zenmod40/coolshare/releases/latest)
![License: OSL 3.0](https://img.shields.io/badge/License-OSL--3.0-blue)

## Le problème

Les thèmes PrestaShop écrivent quelques balises de partage, mais rarement toutes : souvent pas d'image en dehors des fiches produit (la carte partagée reste vide), pas de carte X, une description vide sur l'accueil. Et un module qui en ajoute par-dessus crée des doublons : les réseaux prennent alors la première balise venue.

## Ce que fait CoolShare

- **Un seul jeu de balises, sans doublon.** Les balises de partage du thème sont retirées et remplacées par un jeu complet : Open Graph, carte X, prix des produits, langues. Sans toucher aux fichiers du thème, ni au titre, à la description ou aux données structurées de la page.
- **Toutes les pages publiques.** Accueil, produits, catégories, pages CMS et marques s'éditent une à une ; les autres pages (recherche, fournisseurs, pages de modules) reçoivent un jeu complet avec les valeurs par défaut.
- **Éditable là où vous réglez déjà le SEO.** Un bloc « Partage » dans la fiche produit (onglet SEO), le formulaire des catégories, des pages CMS et des marques : titre, description, image, avec un aperçu de la carte mis à jour à la saisie.
- **Des images prêtes pour les réseaux.** Recadrées en 1200 × 630, le format attendu par Facebook et LinkedIn, avec leurs dimensions réelles déclarées.
- **Un contrôle de qualité.** Les pages sans image, à l'image trop petite, sans description, au texte trop long ou au titre en double, avec un lien vers le formulaire pour corriger.
- **Un lien « Rafraîchir chez Facebook »** sous chaque aperçu : Facebook garde les anciennes cartes en cache plusieurs jours.

## D'où viennent les valeurs

| Balise | D'abord | Sinon | Sinon |
|---|---|---|---|
| Titre | le titre de partage saisi | le titre SEO | le nom de la page |
| Description | la description de partage | la description SEO | le début du texte de la page, sans HTML, coupé à 200 caractères |
| Image | l'image de partage | l'image de la page : photo du produit, image de la catégorie, logo de la marque | l'image par défaut de la boutique |

Pour un produit ou une catégorie, CoolShare choisit la plus petite version de l'image qui fait au moins 1200 px de large, sinon la plus grande.

## Compatibilité

PrestaShop **8.0 → 9.x** · PHP **7.2+** · Aucune dépendance Composer.

- Nouvelle fiche produit (8.1+ et 9) et ancienne fiche produit (8.0, ou 8.1-8.2 sans la nouvelle) : les deux reçoivent le bloc « Partage ».
- Essayé sur PrestaShop 8.2.8 (thème enfant de Classic) et 9.1.5 (Hummingbird, multiboutique, trois langues).
- PrestaShop 1.7.8 : non essayé.

### Si un cache de page complète est installé

Le remplacement des balises passe par le crochet `actionOutputHTMLBefore`, qu'un module de cache de page complète peut court-circuiter. La page de configuration indique si le remplacement a bien été observé sur la vitrine. Sinon, passez en mode **Compléter** : rien n'est retiré, seules l'image et la carte X sont ajoutées.

## Installation

1. Télécharger la dernière release (`coolshare.zip`).
2. Back-office PrestaShop → **Modules** → **Téléverser un module**.
3. Installer, puis ouvrir la configuration de **CoolShare**.

Les images de partage sont rangées dans `img/coolshare/`, hors du dossier du module : elles survivent à ses mises à jour. La désinstallation les supprime, avec les textes saisis.

## Configuration

- **Réglages** : activation, mode (remplacer ou compléter), compte X de la boutique, image par défaut.
- **Page d'accueil** : titre, description et image de partage (l'accueil n'a pas de formulaire à lui dans PrestaShop).
- **Contrôle** : la liste des pages à corriger, filtrable par type de page et par problème.

## Pour les développeurs

Toute la logique passe par une classe publique, `CoolShareApi`, que le back-office du module appelle et que d'autres modules ou applications peuvent appeler aussi. Elle ne dépend pas du back-office : l'employé est passé en paramètre.

```php
CoolShareApi::API_VERSION; // 1

// Entités : 'index' (avec $idEntity = 0), 'product', 'category', 'cms', 'manufacturer'.
CoolShareApi::get($entity, $idEntity, $idLang, $idShop);
// → title, title_source ('share'|'seo'|'auto'), description, description_source,
//   image ['url','width','height','alt'] | null, image_source ('share'|'entity'|'default'|null),
//   url, site_name, locale, locale_alternates, type, price (produits)

CoolShareApi::set($entity, $idEntity, $idLang, $idShop, ['title' => '…', 'description' => '…'], $idEmployee);
// Clé absente : pas touchée ; '' : effacée. → ['previous' => ['title' => ?, 'description' => ?]]

CoolShareApi::setImage($entity, $idEntity, $idShop, $cheminFichier, $idEmployee);
// Recadre en 1200 × 630. → ['image' => […], 'previous' => ?string jeton de restauration]

CoolShareApi::clearImage($entity, $idEntity, $idShop, $idEmployee);            // → ['previous' => ?string]
CoolShareApi::restoreImage($entity, $idEntity, $idShop, $jeton, $idEmployee);  // remet l'image d'avant

CoolShareApi::audit($idShop, $idLang, ['entity' => ?, 'problem' => ?, 'page' => 1, 'per_page' => 50]);
// → ['total' => int, 'rows' => [['entity','id_entity','name','problems' => […]]]]
// Problèmes : no_image, image_too_small, no_description, description_too_long,
//             title_too_long, duplicate_title
```

Les erreurs sont des `CoolShareException` dont le message est un code stable : `unknown_entity`, `not_found`, `bad_image`, `image_too_large`, `bad_restore_token`, `save_failed`. Les images remplacées sont gardées 30 jours pour permettre la restauration.

Le contrat suit SemVer : une signature ne change qu'avec une version majeure et une nouvelle `API_VERSION`.

## Régie

CoolShare se pilote aussi depuis **Régie**, l'application de gestion de boutique de ZM40 : édition sans passer par le back-office, aperçu, contrôle et actions sur tout le catalogue.

## Confidentialité

CoolShare vérifie périodiquement (au maximum 1×/jour) si une nouvelle version est disponible via l'**API publique de GitHub**, et récupère la liste des autres modules ZM40 depuis **zm40.com**. Ces requêtes sont **anonymes** : **aucune donnée de votre boutique n'est transmise** (seule l'adresse IP de votre serveur est visible, comme pour toute requête HTTP). Vous pouvez **tout désactiver** dans la configuration du module (onglet **Écosystème ZM40**).

Les balises de partage, elles, ne font appel à aucun service extérieur.

## Support & services

CoolShare est **offert à la communauté, sans support garanti**. Les issues GitHub sont les bienvenues pour les **bugs** et les **idées**.

Besoin d'aide à l'installation, d'une adaptation sur mesure, d'un connecteur (ERP, marketplace, API), de débogage ou de maintenance ? → **[zm40.com](https://zm40.com)** (c'est Nicolas qui répond).

## Contribuer

Les PR sont bienvenues. Merci de garder le style du code et d'ouvrir une issue avant les gros changements.

## Licence

**OSL 3.0** © 2026 Nicolas Michaud — ZM40 / Magic Garden · [zm40.com](https://zm40.com)

Voir [LICENSE](LICENSE) pour le texte complet.
