/* ============================================================
   SHARED COMPONENTS — shared-components.js
   Navbar behaviour + canonical footer injection.
   Include at the BOTTOM of every page, before page-specific JS.
   ============================================================ */
(function () {
    'use strict';

    /* ── Canonical Footer HTML ─────────────────────────────── */
    var FOOTER_HTML =
        '<div class="footer-content">' +
            '<div class="footer-section">' +
                '<h4>Quick Links</h4>' +
                '<ul>' +
                    '<li><a href="about.html">About HOSU</a></li>' +
                    '<li><a href="membership.html">Membership</a></li>' +
                    '<li><a href="research.html">Research</a></li>' +
                    '<li><a href="events.html">Events</a></li>' +
                    '<li><a href="blog.html">Blog</a></li>' +
                '</ul>' +
            '</div>' +
            '<div class="footer-section">' +
                '<h4>Contact Us</h4>' +
                '<address>' +
                    'HOSU Headquarters<br>' +
                    'Kampala, Uganda<br>' +
                    '<a href="tel:+256766529869">+256 766 529869</a><br>' +
                    '<a href="tel:+256709752107">+256 709 752107</a><br>' +
                    '<a href="mailto:info@hosu.org">info@hosu.org</a>' +
                '</address>' +
            '</div>' +
            '<div class="footer-section">' +
                '<h4>Stay Connected</h4>' +
                '<p>Follow us for updates.</p>' +
                '<div class="social-links">' +
                    '<a href="#" aria-label="LinkedIn"><svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z"/></svg></a>' +
                    '<a href="#" aria-label="Twitter"><svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.026 15c6.038 0 9.341-5.003 9.341-9.334 0-.14 0-.282-.006-.422A6.685 6.685 0 0 0 16 3.542a6.658 6.658 0 0 1-1.889.518 3.301 3.301 0 0 0 1.447-1.817 6.533 6.533 0 0 1-2.087.793A3.286 3.286 0 0 0 7.875 6.03a9.325 9.325 0 0 1-6.767-3.429 3.289 3.289 0 0 0 1.018 4.382A3.323 3.323 0 0 1 .64 6.575v.045a3.288 3.288 0 0 0 2.632 3.218 3.203 3.203 0 0 1-.865.115 3.23 3.23 0 0 1-.614-.057 3.283 3.283 0 0 0 3.067 2.277A6.588 6.588 0 0 1 .78 13.58a6.32 6.32 0 0 1-.78-.045A9.344 9.344 0 0 0 5.026 15z"/></svg></a>' +
                    '<a href="#" aria-label="Facebook"><svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z"/></svg></a>' +
                    '<a href="#" aria-label="YouTube"><svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M8.051 1.999h.089c.822.003 4.987.033 6.11.335a2.01 2.01 0 0 1 1.415 1.42c.101.38.172.883.22 1.402l.01.104.022.26.008.104c.065.914.073 1.77.074 1.957v.075c-.001.194-.01 1.108-.082 2.06l-.008.105-.009.104c-.05.572-.124 1.14-.235 1.558a2.007 2.007 0 0 1-1.415 1.42c-1.16.312-5.569.334-6.18.335h-.142c-.309 0-1.587-.006-2.927-.052l-.17-.006-.087-.004-.171-.007-.171-.007c-1.11-.049-2.167-.128-2.654-.26a2.007 2.007 0 0 1-1.415-1.419c-.111-.417-.185-.986-.235-1.558L.09 9.82l-.008-.104A31.4 31.4 0 0 1 0 7.68v-.123c.002-.215.01-.958.064-1.778l.007-.103.003-.052.008-.104.022-.26.01-.104c.048-.519.119-1.023.22-1.402a2.007 2.007 0 0 1 1.415-1.42c.487-.13 1.544-.21 2.654-.26l.17-.007.172-.006.086-.003.171-.007A99.788 99.788 0 0 1 7.858 2h.193zM6.4 5.209v4.818l4.157-2.408L6.4 5.209z"/></svg></a>' +
                '</div>' +
            '</div>' +
            '<div class="footer-section">' +
                '<h4>Developer</h4>' +
                '<p class="footer-dev-name">TattuIntel</p>' +
                '<p class="footer-dev-contact"><a href="tel:+256770495837">+256 770 495837</a></p>' +
                '<p class="footer-dev-contact"><a href="mailto:tattuintel@gmail.com">tattuintel@gmail.com</a></p>' +
            '</div>' +
        '</div>' +
        '<div class="copyright">' +
            '<p>&copy; 2026 Hematology &amp; Oncology Society of Uganda. All rights reserved.</p>' +
        '</div>';

    /* ── Canonical Navbar inner HTML ───────────────────────── */
    var NAVBAR_HTML =
        '<div class="navbar-container" role="navigation" aria-label="Main navigation">' +
            '<div class="logo">' +
                '<a href="index.html"><img src="img/logo2.png" alt="HOSU - Hematology &amp; Oncology Society of Uganda" loading="eager"></a>' +
            '</div>' +
            '<div class="nav-links">' +
                '<a href="index.html">Home</a>' +
                '<a href="events.html">Events</a>' +
                '<a href="research.html">Research</a>' +
                '<a href="membership.html">Membership</a>' +
                '<a href="about.html">About</a>' +
                '<a href="blog.html">Blog</a>' +
                '<a href="contact.html">Contact</a>' +
                '<div class="login-trigger-wrap">' +
                    '<a href="#" class="cta-button login-trigger" onclick="toggleLoginPopup(event)">Member Portal</a>' +
                    '<div class="login-float-popup" id="loginPopup"></div>' +
                '</div>' +
            '</div>' +
            '<div class="menu-toggle" role="button" tabindex="0" aria-label="Toggle navigation menu" aria-expanded="false">&#9776;</div>' +
        '</div>';

    /* ── Inject into DOM ──────────────────────────────────── */
    var navbar = document.querySelector('nav.navbar');
    if (navbar) navbar.innerHTML = NAVBAR_HTML;

    var footer = document.querySelector('footer');
    if (footer) footer.innerHTML = FOOTER_HTML;

    /* ── Navbar scroll effect ─────────────────────────────── */
    if (navbar) {
        var syncScroll = function () {
            navbar.classList.toggle('scrolled', window.pageYOffset > 50);
        };
        syncScroll();
        window.addEventListener('scroll', syncScroll);
    }

    /* ── Mobile menu toggle ───────────────────────────────── */
    var menuToggle = document.querySelector('.menu-toggle');
    var navLinks   = document.querySelector('.nav-links');

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
})();
