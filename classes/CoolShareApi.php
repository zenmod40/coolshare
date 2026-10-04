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
 * Le contrat public de CoolShare. Le back-office du module l'appelle, Régie aussi, et
 * n'importe quel module peut s'en servir : il ne dépend d'aucun employé connecté (il est
 * passé en paramètre), ni du back-office.
 *
 * Entités : 'index' (avec $idEntity = 0), 'product', 'category', 'cms', 'manufacturer'.
 * Erreurs : CoolShareException, dont le message est un code stable (voir sa classe).
 */
class CoolShareApi
{
    /** Incrémentée à chaque changement de ce contrat. */
    const API_VERSION = 1;

    /** Longueurs au-delà desquelles le contrôle signale un texte trop long. */
    const TITLE_MAX = 95;
    const DESCRIPTION_MAX = 200;

    /** Les valeurs effectives d'une page et leur origine (voir CoolShareResolver). */
    public static function get($entity, $idEntity, $idLang, $idShop)
    {
        self::entity($entity, $idEntity);
        $page = CoolShareResolver::page($entity, $idEntity, $idLang, $idShop);
        if ($page === null) {
            throw new CoolShareException('not_found');
        }

        return $page;
    }

    /**
     * Titre et/ou description de partage. Clé absente : pas touchée ; '' : effacée.
     *
     * @return array ['previous' => ['title' => ?string, 'description' => ?string]]
     */
    public static function set($entity, $idEntity, $idLang, $idShop, array $fields, $idEmployee = 0)
    {
        self::entity($entity, $idEntity);
        self::exists($entity, $idEntity, $idLang, $idShop);
        $avant = CoolShareRepository::texts($entity, $idEntity, $idLang, $idShop);
        $propres = [];
        foreach (['title' => 255, 'description' => 512] as $k => $max) {
            if (array_key_exists($k, $fields)) {
                $propres[$k] = self::clean($fields[$k], $max);
            }
        }
        if (!CoolShareRepository::saveTexts($entity, $idEntity, $idLang, $idShop, $propres)) {
            throw new CoolShareException('save_failed');
        }

        return ['previous' => $avant];
    }

    /**
     * Remplace l'image de partage par ce fichier, recadré en 1200 × 630. L'ancienne est
     * gardée (KEEP_DAYS jours) : son nom, rendu dans `previous`, la fait revenir.
     *
     * @return array ['image' => array, 'previous' => ?string]
     */
    public static function setImage($entity, $idEntity, $idShop, $cheminFichier, $idEmployee = 0)
    {
        self::entity($entity, $idEntity);
        self::exists($entity, $idEntity, (int) Configuration::get('PS_LANG_DEFAULT'), $idShop);
        $avant = CoolShareRepository::image($entity, $idEntity, $idShop);
        $nom = CoolShareImage::store($cheminFichier, $entity, $idEntity, $idShop);
        if (!CoolShareRepository::saveImage($entity, $idEntity, $idShop, $nom)) {
            throw new CoolShareException('save_failed');
        }
        CoolShareImage::purge();
        list($w, $h) = CoolShareImage::size($nom);

        return [
            'image' => ['url' => CoolShareImage::url($nom), 'width' => $w, 'height' => $h],
            'previous' => $avant,
        ];
    }

    /** @return array ['previous' => ?string] */
    public static function clearImage($entity, $idEntity, $idShop, $idEmployee = 0)
    {
        self::entity($entity, $idEntity);
        $avant = CoolShareRepository::image($entity, $idEntity, $idShop);
        if (!CoolShareRepository::saveImage($entity, $idEntity, $idShop, null)) {
            throw new CoolShareException('save_failed');
        }

        return ['previous' => $avant];
    }

    /** Remet l'image désignée par un `previous` (null : aucune image de partage). */
    public static function restoreImage($entity, $idEntity, $idShop, $jeton, $idEmployee = 0)
    {
        self::entity($entity, $idEntity);
        if ($jeton !== null && $jeton !== '' && !CoolShareImage::exists($jeton)) {
            throw new CoolShareException('bad_restore_token');
        }
        if (!CoolShareRepository::saveImage($entity, $idEntity, $idShop, $jeton ?: null)) {
            throw new CoolShareException('save_failed');
        }

        return ['previous' => null];
    }

    /**
     * Le contrôle de qualité.
     *
     * @param array $filtres ['entity' => ?string, 'problem' => ?string, 'page' => int, 'per_page' => int]
     *
     * @return array ['total' => int, 'rows' => [['entity','id_entity','name','problems' => string[]]]]
     */
    public static function audit($idShop, $idLang, array $filtres = [])
    {
        $entites = !empty($filtres['entity']) ? [(string) $filtres['entity']] : ['index', 'product', 'category', 'cms', 'manufacturer'];
        foreach ($entites as $e) {
            if (!CoolShareRepository::isEntity($e)) {
                throw new CoolShareException('unknown_entity');
            }
        }
        $probleme = isset($filtres['problem']) ? (string) $filtres['problem'] : '';
        $parPage = max(1, min(200, isset($filtres['per_page']) ? (int) $filtres['per_page'] : 50));
        $page = max(1, isset($filtres['page']) ? (int) $filtres['page'] : 1);

        $lignes = [];
        foreach ($entites as $e) {
            foreach (self::auditRows($e, (int) $idShop, (int) $idLang) as $r) {
                $lignes[] = $r;
            }
        }

        // Titres en double : comparés entre toutes les pages de la langue.
        $vus = [];
        foreach ($lignes as $r) {
            $cle = Tools::strtolower($r['title']);
            $vus[$cle] = isset($vus[$cle]) ? $vus[$cle] + 1 : 1;
        }
        $sortie = [];
        foreach ($lignes as $r) {
            if ($r['title'] !== '' && $vus[Tools::strtolower($r['title'])] > 1) {
                $r['problems'][] = 'duplicate_title';
            }
            if (!$r['problems'] || ($probleme !== '' && !in_array($probleme, $r['problems'], true))) {
                continue;
            }
            unset($r['title']);
            $sortie[] = $r;
        }

        return [
            'total' => count($sortie),
            'rows' => array_slice($sortie, ($page - 1) * $parPage, $parPage),
        ];
    }

    /**
     * Les pages d'un type, avec leurs problèmes (sauf les doublons, calculés à part). Une
     * requête par type : le titre effectif se calcule en SQL comme dans le résolveur.
     */
    private static function auditRows($entity, $idShop, $idLang)
    {
        $db = Db::getInstance();
        $cs = 'LEFT JOIN `' . CoolShareRepository::table() . '` cs ON cs.`entity` = \'' . pSQL($entity)
            . '\' AND cs.`id_entity` = %s AND cs.`id_lang` = ' . $idLang . ' AND cs.`id_shop` = ' . $idShop
            . ' LEFT JOIN `' . CoolShareRepository::table() . '` ci ON ci.`entity` = \'' . pSQL($entity)
            . '\' AND ci.`id_entity` = %s AND ci.`id_lang` = 0 AND ci.`id_shop` = ' . $idShop;
        $defaut = CoolShareImage::exists((string) Configuration::get('COOLSHARE_DEFAULT_IMAGE', null, null, $idShop));

        switch ($entity) {
            case 'index':
                $meta = Meta::getMetaByPage('index', $idLang);
                $saisie = CoolShareRepository::texts('index', 0, $idLang, $idShop);
                $rows = [[
                    'id' => 0, 'name' => (string) Configuration::get('PS_SHOP_NAME', null, null, $idShop),
                    'share_title' => $saisie['title'], 'seo_title' => isset($meta['title']) ? $meta['title'] : '',
                    'share_description' => $saisie['description'],
                    'seo_description' => isset($meta['description']) ? $meta['description'] : '',
                    'text' => '', 'share_image' => CoolShareRepository::image('index', 0, $idShop), 'entity_image' => 0,
                ]];
                $famille = null;
                break;
            case 'product':
                $rows = $db->executeS(
                    'SELECT p.`id_product` AS id, pl.`name`, cs.`title` AS share_title, pl.`meta_title` AS seo_title,'
                    . ' cs.`description` AS share_description, pl.`meta_description` AS seo_description,'
                    . ' LEFT(IF(pl.`description_short` <> \'\', pl.`description_short`, pl.`description`), 1500) AS text,'
                    . ' ci.`image` AS share_image,'
                    . ' EXISTS(SELECT 1 FROM `' . _DB_PREFIX_ . 'image_shop` ims WHERE ims.`id_product` = p.`id_product`'
                    . '   AND ims.`id_shop` = ' . $idShop . ' AND ims.`cover` = 1) AS entity_image'
                    . ' FROM `' . _DB_PREFIX_ . 'product_shop` p'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product`'
                    . '   AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop
                    . ' ' . sprintf($cs, 'p.`id_product`', 'p.`id_product`')
                    . ' WHERE p.`id_shop` = ' . $idShop . ' AND p.`active` = 1'
                ) ?: [];
                $famille = 'products';
                break;
            case 'category':
                $rows = $db->executeS(
                    'SELECT c.`id_category` AS id, cl.`name`, cs.`title` AS share_title, cl.`meta_title` AS seo_title,'
                    . ' cs.`description` AS share_description, cl.`meta_description` AS seo_description,'
                    . ' LEFT(cl.`description`, 1500) AS text, ci.`image` AS share_image, 0 AS entity_image'
                    . ' FROM `' . _DB_PREFIX_ . 'category` c'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs0 ON cs0.`id_category` = c.`id_category` AND cs0.`id_shop` = ' . $idShop
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category`'
                    . '   AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop
                    . ' ' . sprintf($cs, 'c.`id_category`', 'c.`id_category`')
                    . ' WHERE c.`active` = 1 AND c.`is_root_category` = 0 AND c.`id_parent` > 0'
                ) ?: [];
                foreach ($rows as &$r) {
                    $r['entity_image'] = is_file(_PS_CAT_IMG_DIR_ . (int) $r['id'] . '.jpg') ? 1 : 0;
                }
                unset($r);
                $famille = 'categories';
                break;
            case 'cms':
                $rows = $db->executeS(
                    'SELECT c.`id_cms` AS id, cl.`meta_title` AS name, cs.`title` AS share_title,'
                    . ' cl.`head_seo_title` AS seo_title, cs.`description` AS share_description,'
                    . ' cl.`meta_description` AS seo_description, LEFT(cl.`content`, 1500) AS text,'
                    . ' ci.`image` AS share_image, 0 AS entity_image'
                    . ' FROM `' . _DB_PREFIX_ . 'cms` c'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs0 ON cs0.`id_cms` = c.`id_cms` AND cs0.`id_shop` = ' . $idShop
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'cms_lang` cl ON cl.`id_cms` = c.`id_cms`'
                    . '   AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop
                    . ' ' . sprintf($cs, 'c.`id_cms`', 'c.`id_cms`')
                    . ' WHERE c.`active` = 1'
                ) ?: [];
                $famille = null;
                break;
            default:
                $rows = $db->executeS(
                    'SELECT m.`id_manufacturer` AS id, m.`name`, cs.`title` AS share_title, ml.`meta_title` AS seo_title,'
                    . ' cs.`description` AS share_description, ml.`meta_description` AS seo_description,'
                    . ' LEFT(IF(ml.`short_description` <> \'\', ml.`short_description`, ml.`description`), 1500) AS text,'
                    . ' ci.`image` AS share_image, 0 AS entity_image'
                    . ' FROM `' . _DB_PREFIX_ . 'manufacturer` m'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'manufacturer_shop` ms ON ms.`id_manufacturer` = m.`id_manufacturer` AND ms.`id_shop` = ' . $idShop
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer_lang` ml ON ml.`id_manufacturer` = m.`id_manufacturer` AND ml.`id_lang` = ' . $idLang
                    . ' ' . sprintf($cs, 'm.`id_manufacturer`', 'm.`id_manufacturer`')
                    . ' WHERE m.`active` = 1'
                ) ?: [];
                foreach ($rows as &$r) {
                    $r['entity_image'] = is_file(_PS_MANU_IMG_DIR_ . (int) $r['id'] . '.jpg') ? 1 : 0;
                }
                unset($r);
                $famille = 'manufacturers';
        }

        $type = $famille ? CoolShareResolver::bestType($famille) : null;
        $petite = $type && ((int) $type['width'] < CoolShareImage::WIDTH || (int) $type['height'] < CoolShareImage::HEIGHT);

        $sortie = [];
        foreach ($rows as $r) {
            $titre = self::firstText([$r['share_title'], $r['seo_title'], $r['name']]);
            $saisieDescription = self::firstText([$r['share_description'], $r['seo_description']]);
            $description = $saisieDescription !== '' ? $saisieDescription : CoolShareResolver::resume($r['text']);
            $problemes = [];

            $partage = CoolShareImage::exists($r['share_image']);
            if (!$partage && !(int) $r['entity_image'] && !$defaut) {
                $problemes[] = 'no_image';
            } elseif (!$partage && (int) $r['entity_image'] && $petite) {
                $problemes[] = 'image_too_small';
            }
            if ($description === '') {
                $problemes[] = 'no_description';
            } elseif (Tools::strlen($saisieDescription) > self::DESCRIPTION_MAX) {
                $problemes[] = 'description_too_long';
            }
            if (Tools::strlen($titre) > self::TITLE_MAX) {
                $problemes[] = 'title_too_long';
            }

            $sortie[] = [
                'entity' => $entity,
                'id_entity' => (int) $r['id'],
                'name' => (string) $r['name'],
                'title' => $titre,
                'problems' => $problemes,
            ];
        }

        return $sortie;
    }

    private static function firstText(array $valeurs)
    {
        foreach ($valeurs as $v) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    private static function entity($entity, $idEntity)
    {
        if (!CoolShareRepository::isEntity($entity) || ($entity === 'index') !== ((int) $idEntity === 0)) {
            throw new CoolShareException('unknown_entity');
        }
    }

    private static function exists($entity, $idEntity, $idLang, $idShop)
    {
        if ($entity !== 'index' && CoolShareResolver::page($entity, $idEntity, $idLang, $idShop) === null) {
            throw new CoolShareException('not_found');
        }
    }

    /** Texte brut d'une ligne : sans HTML, espaces normalisés, coupé à la taille de la colonne. */
    private static function clean($valeur, $max)
    {
        if ($valeur === null) {
            return null;
        }
        $t = html_entity_decode(strip_tags((string) $valeur), ENT_QUOTES, 'UTF-8');
        $t = trim(preg_replace('/\s+/u', ' ', $t));

        return Tools::substr($t, 0, $max);
    }
}
