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
 * L'envoi d'image de l'ANCIENNE fiche produit (PrestaShop 8.0, ou 8.1-8.2 sans la nouvelle
 * fiche) : elle enregistre ses champs sans les fichiers, l'image part donc à part, dès
 * qu'on la choisit. Onglet caché, appelé en AJAX avec le jeton du back-office.
 */
class AdminCoolShareController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Le droit qui compte est celui de modifier les produits, pas celui de cet onglet caché
     * (qu'aucun profil ne se voit donner) : il est vérifié dans chaque action.
     */
    public function viewAccess($disable = false)
    {
        return true;
    }

    public function ajaxProcessUploadImage()
    {
        // Le jeton du back-office, vérifié ici même : on ne compte pas sur le cycle AJAX de
        // PrestaShop pour le faire à notre place.
        if (!$this->checkToken()) {
            $this->repondre(['ok' => false, 'error' => $this->module->l('Jeton de sécurité invalide : rechargez la page.')]);
        }
        $idProduct = (int) Tools::getValue('id_product');
        $employe = $this->context->employee;
        $autorise = $employe && ($employe->isSuperAdmin()
            || Access::isGranted('ROLE_MOD_TAB_ADMINPRODUCTS_UPDATE', (int) $employe->id_profile));
        if (!$autorise) {
            $this->repondre(['ok' => false, 'error' => $this->module->l('Vous n\'avez pas le droit de modifier les produits.')]);
        }
        if ($idProduct <= 0 || empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            $this->repondre(['ok' => false, 'error' => $this->module->messageErreur('bad_image')]);
        }

        try {
            $ids = Shop::isFeatureActive() ? Shop::getContextListShopID() : [(int) $this->context->shop->id];
            $image = null;
            foreach (array_filter(array_map('intval', (array) $ids)) as $idShop) {
                $r = CoolShareApi::setImage('product', $idProduct, $idShop, $_FILES['image']['tmp_name'], (int) $employe->id);
                $image = $r['image'];
            }
            $this->repondre(['ok' => true, 'image' => $image]);
        } catch (CoolShareException $e) {
            $this->repondre(['ok' => false, 'error' => $this->module->messageErreur($e->getMessage())]);
        }
    }

    private function repondre(array $donnees)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($donnees);
        exit;
    }
}
