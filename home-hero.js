/**
 * Loads admin-managed hero slides from the API and initializes the carousel.
 * Supports multiple images per slide (URL, upload, Drive folders) with optional
 * per-image title/body/CTA overrides that sync as images rotate.
 */
(function () {
    'use strict';

    window.heroPopupData = window.heroPopupData || {};

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return escHtml(str).replace(/'/g, '&#39;');
    }

    function slideKey(slide, index) {
        if (slide.slide_key) return slide.slide_key;
        return 'hero-slide-' + (slide.id || index);
    }

    function buildPillsHtml(pills) {
        if (!pills || !pills.length) return '';
        return '<div class="hero-pills hero-dynamic-pills">' +
            pills.map(function (p) {
                return '<span class="hero-pill">' + escHtml(p) + '</span>';
            }).join('') +
            '</div>';
    }

    function buildCtaHtml(label, url, secondaryLabel, secondaryUrl) {
        var parts = [];
        if (label && url) {
            parts.push(
                '<a href="' + escHtml(url) + '" class="interactive-button hero-cta-primary">' +
                escHtml(label) + ' <span class="icon">→</span></a>'
            );
        }
        if (secondaryLabel && secondaryUrl) {
            parts.push(
                '<a href="' + escHtml(secondaryUrl) + '" class="btn btn-secondary">' +
                escHtml(secondaryLabel) + '</a>'
            );
        }
        if (!parts.length) return '';
        return '<div class="cta-buttons">' + parts.join('') + '</div>';
    }

    function buildReadMoreHtml(slide, key) {
        if (!slide.popup_html && !slide.popup_title) return '';
        var label = slide.read_more_label || 'Read More →';
        window.heroPopupData[key] = {
            title: slide.popup_title || slide.title,
            content: slide.popup_html || ''
        };
        return '<a href="#" class="hero-readmore" onclick="toggleFloatPopup(this, \'' +
            escHtml(key).replace(/'/g, "\\'") +
            '\'); return false;">' + escHtml(label) +
            '<div class="hero-float-popup" data-popup="' + escHtml(key) + '"></div></a>';
    }

    function buildSlideHtml(slide, index) {
        var key = slideKey(slide, index);
        var badge = slide.badge_label
            ? '<span class="featured-label hero-dynamic-badge"><span class="featured-label-icon">📢</span> ' + escHtml(slide.badge_label) + '</span>'
            : '<span class="featured-label hero-dynamic-badge" style="display:none"></span>';
        var titleHtml = slide.title || '';
        var bodyHtml = slide.body
            ? '<p class="hero-dynamic-body">' + slide.body + '</p>'
            : '<p class="hero-dynamic-body" style="display:none"></p>';
        var pillsHtml = buildPillsHtml(slide.pills);
        var ctaHtml = buildCtaHtml(
            slide.cta_label,
            slide.cta_url,
            slide.cta_secondary_label,
            slide.cta_secondary_url
        );
        return '<div class="hero-slide' + (index === 0 ? ' active' : '') + '" data-slide="' + escHtml(key) + '"' +
            ' data-hero-slide="' + index + '"' +
            ' data-default-title="' + escAttr(stripTags(slide.title || '')) + '"' +
            ' data-default-body="' + escAttr(stripTags(slide.body || '')) + '"' +
            ' data-default-cta-label="' + escAttr(slide.cta_label || '') + '"' +
            ' data-default-cta-url="' + escAttr(slide.cta_url || '') + '">' +
            '<div class="hero-copy-dynamic">' +
            badge +
            '<h1 class="hero-dynamic-title">' + titleHtml + '</h1>' +
            bodyHtml +
            pillsHtml +
            '<div class="hero-dynamic-cta">' + ctaHtml + '</div>' +
            buildReadMoreHtml(slide, key) +
            '</div></div>';
    }

    function stripTags(html) {
        if (!html) return '';
        var d = document.createElement('div');
        d.innerHTML = html;
        return (d.textContent || d.innerText || '').trim();
    }

    function resolveHeroDisplayUrl(img) {
        if (!img) return '';
        var u = img.display_url || img.url || '';
        if (!u || /\/folders\//i.test(u)) return '';
        return u;
    }

    function slideImages(slide) {
        if (slide.images && slide.images.length) {
            return slide.images.map(function (img) {
                return {
                    url: img.url || '',
                    display_url: resolveHeroDisplayUrl(img),
                    alt: img.alt || '',
                    title: img.title || '',
                    body: img.body || img.headline || '',
                    cta_label: img.cta_label || '',
                    cta_url: img.cta_url || '',
                };
            }).filter(function (img) {
                return img.display_url || (img.url && !/\/folders\//i.test(img.url));
            });
        }
        if (slide.image_path && !/\/folders\//i.test(slide.image_path)) {
            return [{
                url: slide.image_path,
                display_url: slide.image_path,
                alt: slide.image_alt || '',
                title: '',
                body: '',
                cta_label: '',
                cta_url: '',
            }];
        }
        return [];
    }

    function mediaDataAttrs(img) {
        var attrs = '';
        if (img.title) attrs += ' data-media-title="' + escAttr(img.title) + '"';
        if (img.body) attrs += ' data-media-body="' + escAttr(img.body) + '"';
        if (img.cta_label) attrs += ' data-media-cta-label="' + escAttr(img.cta_label) + '"';
        if (img.cta_url) attrs += ' data-media-cta-url="' + escAttr(img.cta_url) + '"';
        return attrs;
    }

    function renderSlides(slides) {
        var mount = document.getElementById('hero-slides-mount');
        var dotsMount = document.getElementById('hero-indicators-mount');
        var bgMount = document.getElementById('hero-bg-mount');
        if (!mount || !dotsMount || !bgMount) return;

        if (!slides.length) {
            mount.innerHTML =
                '<div class="hero-slide active" data-slide="fallback">' +
                '<h1>Welcome to HOSU</h1>' +
                '<p>Add hero slides from the admin panel under Site Content.</p>' +
                '</div>';
            dotsMount.innerHTML = '<span class="hero-dot active" data-index="0"></span>';
            bgMount.innerHTML = '<div class="hero-background active"></div>';
            return;
        }

        mount.innerHTML = slides.map(buildSlideHtml).join('');
        dotsMount.innerHTML = slides.map(function (_, i) {
            return '<span class="hero-dot' + (i === 0 ? ' active' : '') + '" data-index="' + i + '"></span>';
        }).join('');

        var bgHtml = '';
        slides.forEach(function (slide, i) {
            var imgs = slideImages(slide);
            if (!imgs.length) {
                bgHtml += '<div class="hero-background' + (i === 0 ? ' active' : '') +
                    '" data-hero-slide="' + i + '" data-hero-media="0"></div>';
                return;
            }
            imgs.forEach(function (img, j) {
                var src = img.display_url || img.url;
                var bg = src ? ' data-bg="' + escAttr(src) + '"' : '';
                var alt = img.alt ? ' aria-label="' + escAttr(img.alt) + '"' : '';
                bgHtml += '<div id="hero-bg-' + i + '-' + j + '" class="hero-background' +
                    (i === 0 && j === 0 ? ' active' : '') + '" data-hero-slide="' + i +
                    '" data-hero-media="' + j + '"' + bg + alt + mediaDataAttrs(img) + '></div>';
            });
        });
        bgMount.innerHTML = bgHtml;

        slides.forEach(function (slide, i) {
            var slideEl = mount.querySelector('.hero-slide[data-hero-slide="' + i + '"]');
            if (slideEl) {
                slideEl.setAttribute('data-media-count', String(slideImages(slide).length || 1));
            }
        });
    }

    function initHero() {
        if (typeof HeroSlideshow !== 'undefined') {
            HeroSlideshow.init();
        }
        bindJoinButtonGlow();
    }

    function bindJoinButtonGlow() {
        document.querySelectorAll('#home .hero-cta-primary').forEach(function (jb) {
            if (jb.dataset.glowBound) return;
            jb.dataset.glowBound = '1';
            jb.addEventListener('mouseenter', function () { jb.classList.add('glow-effect'); });
            jb.addEventListener('mouseleave', function () { jb.classList.remove('glow-effect'); });
        });
    }

    function loadHeroFromBootstrap(boot) {
        if (boot && boot.success && Array.isArray(boot.slides)) {
            renderSlides(boot.slides);
            initHero();
            return true;
        }
        return false;
    }

    window.__HOSU_HERO_READY = new Promise(function (resolve) {
        var boot = window.__HOSU_PAGE_BOOTSTRAP;
        if (loadHeroFromBootstrap(boot)) {
            resolve();
            return;
        }

        fetch('api.php?action=get_home_hero', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var slides = (data && data.success && data.slides) ? data.slides : [];
                renderSlides(slides);
                initHero();
                resolve();
            })
            .catch(function () {
                renderSlides([]);
                initHero();
                resolve();
            });
    });
})();
