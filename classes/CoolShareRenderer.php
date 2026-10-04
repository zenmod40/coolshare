<?php
/**
 * CoolShare - Balises de partage (Open Graph, cartes X) pour PrestaShop
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Le jeu de balises d'une page, et son remplacement dans le HTML du thème.
 */
class CoolShareRenderer
{
    /** Toute balise de partage, quel que soit l'ordre de ses attributs. */
    const PATTERN = '#<meta\b[^>]*\b(?:property|name)\s*=\s*(["\'])(?:og|twitter|product):[^"\']*\1[^>]*>[ \t]*\R?#i';

    /** Le bloc complet : Open Graph, prix d'un produit, carte X. */
    public static function tags(array $page, $twitterSite = '')
    {
        $p = function ($nom, $valeur) {
            return '<meta property="' . $nom . '" content="' . self::e($valeur) . '">';
        };
        $n = function ($nom, $valeur) {
            return '<meta name="' . $nom . '" content="' . self::e($valeur) . '">';
        };

        $l = [];
        $l[] = $p('og:type', $page['type']);
        $l[] = $p('og:site_name', $page['site_name']);
        $l[] = $p('og:title', $page['title']);
        if ($page['description'] !== '') {
            $l[] = $p('og:description', $page['description']);
        }
        if ($page['url'] !== '') {
            $l[] = $p('og:url', $page['url']);
        }
        $l[] = $p('og:locale', $page['locale']);
        foreach ($page['locale_alternates'] as $alt) {
            $l[] = $p('og:locale:alternate', $alt);
        }
        if ($page['image']) {
            $l[] = $p('og:image', $page['image']['url']);
            if (strpos($page['image']['url'], 'https://') === 0) {
                $l[] = $p('og:image:secure_url', $page['image']['url']);
            }
            if (!empty($page['image']['width']) && !empty($page['image']['height'])) {
                $l[] = $p('og:image:width', (int) $page['image']['width']);
                $l[] = $p('og:image:height', (int) $page['image']['height']);
            }
            $l[] = $p('og:image:alt', $page['image']['alt']);
        }
        if (!empty($page['price'])) {
            $l[] = $p('product:price:amount', $page['price']['amount']);
            $l[] = $p('product:price:currency', $page['price']['currency']);
            $l[] = $p('product:pretax_price:amount', $page['price']['pretax_amount']);
            $l[] = $p('product:pretax_price:currency', $page['price']['currency']);
        }

        $l[] = $n('twitter:card', $page['image'] ? 'summary_large_image' : 'summary');
        if ($twitterSite !== '') {
            $l[] = $n('twitter:site', '@' . ltrim($twitterSite, '@'));
        }
        $l[] = $n('twitter:title', $page['title']);
        if ($page['description'] !== '') {
            $l[] = $n('twitter:description', $page['description']);
        }
        if ($page['image']) {
            $l[] = $n('twitter:image', $page['image']['url']);
            $l[] = $n('twitter:image:alt', $page['image']['alt']);
        }

        return "<!-- CoolShare -->\n" . implode("\n", $l) . "\n<!-- /CoolShare -->\n";
    }

    /**
     * Retire les balises de partage du <head> et met le bloc à la place. Rien d'autre
     * n'est touché : ni <title>, ni meta description, ni données structurées.
     *
     * @return string le HTML, inchangé s'il n'a pas de <head> complet
     */
    public static function replace($html, $bloc)
    {
        $fin = stripos($html, '</head>');
        if ($fin === false) {
            return $html;
        }
        $tete = preg_replace(self::PATTERN, '', substr($html, 0, $fin));
        if ($tete === null) {
            return $html;
        }

        return $tete . $bloc . substr($html, $fin);
    }

    /** Lit une balise du <head> : <title> ou une meta par son nom. */
    public static function readHead($html, $quoi)
    {
        $fin = stripos($html, '</head>');
        $tete = $fin === false ? $html : substr($html, 0, $fin);
        if ($quoi === 'title') {
            return preg_match('#<title[^>]*>(.*?)</title>#is', $tete, $m)
                ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') : '';
        }
        if ($quoi === 'canonical') {
            return preg_match('#<link\b[^>]*\brel\s*=\s*["\']canonical["\'][^>]*\bhref\s*=\s*["\']([^"\']+)["\']#i', $tete, $m)
                ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
        }

        return preg_match('#<meta\b[^>]*\bname\s*=\s*["\']' . preg_quote($quoi, '#') . '["\'][^>]*\bcontent\s*=\s*(["\'])(.*?)\1#is', $tete, $m)
            ? html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : '';
    }

    public static function e($valeur)
    {
        return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
    }
}
