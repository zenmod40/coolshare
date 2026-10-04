{*
 * CoolShare - Balises de partage (Open Graph, cartes X) pour PrestaShop
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *}
{* Bloc « Partage » de l'ancienne fiche produit. Les noms coolshare_v1[…] partent avec le
   formulaire ; l'image, elle, part à part dès qu'on la choisit. *}
<div class="coolshare-v1 mt-4">
    <input type="hidden" name="coolshare_v1[present]" value="1">
    <h2>{l s='Partage sur les réseaux sociaux' mod='coolshare'}</h2>

    <div class="form-group">
        <label>{l s='Titre de partage' mod='coolshare'}</label>
        {foreach from=$cs_langues item=l}
            <div class="input-group mb-1">
                <div class="input-group-prepend"><span class="input-group-text">{$l.iso|escape:'html':'UTF-8'|upper}</span></div>
                <input type="text" class="form-control" maxlength="255"
                       id="coolshare_v1_coolshare_title_{$l.id_lang|intval}"
                       name="coolshare_v1[title][{$l.id_lang|intval}]"
                       value="{$l.title|escape:'html':'UTF-8'}">
            </div>
        {/foreach}
        <small class="form-text">{l s='Ce que Facebook, LinkedIn ou WhatsApp affichent en titre. Vide : le titre SEO, sinon le nom.' mod='coolshare'}</small>
    </div>

    <div class="form-group">
        <label>{l s='Description de partage' mod='coolshare'}</label>
        {foreach from=$cs_langues item=l}
            <div class="input-group mb-1">
                <div class="input-group-prepend"><span class="input-group-text">{$l.iso|escape:'html':'UTF-8'|upper}</span></div>
                <textarea class="form-control" rows="2" maxlength="512"
                          id="coolshare_v1_coolshare_description_{$l.id_lang|intval}"
                          name="coolshare_v1[description][{$l.id_lang|intval}]">{$l.description|escape:'html':'UTF-8'}</textarea>
            </div>
        {/foreach}
        <small class="form-text">{l s='Vide : la description SEO, sinon le début du texte de la page.' mod='coolshare'}</small>
    </div>

    <div class="form-group">
        <label for="coolshare_v1_coolshare_image">{l s='Image de partage' mod='coolshare'}</label>
        <input type="file" class="form-control-file" id="coolshare_v1_coolshare_image"
               accept="image/jpeg,image/png,image/webp" data-coolshare="{$cs_apercu|escape:'html':'UTF-8'}">
        <small class="form-text">{l s='JPEG, PNG ou WebP, 8 Mo au plus. Recadrée en 1200 × 630. Envoyée dès que vous la choisissez.' mod='coolshare'}</small>
    </div>

    <div class="form-group">
        <div class="checkbox">
            <label>
                <input type="checkbox" id="coolshare_v1_coolshare_image_remove" name="coolshare_v1[image_remove]" value="1">
                {l s='Retirer l\'image de partage (à l\'enregistrement)' mod='coolshare'}
            </label>
        </div>
    </div>
</div>
