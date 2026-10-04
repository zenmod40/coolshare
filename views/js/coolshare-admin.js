/**
 * CoolShare - Balises de partage (Open Graph, cartes X) pour PrestaShop
 *
 * Le bloc « Partage » des formulaires du back-office : un aperçu de la carte mis à jour
 * à la saisie, un compteur sous chaque texte, et le lien vers l'outil de Facebook.
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License version 3.0
 */
(function () {
    'use strict';

    var LIMITES = { title: 95, description: 200 };

    function texte(el) {
        return el ? String(el.value || '').replace(/\s+/g, ' ').trim() : '';
    }

    /**
     * Un seul compteur par champ, sous le groupe de langues : il suit la langue qu'on
     * saisit. Un compteur par langue s'affichait aussi pour les langues masquées.
     */
    function compteur(champs, limite) {
        if (!champs.length) { return; }
        var groupe = champs[0].closest('.form-group') || champs[0].parentNode;
        var c = document.createElement('div');
        c.className = 'coolshare-compteur';
        var aide = groupe.querySelector('.form-text, .help-block, small');
        (aide || champs[champs.length - 1]).insertAdjacentElement(aide ? 'beforebegin' : 'afterend', c);
        function maj(champ) {
            var n = String(champ.value || '').length;
            c.textContent = n + ' / ' + limite;
            c.classList.toggle('trop', n > limite);
        }
        Array.prototype.forEach.call(champs, function (champ) {
            champ.addEventListener('input', function () { maj(champ); });
            champ.addEventListener('focus', function () { maj(champ); });
        });
        maj(champs[0]);
    }

    function bloc(fichier) {
        var donnees;
        try {
            donnees = JSON.parse(fichier.getAttribute('data-coolshare') || '{}');
        } catch (e) {
            return;
        }
        var prefixe = fichier.id.replace(/coolshare_image$/, '');
        // Les champs d'une langue seulement (`…_title_1`) : le sélecteur de langue porte des
        // identifiants voisins (`…_title_dropdown`, `…_title_help`).
        function champs(nom) {
            return Array.prototype.filter.call(
                document.querySelectorAll('input[id^="' + prefixe + nom + '_"], textarea[id^="' + prefixe + nom + '_"]'),
                function (el) { return /_\d+$/.test(el.id); }
            );
        }
        var titres = champs('coolshare_title');
        var descriptions = champs('coolshare_description');
        var retirer = document.getElementById(prefixe + 'coolshare_image_remove');

        compteur(titres, LIMITES.title);
        compteur(descriptions, LIMITES.description);

        // L'aperçu se place après le dernier champ du bloc.
        var ancre = retirer ? (retirer.closest('.form-group') || retirer) : (fichier.closest('.form-group') || fichier);
        var zone = document.createElement('div');
        zone.className = 'coolshare-bloc';
        zone.innerHTML =
            '<div class="coolshare-card">' +
            '<div class="coolshare-card-img"></div>' +
            '<div class="coolshare-card-body">' +
            '<div class="coolshare-card-domain"></div>' +
            '<div class="coolshare-card-title"></div>' +
            '<div class="coolshare-card-desc"></div>' +
            '</div></div>' +
            '<div class="coolshare-liens"></div>';
        ancre.insertAdjacentElement('afterend', zone);

        var img = zone.querySelector('.coolshare-card-img');
        zone.querySelector('.coolshare-card-domain').textContent = donnees.domain || '';
        if (donnees.url) {
            var a = document.createElement('a');
            a.href = 'https://developers.facebook.com/tools/debug/?q=' + encodeURIComponent(donnees.url);
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = 'Rafraîchir chez Facebook';
            zone.querySelector('.coolshare-liens').appendChild(a);
            zone.querySelector('.coolshare-liens').appendChild(document.createTextNode(
                ' · Facebook garde les anciennes cartes en cache : après une modification, demandez-lui de relire la page.'
            ));
        }

        var nouvelle = '';
        function maj() {
            var t = texte(titres[0]) || donnees.title || '';
            var d = texte(descriptions[0]) || donnees.description || '';
            zone.querySelector('.coolshare-card-title').textContent = t || '—';
            zone.querySelector('.coolshare-card-desc').textContent = d;
            var source = nouvelle || ((retirer && retirer.checked && donnees.image_source === 'share') ? '' : donnees.image);
            img.style.backgroundImage = source ? 'url("' + String(source).replace(/"/g, '%22') + '")' : '';
            img.classList.toggle('coolshare-card-empty', !source);
            img.textContent = source ? '' : (retirer && retirer.checked ? 'Image de la page ou image par défaut' : 'Aucune image');
        }

        Array.prototype.forEach.call(titres, function (c) { c.addEventListener('input', maj); });
        Array.prototype.forEach.call(descriptions, function (c) { c.addEventListener('input', maj); });
        if (retirer) {
            retirer.addEventListener('change', maj);
        }
        var etat = document.createElement('div');
        etat.className = 'coolshare-compteur';
        fichier.insertAdjacentElement('afterend', etat);
        fichier.addEventListener('change', function () {
            nouvelle = fichier.files && fichier.files[0] ? URL.createObjectURL(fichier.files[0]) : '';
            maj();
            // Ancienne fiche produit : elle n'envoie pas les fichiers avec ses champs, l'image
            // part donc tout de suite.
            if (!donnees.upload || !fichier.files || !fichier.files[0]) { return; }
            var envoi = new FormData();
            envoi.append('image', fichier.files[0]);
            etat.textContent = 'Envoi de l’image…';
            fetch(donnees.upload, { method: 'POST', body: envoi, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (r.ok && r.image) {
                        nouvelle = r.image.url;
                        donnees.image = r.image.url;
                        donnees.image_source = 'share';
                        etat.textContent = 'Image enregistrée (1200 × 630).';
                    } else {
                        etat.textContent = r.error || 'L’image n’a pas pu être enregistrée.';
                        etat.classList.add('trop');
                    }
                    maj();
                })
                .catch(function () {
                    etat.textContent = 'L’image n’a pas pu être envoyée.';
                    etat.classList.add('trop');
                });
        });
        maj();
    }

    function demarrer() {
        Array.prototype.forEach.call(document.querySelectorAll('input[data-coolshare]'), bloc);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrer);
    } else {
        demarrer();
    }
})();
