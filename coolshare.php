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

require_once __DIR__ . '/classes/CoolShareException.php';
require_once __DIR__ . '/classes/CoolShareRepository.php';
require_once __DIR__ . '/classes/CoolShareImage.php';
require_once __DIR__ . '/classes/CoolShareResolver.php';
require_once __DIR__ . '/classes/CoolShareRenderer.php';
require_once __DIR__ . '/classes/CoolShareApi.php';
require_once __DIR__ . '/lib/zm40/Zm40Common.php';

class CoolShare extends Module
{
    /** Les formulaires du back-office qui reçoivent le bloc « Partage ». */
    const FORMS = [
        'product' => ['entity' => 'product', 'child' => 'seo'],
        'category' => ['entity' => 'category', 'child' => null],
        'cms_page' => ['entity' => 'cms', 'child' => null],
        'manufacturer' => ['entity' => 'manufacturer', 'child' => null],
    ];

    const HOOKS = [
        'actionOutputHTMLBefore',
        'displayHeader',
        'displayBackOfficeHeader',
        'actionProductFormBuilderModifier',
        'actionAfterCreateProductFormHandler',
        'actionAfterUpdateProductFormHandler',
        'actionCategoryFormBuilderModifier',
        'actionAfterCreateCategoryFormHandler',
        'actionAfterUpdateCategoryFormHandler',
        'actionCmsPageFormBuilderModifier',
        'actionAfterCreateCmsPageFormHandler',
        'actionAfterUpdateCmsPageFormHandler',
        'actionManufacturerFormBuilderModifier',
        'actionAfterCreateManufacturerFormHandler',
        'actionAfterUpdateManufacturerFormHandler',
        'actionObjectProductDeleteAfter',
        'actionObjectCategoryDeleteAfter',
        'actionObjectCmsDeleteAfter',
        'actionObjectManufacturerDeleteAfter',
        // L'ancienne fiche produit (8.0, ou 8.1-8.2 sans la nouvelle fiche).
        'displayAdminProductsSeoStepBottom',
        'actionProductUpdate',
    ];

    public function __construct()
    {
        $this->name = 'coolshare';
        $this->tab = 'seo';
        $this->version = '1.0.0';
        $this->author = 'ZM40';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = 'CoolShare';
        $this->description = $this->l('Balises de partage pour Facebook, LinkedIn, WhatsApp et X : un seul jeu de balises propre sur chaque page, une image au bon format, et un contrôle de qualité.');
        $this->confirmUninstall = $this->l('Les titres, descriptions et images de partage saisis seront supprimés. Continuer ?');
    }

    public function install()
    {
        if (!parent::install() || !CoolShareRepository::install() || !$this->registerHook(self::HOOKS)
            || !$this->installerOnglet()) {
            return false;
        }
        Configuration::updateGlobalValue('COOLSHARE_ENABLED', 1);
        Configuration::updateGlobalValue('COOLSHARE_MODE', 'replace');
        Configuration::updateGlobalValue('COOLSHARE_TWITTER', '');
        Configuration::updateValue('ZM40_NET_ENABLED', 1);

        return true;
    }

    public function uninstall()
    {
        foreach (array_merge(CoolShareRepository::referencedImages(), CoolShareImage::configImages()) as $f) {
            if (CoolShareImage::isSafeName($f)) {
                @unlink(CoolShareImage::dir() . $f);
            }
        }
        foreach (glob(CoolShareImage::dir() . '*.jpg') ?: [] as $f) {
            if (CoolShareImage::isSafeName(basename($f))) {
                @unlink($f);
            }
        }
        foreach (['COOLSHARE_ENABLED', 'COOLSHARE_MODE', 'COOLSHARE_TWITTER', 'COOLSHARE_DEFAULT_IMAGE', 'COOLSHARE_SEEN',
            'ZM40_LASTCHECK_COOLSHARE', 'ZM40_LATEST_COOLSHARE'] as $cle) {
            Configuration::deleteByName($cle);
        }

        $idOnglet = (int) Tab::getIdFromClassName('AdminCoolShare');
        if ($idOnglet) {
            (new Tab($idOnglet))->delete();
        }

        return CoolShareRepository::uninstall() && parent::uninstall();
    }

    /** L'onglet caché qui reçoit les images de l'ancienne fiche produit. */
    private function installerOnglet()
    {
        if (Tab::getIdFromClassName('AdminCoolShare')) {
            return true;
        }
        $onglet = new Tab();
        $onglet->class_name = 'AdminCoolShare';
        $onglet->module = $this->name;
        $onglet->id_parent = -1;
        $onglet->active = 1;
        foreach (Language::getLanguages(false) as $l) {
            $onglet->name[(int) $l['id_lang']] = 'CoolShare';
        }

        return (bool) $onglet->add();
    }

    /** La nouvelle fiche produit est-elle celle du back-office ? (toujours en 9, sur option en 8.1-8.2) */
    public function nouvelleFicheProduit()
    {
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            return true;
        }
        $table = _DB_PREFIX_ . 'feature_flag';
        if (!Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL($table) . '\'')) {
            return false;
        }

        return (bool) Db::getInstance()->getValue(
            'SELECT `state` FROM `' . $table . '` WHERE `name` = \'product_page_v2\''
        );
    }

    // -- Vitrine -----------------------------------------------------------------------

    /**
     * Mode « remplacer » : les balises du thème sont retirées du <head> et un jeu complet
     * prend leur place. Jamais deux og:title sur une page.
     */
    public function hookActionOutputHTMLBefore($params)
    {
        if (!isset($params['html']) || !is_string($params['html']) || !$this->actif('replace')) {
            return;
        }
        $page = $this->pageCourante($params['html']);
        if ($page === null) {
            return;
        }
        $params['html'] = CoolShareRenderer::replace($params['html'], CoolShareRenderer::tags($page, $this->twitter()));
        // La configuration montre si le remplacement a bien lieu : un cache de page
        // complète peut le court-circuiter sans que rien ne le dise.
        if ((int) Configuration::get('COOLSHARE_SEEN') < time() - 3600) {
            Configuration::updateGlobalValue('COOLSHARE_SEEN', time());
        }
    }

    /**
     * Mode « compléter » : rien n'est retiré, on n'ajoute que ce que le thème n'a pas
     * (l'image et la carte X), pour les boutiques où le remplacement est impossible.
     */
    public function hookDisplayHeader($params)
    {
        if (!$this->actif('complete')) {
            return '';
        }
        $page = $this->pageCourante('');
        if ($page === null) {
            return '';
        }
        $bloc = CoolShareRenderer::tags($page, $this->twitter());
        $garder = [];
        foreach (preg_split('/\R/', $bloc) as $ligne) {
            if (strpos($ligne, 'og:image') !== false || strpos($ligne, 'twitter:') !== false) {
                $garder[] = $ligne;
            }
        }

        return $garder ? "<!-- CoolShare -->\n" . implode("\n", $garder) . "\n<!-- /CoolShare -->\n" : '';
    }

    private function actif($mode)
    {
        return (int) Configuration::get('COOLSHARE_ENABLED')
            && (Configuration::get('COOLSHARE_MODE') ?: 'replace') === $mode
            && isset($this->context->controller)
            && $this->context->controller instanceof FrontController
            && !Tools::getValue('ajax');
    }

    private function twitter()
    {
        return trim((string) Configuration::get('COOLSHARE_TWITTER'));
    }

    /** La page affichée, résolue ; les pages que le module n'édite pas prennent les valeurs par défaut. */
    private function pageCourante($html)
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $self = isset($this->context->controller->php_self) ? (string) $this->context->controller->php_self : '';
        $cibles = [
            'index' => ['index', null],
            'product' => ['product', 'id_product'],
            'category' => ['category', 'id_category'],
            'cms' => ['cms', 'id_cms'],
            'manufacturer' => ['manufacturer', 'id_manufacturer'],
        ];
        try {
            if (isset($cibles[$self])) {
                list($entity, $param) = $cibles[$self];
                $id = $param ? (int) Tools::getValue($param) : 0;
                if (!$param || $id > 0) {
                    $page = CoolShareResolver::page($entity, $id, $idLang, $idShop);
                    if ($page !== null) {
                        return $page;
                    }
                }
            }
            if ($html === '') {
                return null;
            }
            $url = CoolShareRenderer::readHead($html, 'canonical');

            return CoolShareResolver::otherPage(
                CoolShareRenderer::readHead($html, 'title'),
                CoolShareRenderer::readHead($html, 'description'),
                $url !== '' ? $url : Tools::getShopDomainSsl(true) . $_SERVER['REQUEST_URI'],
                $idLang,
                $idShop
            );
        } catch (Throwable $e) {
            // Une page de vitrine ne doit jamais tomber à cause des balises de partage.
            return null;
        }
    }

    // -- Formulaires du back-office ------------------------------------------------------

    public function hookActionProductFormBuilderModifier(array $params)
    {
        $this->ajouterBloc('product', $params);
    }

    public function hookActionCategoryFormBuilderModifier(array $params)
    {
        $this->ajouterBloc('category', $params);
    }

    public function hookActionCmsPageFormBuilderModifier(array $params)
    {
        $this->ajouterBloc('cms_page', $params);
    }

    public function hookActionManufacturerFormBuilderModifier(array $params)
    {
        $this->ajouterBloc('manufacturer', $params);
    }

    public function hookActionAfterCreateProductFormHandler(array $params)
    {
        $this->enregistrerBloc('product', $params);
    }

    public function hookActionAfterUpdateProductFormHandler(array $params)
    {
        $this->enregistrerBloc('product', $params);
    }

    public function hookActionAfterCreateCategoryFormHandler(array $params)
    {
        $this->enregistrerBloc('category', $params);
    }

    public function hookActionAfterUpdateCategoryFormHandler(array $params)
    {
        $this->enregistrerBloc('category', $params);
    }

    public function hookActionAfterCreateCmsPageFormHandler(array $params)
    {
        $this->enregistrerBloc('cms_page', $params);
    }

    public function hookActionAfterUpdateCmsPageFormHandler(array $params)
    {
        $this->enregistrerBloc('cms_page', $params);
    }

    public function hookActionAfterCreateManufacturerFormHandler(array $params)
    {
        $this->enregistrerBloc('manufacturer', $params);
    }

    public function hookActionAfterUpdateManufacturerFormHandler(array $params)
    {
        $this->enregistrerBloc('manufacturer', $params);
    }

    /**
     * Le bloc « Partage » dans un formulaire de PrestaShop : titre et description par
     * langue, image, retrait de l'image. Pour un produit, dans l'onglet SEO.
     */
    private function ajouterBloc($form, array $params)
    {
        if (!isset($params['form_builder']) || !isset(self::FORMS[$form])) {
            return;
        }
        $def = self::FORMS[$form];
        $builder = $params['form_builder'];
        if ($def['child'] !== null) {
            if (!$builder->has($def['child'])) {
                return;
            }
            $builder = $builder->get($def['child']);
        }
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $idShop = (int) $this->context->shop->id ?: (int) Configuration::get('PS_SHOP_DEFAULT');
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $titres = [];
        $descriptions = [];
        foreach (Language::getLanguages(false) as $l) {
            $t = $id ? CoolShareRepository::texts($def['entity'], $id, (int) $l['id_lang'], $idShop) : ['title' => null, 'description' => null];
            $titres[(int) $l['id_lang']] = (string) $t['title'];
            $descriptions[(int) $l['id_lang']] = (string) $t['description'];
        }

        // Ce que la vitrine écrira si les champs restent vides, et l'image actuelle : pour
        // l'aperçu du formulaire, qui doit montrer la même chose que la vitrine.
        $apercu = ['entity' => $def['entity'], 'domain' => Tools::getShopDomainSsl(false)];
        if ($id) {
            try {
                $page = CoolShareResolver::page($def['entity'], $id, $idLang, $idShop);
                if ($page) {
                    $apercu['url'] = $page['url'];
                    $apercu['image'] = $page['image'] ? $page['image']['url'] : '';
                    $apercu['image_source'] = $page['image_source'];
                    $apercu['share_image'] = CoolShareImage::url(CoolShareRepository::image($def['entity'], $id, $idShop));
                    $apercu['title'] = $page['title_source'] === 'share' ? '' : $page['title'];
                    $apercu['description'] = $page['description_source'] === 'share' ? '' : $page['description'];
                }
            } catch (Throwable $e) {
                // L'aperçu est un confort : sans lui, le formulaire reste utilisable.
            }
        }

        $translatable = 'PrestaShopBundle\Form\Admin\Type\TranslatableType';
        $builder
            ->add('coolshare_title', $translatable, [
                'type' => 'Symfony\Component\Form\Extension\Core\Type\TextType',
                'label' => $this->l('Titre de partage'),
                'help' => $this->l('Ce que Facebook, LinkedIn ou WhatsApp affichent en titre. Vide : le titre SEO, sinon le nom.'),
                'required' => false,
                // Les valeurs passent par l'option `data` : PrestaShop a déjà posé les données
                // du formulaire quand ce crochet s'exécute.
                'data' => $titres,
                'options' => ['attr' => ['maxlength' => 255, 'class' => 'coolshare-title']],
            ])
            ->add('coolshare_description', $translatable, [
                'type' => 'Symfony\Component\Form\Extension\Core\Type\TextareaType',
                'label' => $this->l('Description de partage'),
                'help' => $this->l('Vide : la description SEO, sinon le début du texte de la page.'),
                'required' => false,
                'data' => $descriptions,
                'options' => ['attr' => ['maxlength' => 512, 'rows' => 3, 'class' => 'coolshare-description']],
            ])
            ->add('coolshare_image', 'Symfony\Component\Form\Extension\Core\Type\FileType', [
                'label' => $this->l('Image de partage'),
                'help' => $this->l('JPEG, PNG ou WebP, 8 Mo au plus. Recadrée en 1200 × 630, le format des réseaux sociaux.'),
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp', 'class' => 'coolshare-image',
                    'data-coolshare' => json_encode($apercu)],
            ])
            ->add('coolshare_image_remove', 'Symfony\Component\Form\Extension\Core\Type\CheckboxType', [
                'label' => $this->l('Retirer l\'image de partage'),
                'required' => false,
                'data' => false,
            ]);
    }

    /** Après l'enregistrement du formulaire de PrestaShop, celui du bloc « Partage ». */
    private function enregistrerBloc($form, array $params)
    {
        $def = self::FORMS[$form];
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $data = isset($params['form_data']) ? $params['form_data'] : [];
        if ($def['child'] !== null) {
            $data = isset($data[$def['child']]) ? $data[$def['child']] : [];
        }
        if (!$id || !is_array($data) || !array_key_exists('coolshare_title', $data)) {
            return;
        }
        $employe = isset($this->context->employee->id) ? (int) $this->context->employee->id : 0;

        try {
            foreach ($this->boutiquesDuContexte() as $idShop) {
                foreach (Language::getLanguages(false) as $l) {
                    $idLang = (int) $l['id_lang'];
                    CoolShareApi::set($def['entity'], $id, $idLang, $idShop, [
                        'title' => isset($data['coolshare_title'][$idLang]) ? (string) $data['coolshare_title'][$idLang] : '',
                        'description' => isset($data['coolshare_description'][$idLang]) ? (string) $data['coolshare_description'][$idLang] : '',
                    ], $employe);
                }
                $fichier = isset($data['coolshare_image']) ? $data['coolshare_image'] : null;
                if (is_object($fichier) && method_exists($fichier, 'getPathname') && $fichier->getPathname() !== '') {
                    CoolShareApi::setImage($def['entity'], $id, $idShop, $fichier->getPathname(), $employe);
                } elseif (!empty($data['coolshare_image_remove'])) {
                    CoolShareApi::clearImage($def['entity'], $id, $idShop, $employe);
                }
            }
        } catch (CoolShareException $e) {
            // Le formulaire de PrestaShop est déjà enregistré : on prévient, sans l'annuler.
            $this->prevenir($this->messageErreur($e->getMessage()));
        }
    }

    /**
     * Le bloc « Partage » de l'ancienne fiche produit, en bas de son onglet SEO. Mêmes
     * champs, même aperçu ; l'image part à part (voir AdminCoolShareController).
     */
    public function hookDisplayAdminProductsSeoStepBottom($params)
    {
        $id = isset($params['id_product']) ? (int) $params['id_product'] : (int) Tools::getValue('id_product');
        if ($id <= 0) {
            return '';
        }
        $idShop = (int) $this->context->shop->id ?: (int) Configuration::get('PS_SHOP_DEFAULT');
        $langues = [];
        foreach (Language::getLanguages(false) as $l) {
            $t = CoolShareRepository::texts('product', $id, (int) $l['id_lang'], $idShop);
            $langues[] = ['id_lang' => (int) $l['id_lang'], 'iso' => $l['iso_code'],
                'title' => (string) $t['title'], 'description' => (string) $t['description']];
        }
        $apercu = ['entity' => 'product', 'domain' => Tools::getShopDomainSsl(false),
            'upload' => $this->context->link->getAdminLink('AdminCoolShare', true, [], ['ajax' => 1, 'action' => 'uploadImage', 'id_product' => $id])];
        try {
            $page = CoolShareResolver::page('product', $id, (int) Configuration::get('PS_LANG_DEFAULT'), $idShop);
            if ($page) {
                $apercu += [
                    'url' => $page['url'], 'image' => $page['image'] ? $page['image']['url'] : '',
                    'image_source' => $page['image_source'],
                    'title' => $page['title_source'] === 'share' ? '' : $page['title'],
                    'description' => $page['description_source'] === 'share' ? '' : $page['description'],
                ];
            }
        } catch (Throwable $e) {
            // L'aperçu est un confort.
        }
        $this->context->smarty->assign([
            'cs_langues' => $langues,
            'cs_lang_defaut' => (int) Configuration::get('PS_LANG_DEFAULT'),
            'cs_apercu' => json_encode($apercu),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/product_v1.tpl');
    }

    /** Enregistrement des textes de l'ancienne fiche : seulement si son bloc était dans la page. */
    public function hookActionProductUpdate($params)
    {
        if (!Tools::getIsset('coolshare_v1') || empty($params['id_product'])) {
            return;
        }
        $id = (int) $params['id_product'];
        $saisie = (array) Tools::getValue('coolshare_v1');
        $employe = isset($this->context->employee->id) ? (int) $this->context->employee->id : 0;
        try {
            foreach ($this->boutiquesDuContexte() as $idShop) {
                foreach (Language::getLanguages(false) as $l) {
                    $idLang = (int) $l['id_lang'];
                    CoolShareApi::set('product', $id, $idLang, $idShop, [
                        'title' => isset($saisie['title'][$idLang]) ? (string) $saisie['title'][$idLang] : '',
                        'description' => isset($saisie['description'][$idLang]) ? (string) $saisie['description'][$idLang] : '',
                    ], $employe);
                }
                if (!empty($saisie['image_remove'])) {
                    CoolShareApi::clearImage('product', $id, $idShop, $employe);
                }
            }
        } catch (CoolShareException $e) {
            $this->prevenir($this->messageErreur($e->getMessage()));
        }
    }

    /** Un message d'erreur après l'enregistrement d'une fiche, sur une page Symfony. */
    private function prevenir($message)
    {
        try {
            $requete = $this->get('request_stack') ? $this->get('request_stack')->getCurrentRequest() : null;
            if ($requete && $requete->hasSession()) {
                $requete->getSession()->getFlashBag()->add('error', $message);

                return;
            }
        } catch (Throwable $e) {
            // Pas de pile de requêtes (contexte hérité) : on passe par le contrôleur.
        }
        if (isset($this->context->controller->errors) && is_array($this->context->controller->errors)) {
            $this->context->controller->errors[] = $message;
        }
    }

    /** En multiboutique, « toutes les boutiques » écrit dans chacune. */
    private function boutiquesDuContexte()
    {
        $ids = Shop::isFeatureActive() ? Shop::getContextListShopID() : [(int) $this->context->shop->id];
        $ids = array_filter(array_map('intval', (array) $ids));

        return $ids ?: [(int) Configuration::get('PS_SHOP_DEFAULT')];
    }

    public function messageErreur($code)
    {
        $messages = [
            'bad_image' => $this->l('CoolShare : ce fichier n\'est pas une image lisible (JPEG, PNG ou WebP).'),
            'image_too_large' => $this->l('CoolShare : l\'image dépasse 8 Mo.'),
            'save_failed' => $this->l('CoolShare : l\'enregistrement a échoué (dossier img/coolshare non accessible en écriture ?).'),
            'not_found' => $this->l('CoolShare : la page n\'existe pas.'),
        ];

        return isset($messages[$code]) ? $messages[$code] : 'CoolShare : ' . $code;
    }

    // -- Suppressions ----------------------------------------------------------------------

    public function hookActionObjectProductDeleteAfter($params)
    {
        $this->oublier('product', $params);
    }

    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $this->oublier('category', $params);
    }

    public function hookActionObjectCmsDeleteAfter($params)
    {
        $this->oublier('cms', $params);
    }

    public function hookActionObjectManufacturerDeleteAfter($params)
    {
        $this->oublier('manufacturer', $params);
    }

    private function oublier($entity, $params)
    {
        if (empty($params['object']->id)) {
            return;
        }
        foreach (CoolShareRepository::deleteEntity($entity, (int) $params['object']->id) as $f) {
            if (CoolShareImage::isSafeName($f)) {
                @unlink(CoolShareImage::dir() . $f);
            }
        }
    }

    // -- Back-office : ressources --------------------------------------------------------

    public function hookDisplayBackOfficeHeader()
    {
        // Les pages Symfony (fiche produit…) n'ont pas `controller` dans l'adresse : le nom
        // hérité est porté par le contrôleur du contexte.
        $controleur = (string) Tools::getValue('controller');
        if ($controleur === '' && isset($this->context->controller->controller_name)) {
            $controleur = (string) $this->context->controller->controller_name;
        }
        $pages = ['AdminProducts', 'AdminCategories', 'AdminCmsContent', 'AdminManufacturers', 'AdminModules'];
        if (!in_array($controleur, $pages, true) || ($controleur === 'AdminModules' && Tools::getValue('configure') !== $this->name)) {
            return '';
        }
        // La version dans l'adresse : les serveurs gardent souvent ces fichiers des mois en
        // cache, et une mise à jour du module ne serait pas vue.
        $this->context->controller->addCSS($this->_path . 'views/css/coolshare-admin.css?v=' . $this->version);
        $this->context->controller->addJS($this->_path . 'views/js/coolshare-admin.js?v=' . $this->version);

        return '';
    }

    // -- Page du module --------------------------------------------------------------------

    public function getContent()
    {
        $message = '';
        if (Tools::isSubmit('submitCoolShareConfig')) {
            $message = $this->enregistrerConfiguration();
        } elseif (Tools::isSubmit('submitCoolShareNet')) {
            Configuration::updateValue('ZM40_NET_ENABLED', (int) Tools::getValue('ZM40_NET_ENABLED'));
            Zm40CommonCsh::clearFeedCache();
            $message = $this->displayConfirmation($this->l('Réglages enregistrés.'));
        }

        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;
        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'module_name' => $this->displayName,
            'module_version' => $this->version,
            'zm40_ah_name' => 'CoolShare',
            'zm40_ah_sub' => $this->l('Balises de partage pour les réseaux sociaux'),
            'zm40_ah_version' => $this->version,
            'zm40_ah_shop' => Configuration::get('PS_SHOP_NAME'),
            'cs_message' => $message,
            'cs_form_config' => $this->formulaireConfiguration(),
            'cs_form_ecosystem' => $this->formulaireEcosysteme(),
            'cs_audit' => $this->donneesControle($idShop, $idLang),
            'cs_seen' => $this->etatRemplacement(),
            'cs_home' => $this->apercuAccueil($idLang, $idShop),
            'zm40_net_enabled' => Zm40CommonCsh::isNetEnabled() ? 1 : 0,
            'zm40_footer_html' => Zm40CommonCsh::footer('CoolShare', $this->version, 'coolshare'),
            'zm40_update' => Zm40CommonCsh::checkUpdate('coolshare', $this->version),
            'zm40_modules' => Zm40CommonCsh::modulesFeed('coolshare'),
            'zm40_about_name' => 'CoolShare',
            'zm40_about_license' => 'OSL 3.0',
            'zm40_about_github' => Zm40CommonCsh::githubUrl('coolshare'),
            'zm40_about_site' => Zm40CommonCsh::siteUrl('coolshare', 'panel', '/contact'),
            'zm40_about_modules' => Zm40CommonCsh::siteUrl('coolshare', 'panel', '/'),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function enregistrerConfiguration()
    {
        $erreurs = [];
        Configuration::updateValue('COOLSHARE_ENABLED', (int) Tools::getValue('COOLSHARE_ENABLED'));
        Configuration::updateValue('COOLSHARE_MODE', Tools::getValue('COOLSHARE_MODE') === 'complete' ? 'complete' : 'replace');
        $compte = trim((string) Tools::getValue('COOLSHARE_TWITTER'));
        Configuration::updateValue('COOLSHARE_TWITTER', preg_match('/^@?[A-Za-z0-9_]{1,15}$/', $compte) ? ltrim($compte, '@') : '');
        if ($compte !== '' && !preg_match('/^@?[A-Za-z0-9_]{1,15}$/', $compte)) {
            $erreurs[] = $this->l('Compte X invalide : lettres, chiffres et _ seulement, 15 caractères au plus.');
        }

        $idShop = (int) $this->context->shop->id;
        $employe = (int) $this->context->employee->id;
        try {
            // L'image par défaut de la boutique.
            if (!empty($_FILES['COOLSHARE_DEFAULT_IMAGE']['tmp_name']) && is_uploaded_file($_FILES['COOLSHARE_DEFAULT_IMAGE']['tmp_name'])) {
                $nom = CoolShareImage::store($_FILES['COOLSHARE_DEFAULT_IMAGE']['tmp_name'], 'default', 0, $idShop);
                Configuration::updateValue('COOLSHARE_DEFAULT_IMAGE', $nom);
                CoolShareImage::purge();
            } elseif (Tools::getValue('COOLSHARE_DEFAULT_IMAGE_REMOVE')) {
                Configuration::updateValue('COOLSHARE_DEFAULT_IMAGE', '');
            }
            // L'accueil : il n'a pas de formulaire à lui dans PrestaShop.
            foreach (Language::getLanguages(false) as $l) {
                $idLang = (int) $l['id_lang'];
                CoolShareApi::set('index', 0, $idLang, $idShop, [
                    'title' => (string) Tools::getValue('COOLSHARE_HOME_TITLE_' . $idLang),
                    'description' => (string) Tools::getValue('COOLSHARE_HOME_DESCRIPTION_' . $idLang),
                ], $employe);
            }
            if (!empty($_FILES['COOLSHARE_HOME_IMAGE']['tmp_name']) && is_uploaded_file($_FILES['COOLSHARE_HOME_IMAGE']['tmp_name'])) {
                CoolShareApi::setImage('index', 0, $idShop, $_FILES['COOLSHARE_HOME_IMAGE']['tmp_name'], $employe);
            } elseif (Tools::getValue('COOLSHARE_HOME_IMAGE_REMOVE')) {
                CoolShareApi::clearImage('index', 0, $idShop, $employe);
            }
        } catch (CoolShareException $e) {
            $erreurs[] = $this->messageErreur($e->getMessage());
        }

        return $erreurs ? $this->displayError(implode('<br>', array_map('htmlspecialchars', $erreurs)))
            : $this->displayConfirmation($this->l('Réglages enregistrés.'));
    }

    private function formulaireConfiguration()
    {
        $idShop = (int) $this->context->shop->id;
        $defaut = (string) Configuration::get('COOLSHARE_DEFAULT_IMAGE');
        $accueil = CoolShareRepository::image('index', 0, $idShop);
        $vignette = function ($fichier) {
            return CoolShareImage::exists($fichier)
                ? '<img src="' . htmlspecialchars(CoolShareImage::url($fichier), ENT_QUOTES, 'UTF-8') . '" alt="" class="coolshare-vignette">'
                : '';
        };
        $oui = [['id' => 'on', 'value' => 1, 'label' => $this->l('Oui')], ['id' => 'off', 'value' => 0, 'label' => $this->l('Non')]];

        $formulaires = [
            ['form' => [
                'legend' => ['title' => $this->l('Réglages'), 'icon' => 'icon-cogs'],
                'input' => [
                    ['type' => 'switch', 'name' => 'COOLSHARE_ENABLED', 'label' => $this->l('Activer CoolShare'), 'values' => $oui, 'is_bool' => true],
                    ['type' => 'select', 'name' => 'COOLSHARE_MODE', 'label' => $this->l('Mode'),
                        'desc' => $this->l('Remplacer : les balises du thème sont retirées et remplacées par un jeu complet (recommandé). Compléter : rien n\'est retiré, seules l\'image et la carte X sont ajoutées — pour une boutique où un cache de page empêche le remplacement.'),
                        'options' => ['query' => [
                            ['id' => 'replace', 'name' => $this->l('Remplacer les balises du thème')],
                            ['id' => 'complete', 'name' => $this->l('Compléter les balises du thème')],
                        ], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'text', 'name' => 'COOLSHARE_TWITTER', 'label' => $this->l('Compte X de la boutique'),
                        'prefix' => '@', 'class' => 'fixed-width-xl', 'desc' => $this->l('Facultatif. Affiché sur les cartes X (twitter:site).')],
                    ['type' => 'file', 'name' => 'COOLSHARE_DEFAULT_IMAGE', 'label' => $this->l('Image par défaut'),
                        'image' => $vignette($defaut) ?: false,
                        'desc' => $this->l('Utilisée pour toute page sans image : pages CMS, accueil, recherche… Recadrée en 1200 × 630.')],
                    ['type' => 'checkbox', 'name' => 'COOLSHARE_DEFAULT_IMAGE', 'label' => '',
                        'values' => ['query' => [['id' => 'REMOVE', 'name' => $this->l('Retirer l\'image par défaut')]], 'id' => 'id', 'name' => 'name']],
                ],
                'submit' => ['title' => $this->l('Enregistrer'), 'name' => 'submitCoolShareConfig'],
            ]],
            ['form' => [
                'legend' => ['title' => $this->l('Page d\'accueil'), 'icon' => 'icon-home'],
                'description' => $this->l('L\'accueil n\'a pas de formulaire à lui dans PrestaShop : ses valeurs de partage se règlent ici. Vides, ce sont le titre et la description SEO de la page d\'accueil (Préférences > Trafic & SEO), puis le nom de la boutique.'),
                'input' => [
                    ['type' => 'text', 'name' => 'COOLSHARE_HOME_TITLE', 'label' => $this->l('Titre de partage'), 'lang' => true, 'maxlength' => 255],
                    ['type' => 'textarea', 'name' => 'COOLSHARE_HOME_DESCRIPTION', 'label' => $this->l('Description de partage'), 'lang' => true, 'rows' => 3],
                    ['type' => 'file', 'name' => 'COOLSHARE_HOME_IMAGE', 'label' => $this->l('Image de partage'),
                        'image' => $vignette($accueil) ?: false,
                        'desc' => $this->l('Vide : l\'image par défaut.')],
                    ['type' => 'checkbox', 'name' => 'COOLSHARE_HOME_IMAGE', 'label' => '',
                        'values' => ['query' => [['id' => 'REMOVE', 'name' => $this->l('Retirer l\'image de l\'accueil')]], 'id' => 'id', 'name' => 'name']],
                ],
                'submit' => ['title' => $this->l('Enregistrer'), 'name' => 'submitCoolShareConfig'],
            ]],
        ];

        $valeurs = [
            'COOLSHARE_ENABLED' => (int) Configuration::get('COOLSHARE_ENABLED'),
            'COOLSHARE_MODE' => Configuration::get('COOLSHARE_MODE') ?: 'replace',
            'COOLSHARE_TWITTER' => (string) Configuration::get('COOLSHARE_TWITTER'),
            'COOLSHARE_DEFAULT_IMAGE_REMOVE' => 0,
            'COOLSHARE_HOME_IMAGE_REMOVE' => 0,
        ];
        foreach (Language::getLanguages(false) as $l) {
            $t = CoolShareRepository::texts('index', 0, (int) $l['id_lang'], $idShop);
            $valeurs['COOLSHARE_HOME_TITLE'][(int) $l['id_lang']] = (string) $t['title'];
            $valeurs['COOLSHARE_HOME_DESCRIPTION'][(int) $l['id_lang']] = (string) $t['description'];
        }

        return $this->helper($formulaires, $valeurs);
    }

    private function formulaireEcosysteme()
    {
        $oui = [['id' => 'on', 'value' => 1, 'label' => $this->l('Oui')], ['id' => 'off', 'value' => 0, 'label' => $this->l('Non')]];

        return $this->helper([['form' => [
            'legend' => ['title' => $this->l('Mises à jour et modules ZM40'), 'icon' => 'icon-globe'],
            'input' => [[
                'type' => 'switch', 'name' => 'ZM40_NET_ENABLED', 'is_bool' => true, 'values' => $oui,
                'label' => $this->l('Vérifier les mises à jour et afficher les autres modules ZM40'),
                'desc' => $this->l('Une fois par jour au plus, une requête anonyme vers GitHub (version) et zm40.com (liste des modules). Aucune donnée de la boutique n\'est transmise.'),
            ]],
            'submit' => ['title' => $this->l('Enregistrer'), 'name' => 'submitCoolShareNet'],
        ]]], ['ZM40_NET_ENABLED' => Zm40CommonCsh::isNetEnabled() ? 1 : 0], 'submitCoolShareNet');
    }

    private function helper(array $formulaires, array $valeurs, $submit = 'submitCoolShareConfig')
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->languages = $this->context->controller->getLanguages();
        $helper->show_toolbar = false;
        $helper->submit_action = $submit;
        $helper->tpl_vars = ['fields_value' => $valeurs, 'languages' => $helper->languages, 'id_language' => $this->context->language->id];

        return $helper->generateForm($formulaires);
    }

    /** L'onglet « Contrôle » : les pages à problèmes, filtrables, avec un lien pour corriger. */
    private function donneesControle($idShop, $idLang)
    {
        $entite = (string) Tools::getValue('cs_entity');
        $probleme = (string) Tools::getValue('cs_problem');
        $page = max(1, (int) Tools::getValue('cs_page'));
        try {
            $r = CoolShareApi::audit($idShop, $idLang, [
                'entity' => CoolShareRepository::isEntity($entite) ? $entite : null,
                'problem' => $probleme, 'page' => $page, 'per_page' => 50,
            ]);
        } catch (Throwable $e) {
            $r = ['total' => 0, 'rows' => []];
        }
        $link = $this->context->link;
        $nouvelle = $this->nouvelleFicheProduit();
        $lien = function ($entity, $id) use ($link, $nouvelle) {
            switch ($entity) {
                case 'product':
                    // La nouvelle fiche a sa route ; l'ancien lien mène au tableau de bord
                    // quand elle est active (8.1-8.2).
                    return $nouvelle
                        ? $link->getAdminLink('AdminProducts', true, ['route' => 'admin_products_edit', 'productId' => $id])
                        : $link->getAdminLink('AdminProducts', true, [], ['id_product' => $id, 'updateproduct' => 1]);
                case 'category': return $link->getAdminLink('AdminCategories', true, [], ['id_category' => $id, 'updatecategory' => 1]);
                case 'cms': return $link->getAdminLink('AdminCmsContent', true, [], ['id_cms' => $id, 'updatecms' => 1]);
                case 'manufacturer': return $link->getAdminLink('AdminManufacturers', true, [], ['id_manufacturer' => $id, 'updatemanufacturer' => 1]);
            }

            return AdminController::$currentIndex . '&configure=coolshare&token=' . Tools::getAdminTokenLite('AdminModules');
        };
        foreach ($r['rows'] as &$ligne) {
            $ligne['edit'] = $lien($ligne['entity'], $ligne['id_entity']);
        }
        unset($ligne);

        return [
            'total' => (int) $r['total'],
            'rows' => $r['rows'],
            'page' => $page,
            'pages' => max(1, (int) ceil($r['total'] / 50)),
            'entity' => $entite,
            'problem' => $probleme,
            'base' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'entities' => [
                'index' => $this->l('Accueil'), 'product' => $this->l('Produits'), 'category' => $this->l('Catégories'),
                'cms' => $this->l('Pages CMS'), 'manufacturer' => $this->l('Marques'),
            ],
            'problems' => [
                'no_image' => $this->l('Sans image'),
                'image_too_small' => $this->l('Image trop petite'),
                'no_description' => $this->l('Sans description'),
                'description_too_long' => $this->l('Description trop longue'),
                'title_too_long' => $this->l('Titre trop long'),
                'duplicate_title' => $this->l('Titre en double'),
            ],
        ];
    }

    /** Le remplacement a-t-il été vu récemment sur la vitrine ? */
    private function etatRemplacement()
    {
        $vu = (int) Configuration::get('COOLSHARE_SEEN');

        return [
            'mode' => Configuration::get('COOLSHARE_MODE') ?: 'replace',
            'seen' => $vu,
            'recent' => $vu > time() - 7 * 86400,
        ];
    }

    private function apercuAccueil($idLang, $idShop)
    {
        try {
            return CoolShareResolver::page('index', 0, $idLang, $idShop);
        } catch (Throwable $e) {
            return null;
        }
    }
}
