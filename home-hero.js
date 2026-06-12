/**
 * Loads admin-managed hero slides from the API and initializes the carousel.
 * Two background modes (admin-configurable):
 * - per_slide: images assigned to each slide; one random image when that slide activates
 * - global_pool: shared image pool; random background as text slides change (no assignment)
 */
(function () {
    'use strict';

    window.heroPopupData = window.heroPopupData || {};
    var heroImageMode = 'per_slide';
    var heroPoolImages = [];
    var _lastHeroFingerprint = '';

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

    function hosuAssetUrl(path) {
        if (!path) return '';
        var s = String(path).trim();
        if (/^https?:\/\//i.test(s)) return s;
        if (s.charAt(0) === '/') return s;
        var base = window.HOSU_ASSET_BASE || '';
        if (!base) {
            var scripts = document.getElementsByTagName('script');
            for (var i = 0; i < scripts.length; i++) {
                var src = scripts[i].src || '';
                if (src && /\/(?:home-hero\.js|page_bootstrap\.php|index\.html)(?:\?|$)/i.test(src)) {
                    base = src.replace(/\/[^/]*(?:\?.*)?$/, '');
                    break;
                }
            }
        }
        if (base && base.charAt(base.length - 1) !== '/') base += '/';
        return (base || '') + s.replace(/^\.\//, '');
    }

    function extractDriveFileId(url) {
        if (!url) return '';
        var m = String(url).match(/drive_image\.php\?id=([a-zA-Z0-9_-]+)/i);
        if (m) return m[1];
        m = String(url).match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
        if (m) return m[1];
        m = String(url).match(/\/d\/([a-zA-Z0-9_-]+)/);
        if (m) return m[1];
        m = String(url).match(/[?&]id=([a-zA-Z0-9_-]+)/);
        return m ? m[1] : '';
    }

    function resolveHeroDisplayUrl(img) {
        if (!img) return '';
        var u = img.display_url || img.url || '';
        if (!u || /\/folders\//i.test(u)) return '';
        if (/drive\.google|docs\.google/i.test(u) && u.indexOf('drive_image.php') === -1) {
            var driveId = extractDriveFileId(u);
            if (driveId) {
                return hosuAssetUrl('drive_image.php?id=' + encodeURIComponent(driveId) + '&w=1400');
            }
        }
        return hosuAssetUrl(u);
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
                display_url: resolveHeroDisplayUrl({ url: slide.image_path, display_url: slide.image_path }),
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

    function poolImagesFromList(images) {
        return (images || []).map(function (img) {
            return {
                url: img.url || '',
                display_url: resolveHeroDisplayUrl(img),
                alt: img.alt || '',
            };
        }).filter(function (img) {
            return img.display_url || (img.url && !/\/folders\//i.test(img.url));
        });
    }

    function buildBackgroundDiv(id, attrs, img) {
        var src = img.display_url || img.url;
        var raw = img.url || src;
        var bg = src ? ' data-bg="' + escAttr(src) + '"' : '';
        var orig = raw && raw !== src ? ' data-original-url="' + escAttr(raw) + '"' : '';
        var driveId = extractDriveFileId(src) || extractDriveFileId(raw);
        var driveAttr = driveId ? ' data-drive-id="' + escAttr(driveId) + '"' : '';
        var alt = img.alt ? ' aria-label="' + escAttr(img.alt) + '"' : '';
        var idAttr = id ? ' id="' + escAttr(id) + '"' : '';
        return '<div' + idAttr + ' class="hero-background"' + attrs + bg + orig + driveAttr + alt + mediaDataAttrs(img) + '></div>';
    }

    function renderSlides(slides) {
        slides = effectiveSlides(slides);
        var mount = document.getElementById('hero-slides-mount');
        var dotsMount = document.getElementById('hero-indicators-mount');
        var bgMount = document.getElementById('hero-bg-mount');
        if (!mount || !dotsMount || !bgMount) return;

        window.HOSU_HERO_IMAGE_MODE = heroImageMode;

        mount.innerHTML = slides.map(buildSlideHtml).join('');
        dotsMount.innerHTML = slides.map(function (_, i) {
            return '<span class="hero-dot' + (i === 0 ? ' active' : '') + '" data-index="' + i + '"></span>';
        }).join('');

        var bgHtml = '';
        var useGlobalPool = heroPoolImages.length > 0
            && (heroImageMode === 'global_pool' || heroPoolImages.length >= 2);

        if (useGlobalPool) {
            var poolImgs = poolImagesFromList(heroPoolImages);
            poolImgs.forEach(function (img, j) {
                bgHtml += buildBackgroundDiv(
                    'hero-pool-' + j,
                    ' data-hero-pool="1" data-hero-media="' + j + '"',
                    img
                );
            });
        } else {
            slides.forEach(function (slide, i) {
                var imgs = slideImages(slide);
                if (!imgs.length) {
                    bgHtml += '<div class="hero-background" data-hero-slide="' + i + '" data-hero-media="0"></div>';
                    return;
                }
                imgs.forEach(function (img, j) {
                    bgHtml += buildBackgroundDiv(
                        'hero-bg-' + i + '-' + j,
                        ' data-hero-slide="' + i + '" data-hero-media="' + j + '"',
                        img
                    );
                });
            });
        }
        bgMount.innerHTML = bgHtml;

        if (useGlobalPool) {
            window.HOSU_HERO_IMAGE_MODE = 'global_pool';
        }

        if (typeof window.hosuLoadAllHeroBackgrounds === 'function') {
            window.hosuLoadAllHeroBackgrounds();
        }

        slides.forEach(function (slide, i) {
            var slideEl = mount.querySelector('.hero-slide[data-hero-slide="' + i + '"]');
            if (slideEl) {
                var count = useGlobalPool ? heroPoolImages.length : (slideImages(slide).length || 1);
                slideEl.setAttribute('data-media-count', String(count));
            }
        });
    }

    function heroFingerprint(slides, mode, pool) {
        var slideFp = effectiveSlides(slides).map(function (s) {
            var imgs = (s.images || []).map(function (img) {
                return (img.display_url || img.url || '') + ':' + (img.title || '') + ':' + (img.body || '');
            }).join(',');
            return [
                s.id || s.slide_key || '',
                s.title || '',
                s.body || '',
                s.badge_label || '',
                s.sort_order || 0,
                s.is_active === false || s.is_active === 0 || s.is_active === '0' ? '0' : '1',
                s.cta_label || '',
                s.cta_url || '',
                s.image_path || '',
                imgs,
            ].join('|');
        }).join(';;');
        var poolFp = (pool || []).map(function (img) {
            return img.display_url || img.url || '';
        }).join(',');
        return (mode || 'per_slide') + '::' + slideFp + '::' + poolFp;
    }

    function syncBootstrapHero(data) {
        if (!window.__HOSU_PAGE_BOOTSTRAP || !data) return;
        window.__HOSU_PAGE_BOOTSTRAP.slides = data.slides || [];
        window.__HOSU_PAGE_BOOTSTRAP.image_mode = data.image_mode;
        window.__HOSU_PAGE_BOOTSTRAP.pool_images = data.pool_images || [];
    }

    function initHero(opts) {
        opts = opts || {};
        if (typeof HeroSlideshow !== 'undefined') {
            if (opts.preserveIndex && typeof HeroSlideshow.reinit === 'function') {
                HeroSlideshow.reinit({
                    preserveIndex: true,
                    index: typeof opts.index === 'number' ? opts.index : HeroSlideshow.getIndex(),
                });
            } else {
                HeroSlideshow.init();
            }
        }
        bindJoinButtonGlow();
    }

    function applyHeroData(data, opts) {
        opts = opts || {};
        if (!data) return false;

        applyHeroImageConfig(data);
        var slides = (data.success && data.slides) ? data.slides : [];
        var fp = heroFingerprint(slides, heroImageMode, heroPoolImages);
        if (!opts.force && fp === _lastHeroFingerprint) return false;
        _lastHeroFingerprint = fp;

        var preserveIndex = opts.preserveIndex !== false;
        var currentIndex = 0;
        if (preserveIndex && typeof HeroSlideshow !== 'undefined' && typeof HeroSlideshow.getIndex === 'function') {
            currentIndex = HeroSlideshow.getIndex();
        }

        window.heroPopupData = {};
        renderSlides(slides);
        initHero({ preserveIndex: preserveIndex, index: currentIndex });
        syncBootstrapHero(data);
        return true;
    }

    function fetchHeroFromApi() {
        return fetch('api.php?action=get_home_hero', {
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function refreshQuietly(force) {
        if (!force && document.hidden) return Promise.resolve(false);
        return fetchHeroFromApi()
            .then(function (data) {
                if (!data || !data.success) return false;
                return applyHeroData(data, { preserveIndex: true, force: !!force });
            })
            .catch(function () {
                return false;
            });
    }

    function bindJoinButtonGlow() {
        document.querySelectorAll('#home .hero-cta-primary').forEach(function (jb) {
            if (jb.dataset.glowBound) return;
            jb.dataset.glowBound = '1';
            jb.addEventListener('mouseenter', function () { jb.classList.add('glow-effect'); });
            jb.addEventListener('mouseleave', function () { jb.classList.remove('glow-effect'); });
        });
    }

    function applyHeroImageConfig(data) {
        heroImageMode = (data && data.image_mode === 'global_pool') ? 'global_pool' : 'per_slide';
        heroPoolImages = (data && Array.isArray(data.pool_images)) ? data.pool_images : [];
    }

    function loadHeroFromBootstrap(boot) {
        if (boot && boot.success) {
            applyHeroData({
                success: true,
                slides: Array.isArray(boot.slides) ? boot.slides : [],
                image_mode: boot.image_mode,
                pool_images: boot.pool_images,
            }, { preserveIndex: false, force: true });
            return true;
        }
        return false;
    }

    window.HOSU_HERO = {
        apply: function (data, opts) {
            return applyHeroData(data, Object.assign({ force: true }, opts || {}));
        },
        refreshQuietly: refreshQuietly,
        reload: function () {
            return fetchHeroFromApi().then(function (data) {
                applyHeroData(data, { preserveIndex: true, force: true });
                return data;
            });
        },
    };

    window.__HOSU_HERO_READY = new Promise(function (resolve) {
        var boot = window.__HOSU_PAGE_BOOTSTRAP;
        if (loadHeroFromBootstrap(boot)) {
            resolve();
            return;
        }

        fetchHeroFromApi()
            .then(function (data) {
                applyHeroData(data, { preserveIndex: false, force: true });
                resolve();
            })
            .catch(function () {
                applyHeroData({ success: true, slides: [] }, { preserveIndex: false, force: true });
                resolve();
            });
    });
})();

