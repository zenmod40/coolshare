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
 * Les images de partage : recadrées au format des réseaux (1200 × 630), rangées dans
 * `img/coolshare/` — hors du dossier du module, pour survivre à ses mises à jour.
 */
class CoolShareImage
{
    const WIDTH = 1200;
    const HEIGHT = 630;
    const MAX_BYTES = 8388608;
    /** Les images remplacées sont gardées ce temps-là, pour pouvoir revenir en arrière. */
    const KEEP_DAYS = 30;

    public static function dir()
    {
        return _PS_IMG_DIR_ . 'coolshare/';
    }

    /** L'adresse publique et absolue d'une image : c'est ce que lisent les réseaux. */
    public static function url($file)
    {
        if (!$file) {
            return '';
        }

        return Context::getContext()->link->getMediaLink(_PS_IMG_ . 'coolshare/' . rawurlencode($file));
    }

    public static function exists($file)
    {
        return $file && self::isSafeName($file) && is_file(self::dir() . $file);
    }

    /** Un nom produit par store() : rien d'autre ne doit pouvoir désigner un fichier. */
    public static function isSafeName($file)
    {
        return (bool) preg_match('/^[a-z]+-[0-9]+-[0-9]+-[a-f0-9]{12}\.jpg$/', (string) $file);
    }

    /**
     * Valide, recadre et range une image. Le nom est imprévisible : les réseaux gardent
     * une image en cache par adresse, une nouvelle adresse les oblige à la relire.
     *
     * @return string le nom du fichier rangé
     *
     * @throws CoolShareException bad_image | image_too_large | save_failed
     */
    public static function store($path, $entity, $idEntity, $idShop)
    {
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            throw new CoolShareException('bad_image');
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new CoolShareException('image_too_large');
        }
        if (!ImageManager::isRealImage($path) || !function_exists('imagecreatefromstring')) {
            throw new CoolShareException('bad_image');
        }
        $source = @imagecreatefromstring((string) Tools::file_get_contents($path));
        if (!$source) {
            throw new CoolShareException('bad_image');
        }

        $w = imagesx($source);
        $h = imagesy($source);
        $cible = self::WIDTH / self::HEIGHT;
        // Recadrage centré : on garde le plus grand rectangle au bon format, puis on le
        // ramène à 1200 × 630. Une image plus petite est agrandie — le contrôle le signale.
        if ($w / $h > $cible) {
            $cw = (int) round($h * $cible);
            $ch = $h;
            $x = (int) round(($w - $cw) / 2);
            $y = 0;
        } else {
            $cw = $w;
            $ch = (int) round($w / $cible);
            $x = 0;
            $y = (int) round(($h - $ch) / 2);
        }

        $sortie = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        // Fond blanc : un PNG transparent ne devient pas noir en JPEG.
        imagefill($sortie, 0, 0, imagecolorallocate($sortie, 255, 255, 255));
        imagecopyresampled($sortie, $source, 0, 0, $x, $y, self::WIDTH, self::HEIGHT, $cw, $ch);
        imagedestroy($source);

        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0755, true)) {
            imagedestroy($sortie);
            throw new CoolShareException('save_failed');
        }
        self::protectDir();
        $nom = preg_replace('/[^a-z]/', '', (string) $entity) . '-' . (int) $idEntity . '-' . (int) $idShop
            . '-' . bin2hex(random_bytes(6)) . '.jpg';
        $ok = imagejpeg($sortie, self::dir() . $nom, 88);
        imagedestroy($sortie);
        if (!$ok) {
            throw new CoolShareException('save_failed');
        }

        return $nom;
    }

    /** Taille réelle d'un fichier rangé : [largeur, hauteur], ou [0, 0]. */
    public static function size($file)
    {
        if (!self::exists($file)) {
            return [0, 0];
        }
        $s = @getimagesize(self::dir() . $file);

        return $s ? [(int) $s[0], (int) $s[1]] : [0, 0];
    }

    /**
     * Supprime les images qui ne servent plus et que plus aucune restauration n'attend :
     * non référencées ET plus vieilles que KEEP_DAYS.
     */
    public static function purge()
    {
        if (!is_dir(self::dir())) {
            return;
        }
        $gardees = array_flip(array_merge(CoolShareRepository::referencedImages(), self::configImages()));
        $limite = time() - self::KEEP_DAYS * 86400;
        foreach (glob(self::dir() . '*.jpg') ?: [] as $chemin) {
            $nom = basename($chemin);
            if (!isset($gardees[$nom]) && self::isSafeName($nom) && filemtime($chemin) < $limite) {
                @unlink($chemin);
            }
        }
    }

    /** Les images par défaut de chaque boutique, réglées dans la configuration. */
    public static function configImages()
    {
        $noms = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'COOLSHARE_DEFAULT_IMAGE\''
        ) ?: [] as $r) {
            if ($r['value']) {
                $noms[] = (string) $r['value'];
            }
        }

        return $noms;
    }

    /** Pas d'exécution de script dans le dossier des images, pas de liste de fichiers. */
    private static function protectDir()
    {
        if (!is_file(self::dir() . 'index.php')) {
            @file_put_contents(self::dir() . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }
        if (!is_file(self::dir() . '.htaccess')) {
            @file_put_contents(self::dir() . '.htaccess', "<FilesMatch \"\\.(?i:php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
        }
    }
}
