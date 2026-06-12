/**
 * Loads admin-managed hero slides from the API and initializes the carousel.
 * Supports multiple images per slide (URL, upload, Drive folders) with optional
 * per-image title/body/CTA overrides. One random gallery image is shown each
 * time its slide becomes active in the carousel (no mid-slide image cycling).
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

  var DEFAULT_HERO_SLIDES = [
        {
            slide_key: 'intro',
            title: 'A Uganda Free from Cancer & Blood Diseases',
            body: 'The Hematology & Oncology Society of Uganda (HOSU) is a nonprofit organization dedicated to eliminating suffering from cancer and blood diseases through care, research, education, and advocacy.',
            cta_label: 'Join Our Community →',
            cta_url: 'membership.html',
            pills: []
        },
        {
            slide_key: 'surgical-oncology',
            title: 'Surgical Oncology',
            body: 'Focuses on the diagnosis, staging, and treatment of cancer through surgery — often combined with other therapies for the best outcomes.',
            pills: ['Diagnosis', 'Staging', 'Curative', 'Palliative', 'Reconstructive'],
            read_more_label: 'Read More →'
        },
        {
            slide_key: 'medical-oncology',
            title: 'Medical Oncology',
            body: 'Treats cancer with medicines — chemotherapy, targeted therapy, immunotherapy, and hormonal therapy — tailored to each patient\'s needs.',
            pills: ['Chemotherapy', 'Immunotherapy', 'Targeted Therapy', 'Hormone Therapy', 'Precision Medicine'],
            read_more_label: 'Read More →'
        },
        {
            slide_key: 'radiation-oncology',
            title: 'Radiation Oncology',
            body: 'Uses high-energy radiation to target and destroy cancer cells while sparing healthy tissue, alone or combined with other treatments.',
            pills: ['External Beam', 'Brachytherapy', 'Stereotactic', 'Proton Therapy', 'Image-Guided'],
            read_more_label: 'Read More →'
        },
        {
            slide_key: 'pediatric-oncology',
            title: 'Pediatric Oncology',
            body: 'Focuses on cancers in children and adolescents — leukemia, brain tumors, and sarcomas — with care tailored to young patients.',
            pills: ['Leukemia', 'Brain Tumors', 'Sarcoma Care', 'Supportive Care', 'Follow-Up'],
            read_more_label: 'Read More →'
        }
    ];

    function effectiveSlides(slides) {
        return slides && slides.length ? slides : DEFAULT_HERO_SLIDES;
    }

    function renderSlides(slides) {
        slides = effectiveSlides(slides);
        var mount = document.getElementById('hero-slides-mount');
        var dotsMount = document.getElementById('hero-indicators-mount');
        var bgMount = document.getElementById('hero-bg-mount');
        if (!mount || !dotsMount || !bgMount) return;

        mount.innerHTML = slides.map(buildSlideHtml).join('');
        dotsMount.innerHTML = slides.map(function (_, i) {
            return '<span class="hero-dot' + (i === 0 ? ' active' : '') + '" data-index="' + i + '"></span>';
        }).join('');

        var bgHtml = '';
        slides.forEach(function (slide, i) {
            var imgs = slideImages(slide);
            if (!imgs.length) {
                bgHtml += '<div class="hero-background' +
                    '" data-hero-slide="' + i + '" data-hero-media="0"></div>';
                return;
            }
            imgs.forEach(function (img, j) {
                var src = img.display_url || img.url;
                var bg = src ? ' data-bg="' + escAttr(src) + '"' : '';
                var alt = img.alt ? ' aria-label="' + escAttr(img.alt) + '"' : '';
                bgHtml += '<div id="hero-bg-' + i + '-' + j + '" class="hero-background' +
                    '" data-hero-slide="' + i +
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
        if (boot && boot.success) {
            renderSlides(Array.isArray(boot.slides) ? boot.slides : []);
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

