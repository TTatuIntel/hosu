/* ============================================================
   SHARED COMPONENTS — shared-components.js
   Navbar + footer from admin Site Content (site_bootstrap.php).
   ============================================================ */
/* ----------------------------------------------------------------
   Global Google Drive image shim
   Any admin who pastes a Drive share link (drive.google.com/file/d/.../view)
   gets it auto-rewritten to a direct image URL on the visitor's browser,
   so plain <img src> and CSS background-image: url(...) both render.
   Safe to run on every page; idempotent.
---------------------------------------------------------------- */
(function () {
    'use strict';
    var W = 1200;

    function driveDirect(s) {
        if (!s) return s;
        if (!/drive\.google\.com|docs\.google\.com/i.test(s)) return s;
        if (/drive_image\.php\?id=/i.test(s)) return s;
        var m = s.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
        if (!m) m = s.match(/\/d\/([a-zA-Z0-9_-]+)/);
        if (!m) m = s.match(/[?&]id=([a-zA-Z0-9_-]+)/);
        if (!m) return s;
        return 'drive_image.php?id=' + encodeURIComponent(m[1]) + '&w=' + W;
    }
    window.HOSU_DRIVE_DIRECT = driveDirect;

    function fixImg(img) {
        if (!img || img.dataset.driveFixed === '1') return;
        var src = img.getAttribute('src') || '';
        var direct = driveDirect(src);
        if (direct !== src) {
            img.setAttribute('src', direct);
            img.dataset.driveFixed = '1';
        }
    }
    function fixBgEl(el) {
        if (!el || el.dataset.driveBgFixed === '1') return;
        var bg = el.getAttribute('data-bg');
        if (bg) {
            var direct = driveDirect(bg);
            if (direct !== bg) {
                el.setAttribute('data-bg', direct);
                el.dataset.driveBgFixed = '1';
            }
        }
    }

    function scan(root) {
        try {
            (root || document).querySelectorAll('img').forEach(fixImg);
            (root || document).querySelectorAll('[data-bg]').forEach(fixBgEl);
        } catch (e) { /* ignore */ }
    }

    /* Initial scan + observe added nodes so async renders are also covered. */
    function bootScan() {
        scan(document);
        if (typeof MutationObserver !== 'function') return;
        var mo = new MutationObserver(function (records) {
            records.forEach(function (r) {
                r.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) return;
                    if (n.tagName === 'IMG') fixImg(n);
                    else scan(n);
                });
                if (r.type === 'attributes' && r.target && r.target.tagName === 'IMG') {
                    /* src may be reset after our fix — re-apply only when the new value
                       is a raw Drive URL (don't loop on our own lh3 rewrite). */
                    var s = r.target.getAttribute('src') || '';
                    if (/drive\.google\.com|docs\.google\.com/i.test(s)) {
                        r.target.dataset.driveFixed = '';
                        fixImg(r.target);
                    }
                }
            });
        });
        mo.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'data-bg'] });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootScan);
    } else {
        bootScan();
    }
})();

(function () {
    'use strict';

    function esc(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    var DEFAULT_CHROME = {
        navbar: {
            logo: 'img/logo2.png',
            logo_alt: 'HOSU - Hematology & Oncology Society of Uganda',
            portal_label: 'Member Portal',
            links: [
                { label: 'Home', url: 'index.html' },
                { label: 'Events', url: 'events.html' },
                { label: 'Research', url: 'research.html' },
                { label: 'Membership', url: 'membership.html' },
                { label: 'About', url: 'about.html' },
                { label: 'Blog', url: 'blog.html' },
                { label: 'Contact', url: 'contact.html' }
            ]
        },
        footer: {
            copyright: '© 2026 Hematology & Oncology Society of Uganda. All rights reserved.',
            quick_links_title: 'Quick Links',
            quick_links: [
                { label: 'About HOSU', url: 'about.html' },
                { label: 'Membership', url: 'membership.html' },
                { label: 'Research', url: 'research.html' },
                { label: 'Events', url: 'events.html' },
                { label: 'Blog', url: 'blog.html' },
                { label: 'Contact Us', url: 'contact.html' }
            ],
            contact_title: 'Contact Us',
            contact_lines: ['Mulago Hospital Complex', 'Kampala, Uganda', 'P.O. Box 170251'],
            phone: '+256 766 529869',
            whatsapp: '+256 709 752107',
            email: 'info@hosu.or.ug',
            website: 'https://hosu.or.ug',
            social_title: 'Stay Connected',
            social_blurb: 'Follow us for updates.',
            social: { twitter: 'https://x.com/Hem0nc_Uganda' },
            support_title: 'Support',
            support_name: 'Official HOSU Support'
        }
    };

    function getChrome() {
        var boot = window.__HOSU_SITE_CHROME;
        if (boot && boot.success && boot.chrome) return boot.chrome;
        return DEFAULT_CHROME;
    }

    function buildNavbarHtml(chrome) {
        var nav = (chrome && chrome.navbar) || {};
        var links = nav.links || [];
        var linksHtml = links.map(function (l) {
            if (!l || !l.url) return '';
            return '<a href="' + esc(l.url) + '">' + esc(l.label || 'Link') + '</a>';
        }).join('');
        var logo = nav.logo || 'img/logo2.png';
        var logoAlt = nav.logo_alt || 'HOSU';
        var portal = nav.portal_label || 'Member Portal';
        return (
            '<div class="navbar-container" role="navigation" aria-label="Main navigation">' +
            '<div class="logo"><a href="index.html"><img src="' + esc(logo) + '" alt="' + esc(logoAlt) + '" loading="eager"></a></div>' +
            '<div class="nav-links">' + linksHtml +
            '<div class="login-trigger-wrap">' +
            '<a href="#" class="cta-button login-trigger" onclick="toggleLoginPopup(event)">' + esc(portal) + '</a>' +
            '<div class="login-float-popup" id="loginPopup"></div></div></div>' +
            '<div class="menu-toggle" role="button" tabindex="0" aria-label="Toggle navigation menu" aria-expanded="false">&#9776;</div></div>'
        );
    }

    function socialIcon(platform) {
        var icons = {
            linkedin: '<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z"/></svg>',
            twitter: '<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.026 15c6.038 0 9.341-5.003 9.341-9.334 0-.14 0-.282-.006-.422A6.685 6.685 0 0 0 16 3.542a6.658 6.658 0 0 1-1.889.518 3.301 3.301 0 0 0 1.447-1.817 6.533 6.533 0 0 1-2.087.793A3.286 3.286 0 0 0 7.875 6.03a9.325 9.325 0 0 1-6.767-3.429 3.289 3.289 0 0 0 1.018 4.382A3.323 3.323 0 0 1 .64 6.575v.045a3.288 3.288 0 0 0 2.632 3.218 3.203 3.203 0 0 1-.865.115 3.23 3.23 0 0 1-.614-.057 3.283 3.283 0 0 0 3.067 2.277A6.588 6.588 0 0 1 .78 13.58a6.32 6.32 0 0 1-.78-.045A9.344 9.344 0 0 0 5.026 15z"/></svg>',
            facebook: '<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z"/></svg>',
            youtube: '<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M8.051 1.999h.089c.822.003 4.987.033 6.11.335a2.01 2.01 0 0 1 1.415 1.42c.101.38.172.883.22 1.402l.01.104.022.26.008.104c.065.914.073 1.77.074 1.957v.075c-.001.194-.01 1.108-.082 2.06l-.008.105-.009.104c-.05.572-.124 1.14-.235 1.558a2.007 2.007 0 0 1-1.415 1.42c-1.16.312-5.569.334-6.18.335h-.142c-.309 0-1.587-.006-2.927-.052l-.17-.006-.087-.004-.171-.007-.171-.007c-1.11-.049-2.167-.128-2.654-.26a2.007 2.007 0 0 1-1.415-1.419c-.111-.417-.185-.986-.235-1.558L.09 9.82l-.008-.104A31.4 31.4 0 0 1 0 7.68v-.123c.002-.215.01-.958.064-1.778l.007-.103.003-.052.008-.104.022-.26.01-.104c.048-.519.119-1.023.22-1.402a2.007 2.007 0 0 1 1.415-1.42c.487-.13 1.544-.21 2.654-.26l.17-.007.172-.006.086-.003.171-.007A99.788 99.788 0 0 1 7.858 2h.193zM6.4 5.209v4.818l4.157-2.408L6.4 5.209z"/></svg>',
        };
        return icons[platform] || '';
    }

    function buildFooterHtml(chrome) {
        var ft = (chrome && chrome.footer) || {};
        var quickLinks = (ft.quick_links || []).map(function (l) {
            return '<li><a href="' + esc(l.url) + '">' + esc(l.label) + '</a></li>';
        }).join('');
        var contactLines = (ft.contact_lines || []).map(function (line) {
            return esc(line) + '<br>';
        }).join('');
        var phone = ft.phone || '';
        var whatsapp = ft.whatsapp || '';
        var email = ft.email || '';
        var website = ft.website || '';
        var social = ft.social || {};
        var socialHtml = '';
        ['linkedin', 'twitter', 'facebook', 'youtube'].forEach(function (p) {
            if (social[p]) {
                socialHtml += '<a href="' + esc(social[p]) + '" target="_blank" rel="noopener" aria-label="' + p + '">' + socialIcon(p) + '</a>';
            }
        });
        return (
            '<div class="footer-content">' +
            '<div class="footer-section"><h4>' + esc(ft.quick_links_title || 'Quick Links') + '</h4><ul>' + quickLinks + '</ul></div>' +
            '<div class="footer-section"><h4>' + esc(ft.contact_title || 'Contact Us') + '</h4><address>' +
            contactLines +
            (phone ? '<a href="tel:' + esc(phone.replace(/\s/g, '')) + '">Call: ' + esc(phone) + '</a><br>' : '') +
            (whatsapp ? '<a href="https://wa.me/' + esc(String(whatsapp).replace(/\D/g, '')) + '" target="_blank" rel="noopener">WhatsApp: ' + esc(whatsapp) + '</a><br>' : '') +
            (email ? '<a href="mailto:' + esc(email) + '">' + esc(email) + '</a><br>' : '') +
            (website ? '<a href="' + esc(website) + '" target="_blank" rel="noopener">' + esc(website.replace(/^https?:\/\//, '')) + '</a>' : '') +
            '</address></div>' +
            '<div class="footer-section"><h4>' + esc(ft.social_title || 'Stay Connected') + '</h4>' +
            '<p>' + esc(ft.social_blurb || 'Follow us for updates.') + '</p>' +
            '<div class="social-links">' + socialHtml + '</div></div>' +
            '<div class="footer-section"><h4>' + esc(ft.support_title || 'Support') + '</h4>' +
            '<p class="footer-dev-name">' + esc(ft.support_name || 'Official HOSU Support') + '</p>' +
            (email ? '<p class="footer-dev-contact"><a href="mailto:' + esc(email) + '">Email: ' + esc(email) + '</a></p>' : '') +
            (whatsapp ? '<p class="footer-dev-contact"><a href="https://wa.me/' + esc(String(whatsapp).replace(/\D/g, '')) + '" target="_blank" rel="noopener">WhatsApp: ' + esc(whatsapp) + '</a></p>' : '') +
            '</div></div>' +
            '<div class="copyright"><p>' + esc(ft.copyright || '© HOSU. All rights reserved.') + '</p></div>'
        );
    }

    function applyHomeMeta(chrome) {
        if (!chrome || !chrome.meta) return;
        var meta = chrome.meta;
        var isHome = /index\.html$/.test(window.location.pathname) || window.location.pathname.endsWith('/');
        if (isHome && meta.home_title) {
            document.title = meta.home_title;
        }
        if (isHome && meta.home_description) {
            var desc = document.querySelector('meta[name="description"]');
            if (desc) desc.setAttribute('content', meta.home_description);
        }
    }

    var chrome = getChrome();
    var NAVBAR_HTML = buildNavbarHtml(chrome);
    var FOOTER_HTML = buildFooterHtml(chrome);

    var navbar = document.querySelector('nav.navbar');
    if (navbar) navbar.innerHTML = NAVBAR_HTML;

    var footer = document.querySelector('footer');
    if (footer) footer.innerHTML = FOOTER_HTML;

    applyHomeMeta(chrome);

    if (navbar) {
        var syncScroll = function () {
            navbar.classList.toggle('scrolled', window.pageYOffset > 50);
        };
        syncScroll();
        window.addEventListener('scroll', syncScroll);
    }

    var menuToggle = document.querySelector('.menu-toggle');
    var navLinks = document.querySelector('.nav-links');

    if (menuToggle && navLinks) {
        menuToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = navLinks.classList.toggle('active');
            menuToggle.setAttribute('aria-expanded', String(open));
            menuToggle.innerHTML = open ? '&#10005;' : '&#9776;';
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.nav-links') && !e.target.closest('.menu-toggle')) {
                navLinks.classList.remove('active');
                menuToggle.setAttribute('aria-expanded', 'false');
                menuToggle.innerHTML = '&#9776;';
            }
        });
    }

    (function () {
        var IDLE_MS = 15 * 60 * 1000;
        var WARN_MS = 60 * 1000;
        var HOME = 'index.html';
        var _idleT = null, _warnT = null, _ticker = null, _warned = false;

        function resetPublicIdle() {
            clearTimeout(_idleT);
            clearTimeout(_warnT);
            clearInterval(_ticker);
            _warned = false;
            var bar = document.getElementById('_pub-idle-bar');
            if (bar) bar.style.display = 'none';
            _warnT = setTimeout(showPublicWarn, IDLE_MS - WARN_MS);
            _idleT = setTimeout(function () { window.location.replace(HOME); }, IDLE_MS);
        }

        function showPublicWarn() {
            if (_warned) return;
            _warned = true;
            var bar = document.getElementById('_pub-idle-bar');
            if (!bar) {
                bar = document.createElement('div');
                bar.id = '_pub-idle-bar';
                bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#fef3c7;border-bottom:2px solid #f59e0b;padding:.55rem 1.25rem;display:flex;align-items:center;justify-content:space-between;font-family:Inter,sans-serif;font-size:.82rem;color:#92400e;gap:.5rem;';
                bar.innerHTML = '<span>Redirecting to home in <strong id="_pub-idle-secs">60</strong>s due to inactivity.</span>'
                    + '<button id="_pub-idle-stay" style="padding:.3rem .8rem;border-radius:6px;border:1.5px solid #d97706;background:#fff;color:#92400e;font-weight:600;cursor:pointer;font-size:.75rem;font-family:inherit;">Stay on Page</button>';
                document.body.appendChild(bar);
                document.getElementById('_pub-idle-stay').addEventListener('click', resetPublicIdle);
            }
            bar.style.display = 'flex';
            var secs = 60;
            document.getElementById('_pub-idle-secs').textContent = secs;
            _ticker = setInterval(function () {
                secs--;
                var s = document.getElementById('_pub-idle-secs');
                if (s) s.textContent = secs;
                if (secs <= 0) { clearInterval(_ticker); window.location.replace(HOME); }
            }, 1000);
        }

        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (e) {
            document.addEventListener(e, resetPublicIdle, { passive: true });
        });
        resetPublicIdle();
    }());
})();
