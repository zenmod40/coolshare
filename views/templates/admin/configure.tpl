{*
 * CoolShare - Balises de partage (Open Graph, cartes X) pour PrestaShop
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 *}
<link rel="stylesheet" href="{$module_dir|escape:'html':'UTF-8'}views/css/zm40-common.css">

{* ===== Header de marque ===== *}
<div class="zm40-ah coolshare-ah">
    <div class="zm40-ah-brand">
        <div>
            <h2>{$zm40_ah_name|escape:'html':'UTF-8'}</h2>
            <div class="zm40-ah-sub">{$zm40_ah_sub|escape:'html':'UTF-8'} &middot; v{$zm40_ah_version|escape:'html':'UTF-8'}</div>
        </div>
    </div>
    {if $zm40_ah_shop}<span class="zm40-ah-badge">{$zm40_ah_shop|escape:'html':'UTF-8'}</span>{/if}
</div>

{include file="module:coolshare/views/templates/admin/_partials/zm40_update.tpl"}

{$cs_message nofilter}

{* ===== Onglets ===== *}
<ul class="zm40-tabs" id="zm40-tabs">
    <li class="is-active" data-tab="config"><i class="icon icon-cogs"></i>{l s='Configuration' mod='coolshare'}</li>
    <li data-tab="audit"><i class="icon icon-check-square-o"></i>{l s='Contrôle' mod='coolshare'}{if $cs_audit.total} <span class="badge">{$cs_audit.total|intval}</span>{/if}</li>
    <li data-tab="guide"><i class="icon icon-book"></i>{l s='Guide' mod='coolshare'}</li>
    <li data-tab="ecosystem"><i class="icon icon-th-large"></i>{l s='Modules ZM40' mod='coolshare'}</li>
</ul>

{* ===== Onglet 1 : CONFIGURATION ===== *}
<div class="zm40-tab-content is-active" data-content="config">
    {if $cs_seen.mode == 'replace'}
        {if $cs_seen.recent}
            <div class="alert alert-success">{l s='Remplacement actif : la vitrine reçoit les balises de CoolShare (vu le' mod='coolshare'} {$cs_seen.seen|date_format:'%d/%m/%Y %H:%M'|escape:'html':'UTF-8'}).</div>
        {else}
            <div class="alert alert-warning">{l s='Le remplacement n\'a pas encore été observé sur la vitrine. Affichez une page de la boutique : si cet avis reste, un cache de page complète empêche peut-être le remplacement — passez alors en mode « Compléter ».' mod='coolshare'}</div>
        {/if}
    {/if}

    {if $cs_home}
        <div class="panel coolshare-panel">
            <h3><i class="icon icon-eye"></i> {l s='Aperçu du partage de l\'accueil' mod='coolshare'}</h3>
            <div class="coolshare-card">
                {if $cs_home.image}
                    <div class="coolshare-card-img" style="background-image:url('{$cs_home.image.url|escape:'html':'UTF-8'}')"></div>
                {else}
                    <div class="coolshare-card-img coolshare-card-empty">{l s='Aucune image' mod='coolshare'}</div>
                {/if}
                <div class="coolshare-card-body">
                    <div class="coolshare-card-domain">{$cs_home.url|regex_replace:'#^https?://([^/]+).*$#':'$1'|escape:'html':'UTF-8'}</div>
                    <div class="coolshare-card-title">{$cs_home.title|escape:'html':'UTF-8'}</div>
                    <div class="coolshare-card-desc">{$cs_home.description|escape:'html':'UTF-8'}</div>
                </div>
            </div>
            <p class="help-block">
                <a href="https://developers.facebook.com/tools/debug/?q={$cs_home.url|escape:'url'}" target="_blank" rel="noopener">{l s='Rafraîchir chez Facebook' mod='coolshare'}</a>
                &middot; {l s='Facebook garde les anciennes cartes en cache : après une modification, demandez-lui de relire la page.' mod='coolshare'}
            </p>
        </div>
    {/if}

    {$cs_form_config nofilter}
</div>

{* ===== Onglet 2 : CONTRÔLE ===== *}
<div class="zm40-tab-content" data-content="audit">
    <div class="panel coolshare-panel">
        <h3><i class="icon icon-check-square-o"></i> {l s='Pages à corriger' mod='coolshare'} <span class="badge">{$cs_audit.total|intval}</span></h3>
        <form method="get" action="{$cs_audit.base|escape:'html':'UTF-8'}" class="form-inline coolshare-filtres">
            {* Les paramètres de la page du module, repris : un GET sur l'URL seule les perdrait. *}
            <input type="hidden" name="controller" value="AdminModules">
            <input type="hidden" name="configure" value="coolshare">
            <input type="hidden" name="token" value="{$smarty.get.token|escape:'html':'UTF-8'}">
            <input type="hidden" name="cs_tab" value="audit">
            <select name="cs_entity" class="form-control">
                <option value="">{l s='Toutes les pages' mod='coolshare'}</option>
                {foreach from=$cs_audit.entities key=k item=v}
                    <option value="{$k|escape:'html':'UTF-8'}"{if $cs_audit.entity == $k} selected{/if}>{$v|escape:'html':'UTF-8'}</option>
                {/foreach}
            </select>
            <select name="cs_problem" class="form-control">
                <option value="">{l s='Tous les problèmes' mod='coolshare'}</option>
                {foreach from=$cs_audit.problems key=k item=v}
                    <option value="{$k|escape:'html':'UTF-8'}"{if $cs_audit.problem == $k} selected{/if}>{$v|escape:'html':'UTF-8'}</option>
                {/foreach}
            </select>
            <button type="submit" class="btn btn-default"><i class="icon-filter"></i> {l s='Filtrer' mod='coolshare'}</button>
        </form>

        {if $cs_audit.rows}
            <table class="table coolshare-table">
                <thead><tr>
                    <th>{l s='Page' mod='coolshare'}</th>
                    <th>{l s='Type' mod='coolshare'}</th>
                    <th>{l s='Problèmes' mod='coolshare'}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                {foreach from=$cs_audit.rows item=r}
                    <tr>
                        <td>{$r.name|escape:'html':'UTF-8'} <span class="text-muted">#{$r.id_entity|intval}</span></td>
                        <td>{$cs_audit.entities[$r.entity]|escape:'html':'UTF-8'}</td>
                        <td>{foreach from=$r.problems item=p}<span class="label label-warning coolshare-pb">{$cs_audit.problems[$p]|escape:'html':'UTF-8'}</span> {/foreach}</td>
                        <td class="text-right"><a class="btn btn-default btn-xs" href="{$r.edit|escape:'html':'UTF-8'}"><i class="icon-pencil"></i> {l s='Corriger' mod='coolshare'}</a></td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
            {if $cs_audit.pages > 1}
                <div class="coolshare-pagination">
                    {if $cs_audit.page > 1}<a class="btn btn-default btn-xs" href="{$cs_audit.base|escape:'html':'UTF-8'}&amp;cs_tab=audit&amp;cs_entity={$cs_audit.entity|escape:'url'}&amp;cs_problem={$cs_audit.problem|escape:'url'}&amp;cs_page={$cs_audit.page-1|intval}">&larr;</a>{/if}
                    {l s='Page' mod='coolshare'} {$cs_audit.page|intval} / {$cs_audit.pages|intval}
                    {if $cs_audit.page < $cs_audit.pages}<a class="btn btn-default btn-xs" href="{$cs_audit.base|escape:'html':'UTF-8'}&amp;cs_tab=audit&amp;cs_entity={$cs_audit.entity|escape:'url'}&amp;cs_problem={$cs_audit.problem|escape:'url'}&amp;cs_page={$cs_audit.page+1|intval}">&rarr;</a>{/if}
                </div>
            {/if}
        {else}
            <p class="alert alert-success">{l s='Aucune page à corriger avec ces filtres.' mod='coolshare'}</p>
        {/if}
        <p class="help-block">{l s='Sans image : ni image de partage, ni image de la page, ni image par défaut. Image trop petite : sous 1200 × 630, les réseaux affichent une petite vignette au lieu d\'une grande carte. Description trop longue : au-delà de 200 caractères. Titre trop long : au-delà de 95 caractères.' mod='coolshare'}</p>
    </div>
</div>

{* ===== Onglet 3 : GUIDE ===== *}
<div class="zm40-tab-content" data-content="guide">
    <div class="panel coolshare-panel">
        <h3><i class="icon icon-book"></i> {l s='Guide' mod='coolshare'} <small>{$module_name|escape:'html':'UTF-8'} v{$module_version|escape:'html':'UTF-8'}</small></h3>
        <p>{l s='Quand une page de la boutique est partagée sur Facebook, LinkedIn, WhatsApp ou X, la carte affichée vient de balises invisibles du code de la page. CoolShare les écrit toutes, proprement, sur chaque page : un seul jeu, sans doublon avec celles du thème.' mod='coolshare'}</p>
        <h4>{l s='Où régler les valeurs' mod='coolshare'}</h4>
        <ul>
            <li>{l s='Produits : fiche produit, onglet SEO, bloc « Partage ».' mod='coolshare'}</li>
            <li>{l s='Catégories, pages CMS, marques : en bas de leur formulaire.' mod='coolshare'}</li>
            <li>{l s='Accueil : ici, onglet Configuration.' mod='coolshare'}</li>
        </ul>
        <h4>{l s='D\'où viennent les valeurs' mod='coolshare'}</h4>
        <ul>
            <li>{l s='Titre : le titre de partage s\'il est saisi, sinon le titre SEO, sinon le nom.' mod='coolshare'}</li>
            <li>{l s='Description : la description de partage, sinon la description SEO, sinon le début du texte de la page.' mod='coolshare'}</li>
            <li>{l s='Image : l\'image de partage, sinon l\'image de la page (photo du produit, image de la catégorie, logo de la marque), sinon l\'image par défaut.' mod='coolshare'}</li>
        </ul>
        <h4>{l s='Après une modification' mod='coolshare'}</h4>
        <p>{l s='Les réseaux gardent les cartes en cache plusieurs jours. Pour Facebook, ouvrez l\'outil de débogage du partage et cliquez sur « Récupérer à nouveau » ; le lien est sous chaque aperçu.' mod='coolshare'}</p>
        <h4>{l s='Régie' mod='coolshare'}</h4>
        <p>{l s='CoolShare se pilote aussi depuis Régie, l\'application de ZM40 : édition sans passer par le back-office, aperçu, contrôle et actions sur tout le catalogue.' mod='coolshare'}</p>
    </div>
</div>

{* ===== Onglet 4 : MODULES ZM40 ===== *}
<div class="zm40-tab-content" data-content="ecosystem">
    {include file="module:coolshare/views/templates/admin/_partials/zm40_modules.tpl"}
    {if !isset($zm40_modules) || !$zm40_modules|@count}<div class="panel"><p style="margin:0">{l s='Aucun module à afficher pour le moment.' mod='coolshare'}</p></div>{/if}
    {$cs_form_ecosystem nofilter}
</div>

{include file="module:coolshare/views/templates/admin/_partials/zm40_panel.tpl"}
{include file="module:coolshare/views/templates/admin/_partials/zm40_footer.tpl"}

<script>
(function () {
    var tabs = document.querySelectorAll('#zm40-tabs li');
    var contents = document.querySelectorAll('.zm40-tab-content');
    function ouvrir(cible) {
        var li = document.querySelector('#zm40-tabs li[data-tab="' + cible + '"]');
        var pane = document.querySelector('.zm40-tab-content[data-content="' + cible + '"]');
        if (!li || !pane) { return; }
        tabs.forEach(function (x) { x.classList.remove('is-active'); });
        contents.forEach(function (x) { x.classList.remove('is-active'); });
        li.classList.add('is-active');
        pane.classList.add('is-active');
        try { localStorage.setItem('zm40_coolshare_tab', cible); } catch (e) {}
    }
    tabs.forEach(function (li) {
        li.addEventListener('click', function () { ouvrir(li.getAttribute('data-tab')); });
    });
    // Un filtre du contrôle recharge la page : on y revient.
    var demande = (location.search.match(/[?&]cs_tab=([a-z]+)/) || [])[1];
    try {
        ouvrir(demande || localStorage.getItem('zm40_coolshare_tab') || 'config');
    } catch (e) {}
})();
</script>
