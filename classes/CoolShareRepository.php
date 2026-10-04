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
 * La table des valeurs saisies. Une ligne par page, langue et boutique pour les textes ;
 * l'image, commune à toutes les langues, vit sur la ligne `id_lang = 0`.
 * Une colonne NULL veut dire « rien de saisi » : on prend alors la valeur suivante
 * (SEO, puis automatique) — jamais une chaîne vide qui masquerait la suite.
 */
class CoolShareRepository
{
    /** Les pages qu'on édite. Les autres reçoivent les valeurs par défaut. */
    const ENTITIES = ['index', 'product', 'category', 'cms', 'manufacturer'];

    public static function table()
    {
        return _DB_PREFIX_ . 'coolshare';
    }

    public static function install()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
                `id_coolshare` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity` VARCHAR(16) NOT NULL,
                `id_entity` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_lang` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_shop` INT UNSIGNED NOT NULL,
                `title` VARCHAR(255) NULL,
                `description` VARCHAR(512) NULL,
                `image` VARCHAR(128) NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_coolshare`),
                UNIQUE KEY `cible` (`entity`, `id_entity`, `id_lang`, `id_shop`),
                KEY `page` (`entity`, `id_entity`, `id_shop`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function uninstall()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table() . '`');
    }

    public static function isEntity($entity)
    {
        return in_array($entity, self::ENTITIES, true);
    }

    /** @return array ['title' => ?string, 'description' => ?string] */
    public static function texts($entity, $idEntity, $idLang, $idShop)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `title`, `description` FROM `' . self::table() . '`'
            . ' WHERE `entity` = \'' . pSQL($entity) . '\' AND `id_entity` = ' . (int) $idEntity
            . ' AND `id_lang` = ' . (int) $idLang . ' AND `id_shop` = ' . (int) $idShop
        );

        return [
            'title' => $row && $row['title'] !== null ? (string) $row['title'] : null,
            'description' => $row && $row['description'] !== null ? (string) $row['description'] : null,
        ];
    }

    /** Le nom du fichier de l'image de partage, ou null. */
    public static function image($entity, $idEntity, $idShop)
    {
        $file = Db::getInstance()->getValue(
            'SELECT `image` FROM `' . self::table() . '`'
            . ' WHERE `entity` = \'' . pSQL($entity) . '\' AND `id_entity` = ' . (int) $idEntity
            . ' AND `id_lang` = 0 AND `id_shop` = ' . (int) $idShop
        );

        return $file ? (string) $file : null;
    }

    /**
     * Enregistre titre et/ou description. Une clé absente n'est pas touchée ; null ou ''
     * efface la saisie.
     */
    public static function saveTexts($entity, $idEntity, $idLang, $idShop, array $fields)
    {
        $actuel = self::texts($entity, $idEntity, $idLang, $idShop);
        foreach (['title', 'description'] as $k) {
            if (array_key_exists($k, $fields)) {
                $actuel[$k] = ($fields[$k] === null || $fields[$k] === '') ? null : (string) $fields[$k];
            }
        }

        return self::upsert($entity, $idEntity, (int) $idLang, $idShop, [
            'title' => $actuel['title'],
            'description' => $actuel['description'],
        ]);
    }

    public static function saveImage($entity, $idEntity, $idShop, $file)
    {
        return self::upsert($entity, $idEntity, 0, $idShop, ['image' => $file ?: null]);
    }

    private static function upsert($entity, $idEntity, $idLang, $idShop, array $valeurs)
    {
        $sql = function ($v) {
            return $v === null ? 'NULL' : '\'' . pSQL($v, false) . '\'';
        };
        $colonnes = ['`entity`', '`id_entity`', '`id_lang`', '`id_shop`', '`date_upd`'];
        $donnees = ['\'' . pSQL($entity) . '\'', (int) $idEntity, (int) $idLang, (int) $idShop, 'NOW()'];
        $maj = ['`date_upd` = NOW()'];
        foreach ($valeurs as $col => $v) {
            $colonnes[] = '`' . bqSQL($col) . '`';
            $donnees[] = $sql($v);
            $maj[] = '`' . bqSQL($col) . '` = ' . $sql($v);
        }

        $ok = Db::getInstance()->execute(
            'INSERT INTO `' . self::table() . '` (' . implode(', ', $colonnes) . ')'
            . ' VALUES (' . implode(', ', $donnees) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $maj)
        );
        // Une ligne vidée de tout ne sert plus : on la retire, la table reste le reflet
        // de ce qui a vraiment été saisi.
        Db::getInstance()->execute(
            'DELETE FROM `' . self::table() . '` WHERE `entity` = \'' . pSQL($entity) . '\''
            . ' AND `id_entity` = ' . (int) $idEntity . ' AND `id_lang` = ' . (int) $idLang
            . ' AND `id_shop` = ' . (int) $idShop
            . ' AND `title` IS NULL AND `description` IS NULL AND `image` IS NULL'
        );

        return (bool) $ok;
    }

    /**
     * Efface tout ce qui concerne une page supprimée (toutes langues, toutes boutiques).
     *
     * @return string[] les fichiers d'image qui ne servent plus
     */
    public static function deleteEntity($entity, $idEntity)
    {
        $fichiers = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `image` FROM `' . self::table() . '` WHERE `entity` = \'' . pSQL($entity) . '\''
            . ' AND `id_entity` = ' . (int) $idEntity . ' AND `image` IS NOT NULL'
        ) ?: [] as $r) {
            $fichiers[] = (string) $r['image'];
        }
        Db::getInstance()->execute(
            'DELETE FROM `' . self::table() . '` WHERE `entity` = \'' . pSQL($entity) . '\''
            . ' AND `id_entity` = ' . (int) $idEntity
        );

        return $fichiers;
    }

    /** @return string[] toutes les images encore référencées */
    public static function referencedImages()
    {
        return array_map('strval', array_column(Db::getInstance()->executeS(
            'SELECT DISTINCT `image` FROM `' . self::table() . '` WHERE `image` IS NOT NULL'
        ) ?: [], 'image'));
    }
}
