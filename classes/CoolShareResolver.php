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
 * Les valeurs EFFECTIVES d'une page, telles que le front les écrira : la saisie de
 * partage d'abord, puis le SEO, puis une valeur calculée. Le front, le back-office et
 * CoolShareApi passent tous par ici — un seul calcul, donc un aperçu qui dit vrai.
 */
class CoolShareResolver
{
    const DESCRIPTION_MAX = 200;

    /** @var array cache des meilleurs types d'image, par famille */
    private static $types = [];

    /**
     * @return array|null null si l'objet n'existe pas
     */
    public static function page($entity, $idEntity, $idLang, $idShop)
    {
        $idEntity = (int) $idEntity;
        $idLang = (int) $idLang;
        $idShop = (int) $idShop;
        $link = Context::getContext()->link;
        $objet = null;
        $nom = '';
        $seoTitre = '';
        $seoDescription = '';
        $texte = '';
        $url = '';
        $image = null;
        $type = 'website';

        switch ($entity) {
            case 'index':
                $meta = Meta::getMetaByPage('index', $idLang);
                $nom = (string) Configuration::get('PS_SHOP_NAME', null, null, $idShop);
                $seoTitre = isset($meta['title']) ? (string) $meta['title'] : '';
                $seoDescription = isset($meta['description']) ? (string) $meta['description'] : '';
                $url = $link->getPageLink('index', null, $idLang, null, false, $idShop);
                break;
            case 'product':
                $objet = new Product($idEntity, false, $idLang, $idShop);
                if (!Validate::isLoadedObject($objet)) {
                    return null;
                }
                $nom = (string) $objet->name;
                $seoTitre = (string) $objet->meta_title;
                $seoDescription = (string) $objet->meta_description;
                $texte = (string) ($objet->description_short ?: $objet->description);
                $url = $link->getProductLink($objet, null, null, null, $idLang, $idShop);
                $type = 'product';
                $couverture = Product::getCover($idEntity);
                if ($couverture && !empty($couverture['id_image'])) {
                    $t = self::bestType('products');
                    if ($t) {
                        $image = [
                            'url' => $link->getImageLink((string) $objet->link_rewrite, (int) $couverture['id_image'], $t['name']),
                            'width' => $t['width'], 'height' => $t['height'],
                        ];
                    }
                }
                break;
            case 'category':
                $objet = new Category($idEntity, $idLang, $idShop);
                if (!Validate::isLoadedObject($objet)) {
                    return null;
                }
                $nom = (string) $objet->name;
                $seoTitre = (string) $objet->meta_title;
                $seoDescription = (string) $objet->meta_description;
                $texte = (string) $objet->description;
                $url = $link->getCategoryLink($objet, null, $idLang, null, $idShop);
                if (is_file(_PS_CAT_IMG_DIR_ . $idEntity . '.jpg')) {
                    $t = self::bestType('categories');
                    if ($t) {
                        $image = [
                            'url' => $link->getCatImageLink((string) $objet->link_rewrite, $idEntity, $t['name']),
                            'width' => $t['width'], 'height' => $t['height'],
                        ];
                    }
                }
                break;
            case 'cms':
                $objet = new CMS($idEntity, $idLang, $idShop);
                if (!Validate::isLoadedObject($objet)) {
                    return null;
                }
                // Sur une page CMS, meta_title est le titre de la page, head_seo_title son
                // titre SEO : le second passe devant.
                $nom = (string) $objet->meta_title;
                $seoTitre = isset($objet->head_seo_title) ? (string) $objet->head_seo_title : '';
                $seoDescription = (string) $objet->meta_description;
                $texte = (string) $objet->content;
                $url = $link->getCMSLink($objet, null, null, $idLang, $idShop);
                break;
            case 'manufacturer':
                $objet = new Manufacturer($idEntity, $idLang);
                if (!Validate::isLoadedObject($objet)) {
                    return null;
                }
                $nom = (string) $objet->name;
                $seoTitre = (string) $objet->meta_title;
                $seoDescription = (string) $objet->meta_description;
                $texte = (string) ($objet->short_description ?: $objet->description);
                $url = $link->getManufacturerLink($objet, null, $idLang, $idShop);
                if (is_file(_PS_MANU_IMG_DIR_ . $idEntity . '.jpg')) {
                    $t = self::bestType('manufacturers');
                    if ($t) {
                        $image = [
                            'url' => $link->getManufacturerImageLink($idEntity, $t['name']),
                            'width' => $t['width'], 'height' => $t['height'],
                        ];
                    }
                }
                break;
            default:
                throw new CoolShareException('unknown_entity');
        }

        $saisie = CoolShareRepository::texts($entity, $idEntity, $idLang, $idShop);
        $page = self::assemble([
            'type' => $type,
            'url' => $url,
            'name' => $nom,
            'seo_title' => $seoTitre,
            'seo_description' => $seoDescription,
            'text' => $texte,
            'share_title' => $saisie['title'],
            'share_description' => $saisie['description'],
            'share_image' => CoolShareRepository::image($entity, $idEntity, $idShop),
            'entity_image' => $image,
        ], $idLang, $idShop);

        if ($type === 'product') {
            $page['price'] = self::prices($idEntity);
        }

        return $page;
    }

    /**
     * Une page que le module n'édite pas (recherche, fournisseur, page de module…) : ses
     * titre et description, lus dans le HTML que le thème a produit, et l'image par défaut.
     */
    public static function otherPage($titre, $description, $url, $idLang, $idShop)
    {
        return self::assemble([
            'type' => 'website',
            'url' => $url,
            'name' => (string) Configuration::get('PS_SHOP_NAME', null, null, $idShop),
            'seo_title' => (string) $titre,
            'seo_description' => (string) $description,
            'text' => '',
            'share_title' => null,
            'share_description' => null,
            'share_image' => null,
            'entity_image' => null,
        ], $idLang, $idShop);
    }

    private static function assemble(array $d, $idLang, $idShop)
    {
        list($titre, $sourceTitre) = self::premier([
            ['share', $d['share_title']], ['seo', $d['seo_title']], ['auto', $d['name']],
        ]);
        list($description, $sourceDescription) = self::premier([
            ['share', $d['share_description']], ['seo', $d['seo_description']],
            ['auto', self::resume($d['text'])],
        ]);

        $image = null;
        $sourceImage = null;
        if (CoolShareImage::exists($d['share_image'])) {
            list($w, $h) = CoolShareImage::size($d['share_image']);
            $image = ['url' => CoolShareImage::url($d['share_image']), 'width' => $w, 'height' => $h];
            $sourceImage = 'share';
        } elseif ($d['entity_image']) {
            $image = $d['entity_image'];
            $sourceImage = 'entity';
        } else {
            $defaut = (string) Configuration::get('COOLSHARE_DEFAULT_IMAGE', null, null, $idShop);
            if (CoolShareImage::exists($defaut)) {
                list($w, $h) = CoolShareImage::size($defaut);
                $image = ['url' => CoolShareImage::url($defaut), 'width' => $w, 'height' => $h];
                $sourceImage = 'default';
            }
        }
        if ($image) {
            $image['alt'] = $titre;
        }

        return [
            'type' => $d['type'],
            'title' => $titre,
            'title_source' => $sourceTitre,
            'description' => $description,
            'description_source' => $sourceDescription,
            'image' => $image,
            'image_source' => $sourceImage,
            'url' => (string) $d['url'],
            'site_name' => (string) Configuration::get('PS_SHOP_NAME', null, null, $idShop),
            'locale' => self::locale($idLang),
            'locale_alternates' => self::alternates($idLang, $idShop),
        ];
    }

    /** La première valeur non vide, avec son origine. */
    private static function premier(array $candidats)
    {
        foreach ($candidats as $c) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) $c[1]));
            if ($v !== '') {
                return [$v, $c[0]];
            }
        }

        return ['', 'auto'];
    }

    /** Un texte de fiche en description de partage : sans HTML, coupé à un mot entier. */
    public static function resume($html)
    {
        $t = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', '</p> ', '</li> '], (string) $html)), ENT_QUOTES, 'UTF-8');
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if (Tools::strlen($t) <= self::DESCRIPTION_MAX) {
            return $t;
        }
        $coupe = Tools::substr($t, 0, self::DESCRIPTION_MAX - 1);
        $espace = strrpos($coupe, ' ');
        if ($espace !== false && $espace > self::DESCRIPTION_MAX / 2) {
            $coupe = substr($coupe, 0, $espace);
        }

        return rtrim($coupe, " ,;:.-") . '…';
    }

    /**
     * Le type d'image le mieux adapté au partage pour une famille : le plus petit qui fait
     * au moins 1200 px de large, sinon le plus grand.
     *
     * @return array|null ['name' => string, 'width' => int, 'height' => int]
     */
    public static function bestType($famille)
    {
        if (!array_key_exists($famille, self::$types)) {
            $types = ImageType::getImagesTypes($famille) ?: [];
            usort($types, function ($a, $b) {
                return (int) $a['width'] - (int) $b['width'];
            });
            $choix = null;
            foreach ($types as $t) {
                $choix = $t;
                if ((int) $t['width'] >= CoolShareImage::WIDTH) {
                    break;
                }
            }
            self::$types[$famille] = $choix
                ? ['name' => (string) $choix['name'], 'width' => (int) $choix['width'], 'height' => (int) $choix['height']]
                : null;
        }

        return self::$types[$famille];
    }

    private static function prices($idProduct)
    {
        $devise = Context::getContext()->currency;

        return [
            'amount' => number_format((float) Product::getPriceStatic((int) $idProduct, true), 2, '.', ''),
            'pretax_amount' => number_format((float) Product::getPriceStatic((int) $idProduct, false), 2, '.', ''),
            'currency' => $devise ? (string) $devise->iso_code : '',
        ];
    }

    /** « fr-FR » ou « fr » → « fr_FR », le format qu'attend og:locale. */
    public static function locale($idLang)
    {
        $langue = new Language((int) $idLang);
        $code = !empty($langue->locale) ? (string) $langue->locale : (string) $langue->language_code;
        $parties = preg_split('/[-_]/', $code);
        if (count($parties) >= 2) {
            return Tools::strtolower($parties[0]) . '_' . Tools::strtoupper($parties[1]);
        }

        return Tools::strtolower($parties[0]) . '_' . Tools::strtoupper($parties[0]);
    }

    private static function alternates($idLang, $idShop)
    {
        $autres = [];
        foreach (Language::getLanguages(true, (int) $idShop) as $l) {
            if ((int) $l['id_lang'] !== (int) $idLang) {
                $autres[] = self::locale((int) $l['id_lang']);
            }
        }

        return array_values(array_unique($autres));
    }
}
