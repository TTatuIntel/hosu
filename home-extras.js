/**
 * Renders admin-managed homepage sections below the hero (partners, CTA).
 * Refreshes quietly in the background when admin content changes.
 */
(function () {
    'use strict';

    var _lastExtrasFingerprint = '';

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderPartnerLogo(item) {
        var cls = 'partner-logo' + (item.css_class ? ' ' + escHtml(item.css_class) : '');
        return '<div class="' + cls + '"><img src="' + escHtml(item.logo) + '" alt="' + escHtml(item.name) + '" loading="lazy"></div>';
    }

    function renderPartners(partners) {
        var mount = document.getElementById('home-partners-mount');
        if (!mount) return;
        /* Collapse the whole #partners section when admin has not added any partners,
           so the home page does not leave a blank band. */
        var section = mount.closest('section');
        if (!partners || partners.visible === false || !partners.items || !partners.items.length) {
            if (section) section.hidden = true;
            mount.innerHTML = '';
            return;
        }
        if (section) section.hidden = false;

        var itemsHtml = partners.items.map(renderPartnerLogo).join('');
        mount.innerHTML =
            '<div class="partners-container">' +
            '<div class="partners-header"><h2 class="partners-title">' + escHtml(partners.title || 'Our Partners') + '</h2></div>' +
            '<div class="partners-track">' +
            '<div class="partners-grid">' + itemsHtml + '</div>' +
            '<div class="partners-grid">' + itemsHtml + '</div>' +
            '</div></div>';
    }

    function renderCta(cta) {
        var mount = document.getElementById('home-cta-mount');
        if (!mount) return;
        /* Hide the CTA band entirely when no admin content is configured. */
        var section = mount.closest('section');
        var hasContent = cta && cta.visible !== false && (cta.title || cta.body || cta.primary_label || cta.secondary_label);
        if (!hasContent) {
            if (section) section.hidden = true;
            mount.innerHTML = '';
            return;
        }
        if (section) section.hidden = false;

        var secondaryHtml = '';
        if (cta.secondary_label) {
            if (cta.secondary_action === 'donate') {
                secondaryHtml =
                    '<div class="donate-trigger-wrap">' +
                    '<button class="cta-button donate-trigger">' + escHtml(cta.secondary_label) + '</button>' +
                    '<div class="donate-float-popup" id="cta-donate-popup"></div></div>';
            } else if (cta.secondary_url) {
                secondaryHtml = '<a href="' + escHtml(cta.secondary_url) + '" class="cta-button">' + escHtml(cta.secondary_label) + '</a>';
            }
        }

        var primaryHtml = (cta.primary_label && cta.primary_url)
            ? '<a href="' + escHtml(cta.primary_url) + '" class="cta-button cta-button--outline">' + escHtml(cta.primary_label) + '</a>'
            : '';

        mount.innerHTML =
            '<div class="container"><div class="cta-inner">' +
            '<h2 class="section-title">' + escHtml(cta.title || '') + '</h2>' +
            (cta.body ? '<p>' + cta.body + '</p>' : '') +
            '<div class="cta-buttons">' + primaryHtml + secondaryHtml + '</div>' +
            '</div></div>';
    }

    function extrasFingerprint(data) {
        if (!data) return '';
        return JSON.stringify(data.partners || {}) + '||' + JSON.stringify(data.cta || {});
    }

    function syncBootstrapExtras(data) {
        if (!window.__HOSU_PAGE_BOOTSTRAP || !data) return;
        window.__HOSU_PAGE_BOOTSTRAP.homepage_extras = {
            partners: data.partners,
            cta: data.cta,
        };
    }

    function applyExtras(data, opts) {
        opts = opts || {};
        if (!data) return false;
        var fp = extrasFingerprint(data);
        if (!opts.force && fp === _lastExtrasFingerprint) return false;
        _lastExtrasFingerprint = fp;
        renderPartners(data.partners);
        renderCta(data.cta);
        syncBootstrapExtras(data);
        return true;
    }

    function fetchExtrasFromApi() {
        return fetch('api.php?action=get_homepage_extras', {
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function refreshQuietly(force) {
        if (!force && document.hidden) return Promise.resolve(false);
        return fetchExtrasFromApi()
            .then(function (data) {
                if (!data || !data.success) return false;
                return applyExtras(data, { force: !!force });
            })
            .catch(function () {
                return false;
            });
    }

    window.HOSU_EXTRAS = {
        apply: function (data, opts) {
            return applyExtras(data, Object.assign({ force: true }, opts || {}));
        },
        refreshQuietly: refreshQuietly,
        reload: function () {
            return fetchExtrasFromApi().then(function (data) {
                if (data && data.success) applyExtras(data, { force: true });
                return data;
            });
        },
    };

    var boot = window.__HOSU_PAGE_BOOTSTRAP;
    if (boot && boot.success && boot.homepage_extras) {
        applyExtras(boot.homepage_extras, { force: true });
        return;
    }

    fetchExtrasFromApi()
        .then(function (data) {
            if (data && data.success) applyExtras(data, { force: true });
        })
        .catch(function () { /* keep static fallback if any */ });
})();
