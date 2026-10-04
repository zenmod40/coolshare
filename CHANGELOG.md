# Changelog CoolShare

Toutes les évolutions notables du module sont listées ici.
Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).

## [1.0.0] — 2026-10-04

Première publication open source.

### Ajouté

- **Un seul jeu de balises de partage par page.** Les balises Open Graph, X et prix du thème sont retirées du `<head>` et remplacées par un jeu complet, sur toutes les pages publiques. Le titre, la description et les données structurées de la page ne sont pas touchés.
- **Bloc « Partage » dans les formulaires de PrestaShop** : fiche produit (onglet SEO, nouvelle et ancienne fiche), catégories, pages CMS et marques. Titre et description par langue, image, aperçu de la carte et compteurs de caractères.
- **Page d'accueil** : titre, description et image de partage dans la configuration du module.
- **Images recadrées en 1200 × 630**, rangées dans `img/coolshare/` pour survivre aux mises à jour du module. Les images remplacées sont gardées 30 jours.
- **Image par défaut** pour toute page sans image.
- **Contrôle de qualité** : pages sans image, à l'image trop petite, sans description, au texte trop long, au titre en double, avec un lien vers le formulaire à corriger.
- **Mode « Compléter »** pour les boutiques où un cache de page complète empêche le remplacement, et indicateur du remplacement observé sur la vitrine.
- **Classe publique `CoolShareApi`** (`API_VERSION` 1) : lecture des valeurs effectives et de leur origine, écriture, image avec restauration, contrôle de qualité.
- Composant commun ZM40 : notification de mise à jour, autres modules ZM40, opt-out réseau.
