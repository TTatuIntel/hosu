/**
 * Homepage Ongoing Now — live events only; gallery images rotate dynamically per event.
 */
(function () {
    'use strict';

    function getCarouselInterval() {
        var ms = window.HOSU_CAROUSEL_INTERVAL;
        return (typeof ms === 'number' && ms >= 3000) ? ms : 6000;
    }

    var REFRESH_MS = 45000;
    var _carouselTimer = null;
    var _mediaTimers = {};
    var _slideIndex = 0;
    var _mediaIndexBySlide = {};
    var _lastSlideCount = 0;
    var _lastSpotlightFingerprint = '';

    function esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function cssUrl(url) {
        return String(url).replace(/\\/g, '\\\\').replace(/'/g, '%27').replace(/"/g, '%22');
    }

    var DISPLAY_MAX_WIDTH = 1280;

    function resolveMediaUrl(url) {
        if (!url) return '';
        var s = String(url).trim();
        if (!s) return '';
        if (/^https?:\/\//i.test(s) || s.indexOf('//') === 0 || s.indexOf('data:') === 0) {
            return displayImageUrl(s);
        }
        if (s.charAt(0) === '/') return s;
        try {
            var base = window.location.pathname.replace(/[^/]+$/, '');
            return base + s.replace(/^\.\//, '');
        } catch (e) {
            return s;
        }
    }

    function extractDriveFileId(s) {
        if (!s) return '';
        var str = String(s);
        var m = str.match(/drive_image\.php\?id=([a-zA-Z0-9_-]+)/i);
        if (m) return m[1];
        m = str.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
        if (!m) m = str.match(/\/d\/([a-zA-Z0-9_-]+)/);
        if (!m) m = str.match(/[?&]id=([a-zA-Z0-9_-]+)/);
        return m ? m[1] : '';
    }

    function driveProxyUrl(fileId, width) {
        var w = width || DISPLAY_MAX_WIDTH;
        return 'drive_image.php?id=' + encodeURIComponent(fileId) + '&w=' + w;
    }

    function driveDirectUrls(fileId) {
        return [
            driveProxyUrl(fileId),
            'https://drive.google.com/thumbnail?id=' + encodeURIComponent(fileId) + '&sz=w' + DISPLAY_MAX_WIDTH,
            'https://drive.google.com/uc?export=view&id=' + encodeURIComponent(fileId),
            'https://lh3.googleusercontent.com/d/' + fileId + '=w' + DISPLAY_MAX_WIDTH,
        ];
    }

    /* Google Drive file links → same-origin proxy (reliable in all browsers). */
    function normalizeDriveUrl(s) {
        var id = extractDriveFileId(s);
        return id ? driveProxyUrl(id) : s;
    }

    function displayImageUrl(url) {
        if (!url) return '';
        var s = String(url).trim();
        if (/drive\.google\.com|docs\.google\.com|drive_image\.php/i.test(s)) {
            return normalizeDriveUrl(s);
        }
        if (/images\.unsplash\.com/i.test(s)) {
            try {
                var u = new URL(s, window.location.origin);
                u.searchParams.set('w', String(DISPLAY_MAX_WIDTH));
                if (!u.searchParams.has('q')) u.searchParams.set('q', '88');
                if (!u.searchParams.has('auto')) u.searchParams.set('auto', 'format');
                if (!u.searchParams.has('fit')) u.searchParams.set('fit', 'max');
                return u.toString();
            } catch (e) {
                return s;
            }
        }
        return s;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.slice(0, len).replace(/\s+\S*$/, '') + '…' : str;
    }

    function firstLine(str) {
        if (!str) return '';
        return String(str).split(/\n/)[0].trim();
    }

    function shortenMeta(value, max) {
        return truncate(firstLine(value), max);
    }

    function shortBadge() {
        return 'LIVE NOW';
    }

    function pickDescription(slide) {
        return truncate(slide.headline || slide.body || '', 120);
    }

    function buildPills(meta) {
        var pills = [];
        if (meta.date) pills.push({ icon: '🗓', text: shortenMeta(meta.date, 22) });
        if (meta.location) pills.push({ icon: '📍', text: shortenMeta(meta.location, 26) });
        if (meta.status) pills.push({ icon: '⏱', text: shortenMeta(meta.status, 16) });
        else if (meta.speakers && pills.length < 3) {
            pills.push({ icon: '🎤', text: shortenMeta(meta.speakers, 22) });
        }
        return pills.slice(0, 3);
    }

    function isVideoUrl(url) {
        if (!url) return false;
        return /\.(mp4|webm|ogg|mov)(\?|$)/i.test(url) || /youtube\.com|youtu\.be|vimeo\.com/i.test(url);
    }

    function normalizeSlideMedia(slide) {
        var list = [];
        if (slide.media && slide.media.length) {
            slide.media.forEach(function (m) {
                if (!m) return;
                var raw = m.display_url || m.url || '';
                if (!raw) return;
                list.push({
                    type: (m.type === 'video' || isVideoUrl(raw)) ? 'video' : 'image',
                    url: raw,
                    fullUrl: m.url || raw,
                    drive_file_id: m.drive_file_id || extractDriveFileId(raw) || extractDriveFileId(m.url || ''),
                    title: m.title || '',
                    headline: m.headline || m.body || '',
                    body: m.body || '',
                    cta_label: m.cta_label || '',
                    cta_url: m.cta_url || '',
                });
            });
        }
        if (!list.length && slide.image) {
            var img = slide.image;
            list.push({ type: isVideoUrl(img) ? 'video' : 'image', url: img });
        }
        if (!list.length && slide.video_url) {
            list.push({ type: 'video', url: slide.video_url });
        }
        return list.filter(function (m) {
            return m.url && resolveMediaUrl(m.url);
        });
    }

    function mediaDataAttrs(media) {
        var attrs = '';
        if (media.title) attrs += ' data-media-title="' + esc(media.title) + '"';
        if (media.headline) attrs += ' data-media-headline="' + esc(media.headline) + '"';
        if (media.body) attrs += ' data-media-body="' + esc(media.body) + '"';
        if (media.cta_label) attrs += ' data-media-cta-label="' + esc(media.cta_label) + '"';
        if (media.cta_url) attrs += ' data-media-cta-url="' + esc(media.cta_url) + '"';
        return attrs;
    }

    function buildCtaHtml(ctaLabel, ctaUrl) {
        if (!ctaLabel || !ctaUrl) return '';
        return '<div class="cta-buttons"><a href="' + esc(ctaUrl) + '" class="interactive-button">' +
            esc(truncate(ctaLabel, 22)) + ' <span class="icon">→</span></a></div>';
    }

    function resolveMediaCopy(slideEl, bgEl) {
        return {
            title: (bgEl && bgEl.getAttribute('data-media-title')) || slideEl.getAttribute('data-default-title') || '',
            headline: (bgEl && (bgEl.getAttribute('data-media-headline') || bgEl.getAttribute('data-media-body'))) ||
                slideEl.getAttribute('data-default-headline') || '',
            cta_label: (bgEl && bgEl.getAttribute('data-media-cta-label')) || slideEl.getAttribute('data-default-cta-label') || '',
            cta_url: (bgEl && bgEl.getAttribute('data-media-cta-url')) || slideEl.getAttribute('data-default-cta-url') || '',
        };
    }

    function ensureSpotlightBg(bgEl) {
        if (!bgEl || bgEl.querySelector('.on-img--main')) return;
        var src = resolveMediaUrl(bgEl.getAttribute('data-bg'));
        if (src) {
            bgEl.style.backgroundImage = "url('" + cssUrl(src) + "')";
        }
    }

    function applyMediaContent(section, slideIndex, mediaIndex) {
        var slideEl = section.querySelector('.hero-slide[data-lu-slide="' + slideIndex + '"]');
        if (!slideEl) return;
        var bgEl = section.querySelector(
            '.hero-background[data-lu-slide="' + slideIndex + '"][data-lu-media="' + mediaIndex + '"]'
        );
        var copy = slideEl.querySelector('.lu-copy-dynamic');
        if (!copy) return;

        var content = resolveMediaCopy(slideEl, bgEl);
        var titleEl = copy.querySelector('.lu-dynamic-title');
        var descEl = copy.querySelector('.lu-dynamic-desc');
        var ctaEl = copy.querySelector('.lu-dynamic-cta');

        copy.classList.add('lu-copy-swapping');
        window.setTimeout(function () {
            if (titleEl) titleEl.textContent = truncate(content.title, 60);
            if (descEl) {
                var desc = truncate(content.headline, 120);
                descEl.textContent = desc;
                descEl.style.display = desc ? '' : 'none';
            }
            if (ctaEl) {
                ctaEl.innerHTML = buildCtaHtml(content.cta_label, content.cta_url);
                ctaEl.style.display = content.cta_label && content.cta_url ? '' : 'none';
            }
            copy.classList.remove('lu-copy-swapping');
        }, 180);

        section.querySelectorAll('.lu-media-dot[data-lu-slide="' + slideIndex + '"]').forEach(function (d, i) {
            d.classList.toggle('active', i === mediaIndex);
        });

        /* Sync the media counter pill + caption strip for this slide. */
        var counterEl = section.querySelector('.lu-media-counter[data-lu-counter="' + slideIndex + '"]');
        if (counterEl) {
            var numEl = counterEl.querySelector('.lu-media-counter__num b');
            if (numEl) numEl.textContent = String(mediaIndex + 1);
            var iconEl = counterEl.querySelector('.lu-media-counter__icon');
            if (iconEl && bgEl) {
                iconEl.textContent = bgEl.classList.contains('spotlight-media-video') ||
                    bgEl.classList.contains('spotlight-media-embed') ? '▶' : '📷';
            }
        }
        var captionEl = section.querySelector('.lu-media-caption[data-lu-caption="' + slideIndex + '"]');
        if (captionEl) {
            var textEl = captionEl.querySelector('.lu-media-caption__text');
            if (textEl) textEl.textContent = truncate(content.title || content.headline || '', 90);
            captionEl.toggleAttribute('hidden', !(content.title || content.headline));
        }
    }

    function buildCtas(slide) {
        var primary = slide.cta_primary && slide.cta_primary_url
            ? { label: slide.cta_primary, url: slide.cta_primary_url }
            : null;
        var secondary = slide.cta_secondary && slide.cta_secondary_url
            ? { label: slide.cta_secondary, url: slide.cta_secondary_url }
            : null;

        if (slide.is_live) {
            secondary = null;
        } else if (primary && secondary) {
            if (primary.url === secondary.url) {
                secondary = null;
            } else {
                var p = primary.label.toLowerCase();
                var s = secondary.label.toLowerCase();
                if (p === s || (p.indexOf('view') !== -1 && s.indexOf('view') !== -1)) {
                    secondary = null;
                }
            }
        }

        if (primary && primary.label) {
            primary.label = truncate(primary.label, 22);
        }

        var html = '';
        if (primary) {
            html += '<a href="' + esc(primary.url) + '" class="interactive-button">' +
                esc(primary.label) + ' <span class="icon">→</span></a>';
        }
        if (secondary) {
            html += '<a href="' + esc(secondary.url) + '" class="cta-button cta-button--outline">' +
                esc(secondary.label) + '</a>';
        }
        return html ? '<div class="cta-buttons">' + html + '</div>' : '';
    }

    function renderUpdateFeed(items) {
        if (!items || !items.length) return '';
        var item = items[0];
        return (
            '<div class="live-updates-feed-item">' +
            '<strong>' + esc(item.title || 'Update') + '</strong>' +
            (item.body ? '<span> — ' + esc(truncate(item.body, 72)) + '</span>' : '') +
            (item.link_url
                ? ' <a href="' + esc(item.link_url) + '" target="_blank" rel="noopener">' +
                  esc(item.link_label || 'Link') + ' →</a>'
                : '') +
            '</div>'
        );
    }

    function embedUrl(src) {
        if (/youtu\.be\//i.test(src)) {
            return 'https://www.youtube.com/embed/' + src.split('/').pop().split('?')[0] + '?autoplay=1&mute=1&rel=0';
        }
        if (/youtube\.com\/watch/i.test(src)) {
            var vid = (src.match(/[?&]v=([^&]+)/) || [])[1];
            if (vid) return 'https://www.youtube.com/embed/' + vid + '?autoplay=1&mute=1&rel=0';
        }
        if (/vimeo\.com/i.test(src)) {
            var vId = src.split('/').filter(Boolean).pop();
            return 'https://player.vimeo.com/video/' + vId + '?autoplay=1&muted=1';
        }
        return src;
    }

    function renderOneMedia(media, slideIndex, mediaIndex, isActive) {
        var active = isActive ? ' active' : '';
        var url = resolveMediaUrl(media.url || '');

        if (media.type === 'video' && /youtube\.com|youtu\.be|vimeo\.com/i.test(url)) {
            return (
                '<div class="hero-background on-frame spotlight-media-embed' + active + '" data-lu-slide="' + slideIndex + '" data-lu-media="' + mediaIndex + '"' +
                mediaDataAttrs(media) + '>' +
                '<iframe src="' + esc(embedUrl(url)) + '" title="Event media" allow="autoplay; fullscreen" loading="lazy"></iframe></div>'
            );
        }

        if (media.type === 'video') {
            return (
                '<div class="hero-background on-frame spotlight-media-video' + active + '" data-lu-slide="' + slideIndex + '" data-lu-media="' + mediaIndex + '"' +
                mediaDataAttrs(media) + '>' +
                '<video muted playsinline loop autoplay preload="metadata">' +
                '<source src="' + esc(url) + '"></video></div>'
            );
        }

        var driveId = media.drive_file_id || extractDriveFileId(media.url || '') || extractDriveFileId(media.fullUrl || '');
        var displayUrl = driveId ? driveProxyUrl(driveId) : url;
        var safeUrl = displayUrl ? esc(displayUrl) : '';
        var fullUrl = esc(driveId ? driveDirectUrls(driveId)[0] : resolveMediaUrl(media.fullUrl || media.url || ''));
        var eager = isActive && slideIndex === 0 && mediaIndex === 0;
        var loadAttr = eager ? 'eager' : 'lazy';
        var priorityAttr = eager ? ' fetchpriority="high"' : '';
        var driveAttrs = driveId
            ? ' data-drive-id="' + esc(driveId) + '" data-drive-fallback="0" onerror="window.HOSU_SPOTLIGHT&&window.HOSU_SPOTLIGHT.driveImgFallback(this)"'
            : '';
        var altText = media.title || (driveId ? 'Event photo' : 'Event photo');
        var inner = safeUrl
            ? '<button type="button" class="on-img-btn" aria-label="View full image">' +
              '<img class="on-img on-img--main" src="' + safeUrl + '" data-full-src="' + fullUrl + '"' +
              ' alt="' + esc(altText) + '" loading="' + loadAttr + '"' +
              priorityAttr + ' decoding="async" referrerpolicy="no-referrer"' + driveAttrs + '></button>'
            : '';
        return (
            '<div class="hero-background on-frame on-frame--image' + active + (url ? '' : ' event-bg') + '"' +
            ' data-lu-slide="' + slideIndex + '" data-lu-media="' + mediaIndex + '"' +
            mediaDataAttrs(media) + '>' + inner + '</div>'
        );
    }

    function closeImageLightbox() {
        var lb = document.getElementById('on-image-lightbox');
        if (lb) {
            lb.classList.remove('is-open');
            lb.setAttribute('hidden', '');
            document.body.style.overflow = '';
        }
    }

    function openImageLightbox(src, alt) {
        if (!src) return;
        var lb = document.getElementById('on-image-lightbox');
        if (!lb) {
            lb = document.createElement('div');
            lb.id = 'on-image-lightbox';
            lb.className = 'on-lightbox';
            lb.setAttribute('hidden', '');
            lb.innerHTML =
                '<div class="on-lightbox__backdrop" data-on-lb-close></div>' +
                '<div class="on-lightbox__panel" role="dialog" aria-modal="true" aria-label="Full size image">' +
                '<button type="button" class="on-lightbox__close" data-on-lb-close aria-label="Close">&times;</button>' +
                '<img class="on-lightbox__img" src="" alt="">' +
                '</div>';
            document.body.appendChild(lb);
            lb.addEventListener('click', function (e) {
                if (e.target.hasAttribute('data-on-lb-close') || e.target.classList.contains('on-lightbox__close')) {
                    closeImageLightbox();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeImageLightbox();
            });
        }
        var img = lb.querySelector('.on-lightbox__img');
        if (img) {
            img.src = src;
            img.alt = alt || '';
        }
        lb.removeAttribute('hidden');
        lb.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function initImageLightbox(section) {
        if (!section) return;
        section.querySelectorAll('.on-img-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var img = btn.querySelector('.on-img--main');
                if (!img) return;
                openImageLightbox(img.getAttribute('data-full-src') || img.src, img.alt);
            });
        });
    }

    function lazyLoadSpotlightBackgrounds(section) {
        if (!section) return;
        var loadAll = function () {
            section.querySelectorAll('.hero-background[data-bg]').forEach(ensureSpotlightBg);
        };
        if ('requestIdleCallback' in window) {
            requestIdleCallback(loadAll, { timeout: 2000 });
        } else {
            setTimeout(loadAll, 400);
        }
    }

    function preloadAdjacentSpotlightBg(section, slideIndex, mediaIndex) {
        if (!section) return;
        var slideEl = section.querySelector('.hero-slide[data-lu-slide="' + slideIndex + '"]');
        var count = slideEl ? parseInt(slideEl.getAttribute('data-media-count'), 10) || 0 : 0;
        if (count <= 1) return;
        var nextIdx = (mediaIndex + 1) % count;
        var nextBg = section.querySelector(
            '.hero-background[data-lu-slide="' + slideIndex + '"][data-lu-media="' + nextIdx + '"]'
        );
        if (nextBg) ensureSpotlightBg(nextBg);
    }

    function renderMediaDots(slideIndex, mediaCount) {
        if (mediaCount <= 1) return '';
        return '<div class="lu-media-dots" aria-hidden="true">' +
            Array.from({ length: mediaCount }, function (_, mi) {
                return '<span class="lu-media-dot' + (mi === 0 ? ' active' : '') +
                    '" data-lu-slide="' + slideIndex + '"></span>';
            }).join('') +
            '</div>';
    }

    function renderMediaNav() {
        return (
            '<div class="on-media-nav" data-lu-media-nav hidden>' +
                '<button type="button" class="on-media-nav__btn on-media-nav__btn--prev" data-nav="prev" aria-label="Previous photo">' +
                    '<span aria-hidden="true">‹</span>' +
                '</button>' +
                '<button type="button" class="on-media-nav__btn on-media-nav__btn--next" data-nav="next" aria-label="Next photo">' +
                    '<span aria-hidden="true">›</span>' +
                '</button>' +
            '</div>'
        );
    }

    function syncMediaNav(section, slideIndex) {
        var nav = section.querySelector('[data-lu-media-nav]');
        if (!nav) return;
        var slideEl = section.querySelector('.hero-slide[data-lu-slide="' + slideIndex + '"]');
        var count = slideEl ? parseInt(slideEl.getAttribute('data-media-count'), 10) || 0 : 0;
        nav.toggleAttribute('hidden', count <= 1);
    }

    function navigateMediaManually(section, slideIndex, direction) {
        var slideEl = section.querySelector('.hero-slide[data-lu-slide="' + slideIndex + '"]');
        if (!slideEl) return;
        var count = parseInt(slideEl.getAttribute('data-media-count'), 10) || 0;
        if (count <= 1) return;

        var cur = _mediaIndexBySlide[slideIndex] || 0;
        var next = direction === 'prev' ? (cur - 1 + count) % count : (cur + 1) % count;
        goToMediaInSlide(section, slideIndex, next);
        clearMediaTimer(slideIndex);
        startMediaRotation(section, slideIndex);
    }

    function initMediaNav(section) {
        section.querySelectorAll('.on-media-nav__btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var activeSlide = section.querySelector('.hero-slide.active[data-lu-slide]');
                if (!activeSlide) return;
                navigateMediaManually(
                    section,
                    parseInt(activeSlide.getAttribute('data-lu-slide'), 10),
                    btn.getAttribute('data-nav')
                );
            });
        });

        if (section._mediaNavKeyBound) return;
        section._mediaNavKeyBound = true;
        document.addEventListener('keydown', function (e) {
            var liveSection = document.getElementById('home-ongoing');
            if (!liveSection || liveSection.hasAttribute('hidden')) return;
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            var focused = document.activeElement;
            if (focused && (
                focused.tagName === 'INPUT' ||
                focused.tagName === 'TEXTAREA' ||
                focused.tagName === 'SELECT' ||
                focused.isContentEditable
            )) return;
            var activeSlide = liveSection.querySelector('.hero-slide.active[data-lu-slide]');
            if (!activeSlide) return;
            e.preventDefault();
            navigateMediaManually(
                liveSection,
                parseInt(activeSlide.getAttribute('data-lu-slide'), 10),
                e.key === 'ArrowLeft' ? 'prev' : 'next'
            );
        });
    }

    /* Top-right pill showing "N of M" + media-type icon. Updated as media rotates. */
    function renderMediaCounter(slideIndex, mediaList) {
        if (!mediaList || mediaList.length <= 1) return '';
        var first = mediaList[0] || {};
        var icon = first.type === 'video' ? '▶' : '📷';
        return '<div class="lu-media-counter" data-lu-counter="' + slideIndex + '" aria-hidden="true">' +
            '<span class="lu-media-counter__icon">' + icon + '</span>' +
            '<span class="lu-media-counter__num"><b>1</b> / ' + mediaList.length + '</span>' +
            '</div>';
    }

    /* Bottom-left caption strip overlaid on the spotlight image (event-title fallback flows through). */
    function renderMediaCaption(slideIndex, mediaList) {
        if (!mediaList || !mediaList.length) return '';
        var first = mediaList[0] || {};
        var text = first.title || first.headline || '';
        return '<div class="lu-media-caption" data-lu-caption="' + slideIndex + '"' +
            (text ? '' : ' hidden') + '>' +
            '<span class="lu-media-caption__text">' + esc(truncate(text, 90)) + '</span>' +
            '</div>';
    }

    function renderContentSlide(slide, slideIndex) {
        var media = normalizeSlideMedia(slide);
        var meta = slide.meta || {};
        var pillList = buildPills(meta);
        var pills = pillList.map(function (p) {
            return '<span class="on-pill">' + p.icon + ' ' + esc(p.text) + '</span>';
        }).join('');

        var icon = slide.is_live ? '🔴' : '📢';
        var badge = shortBadge(slide);
        var desc = pickDescription(slide);
        var defaultCta = buildCtas(slide);

        var feedHtml = '';
        if (slide.updates && slide.updates.length) {
            feedHtml =
                '<div class="on-feed" id="live-feed-' + slideIndex + '">' +
                '<span class="on-feed__label">Latest update</span>' +
                renderUpdateFeed(slide.updates) +
                '</div>';
        }

        var badgeCls = slide.is_live ? 'on-badge on-badge--live' : 'on-badge';

        return (
            '<div class="on-slide hero-slide' + (slideIndex === 0 ? ' active' : '') + '" data-lu-slide="' + slideIndex + '"' +
            ' data-media-count="' + media.length + '"' +
            ' data-default-title="' + esc(slide.title || '') + '"' +
            ' data-default-headline="' + esc(slide.headline || slide.body || '') + '"' +
            ' data-default-cta-label="' + esc(slide.cta_primary || '') + '"' +
            ' data-default-cta-url="' + esc(slide.cta_primary_url || '') + '">' +
            '<div class="on-copy lu-copy-dynamic">' +
            '<span class="' + badgeCls + '">' +
            '<span class="on-badge__icon">' + icon + '</span>' + esc(badge) +
            '</span>' +
            '<h3 class="on-title lu-dynamic-title">' + esc(truncate(slide.title, 60)) + '</h3>' +
            (desc ? '<p class="on-desc lu-dynamic-desc">' + esc(desc) + '</p>' : '<p class="on-desc lu-dynamic-desc" style="display:none"></p>') +
            (pills ? '<div class="on-pills lu-dynamic-pills">' + pills + '</div>' : '') +
            '<div class="on-cta lu-dynamic-cta">' + defaultCta + '</div>' +
            feedHtml +
            '</div></div>'
        );
    }

    function pauseSlideVideos(section, slideIndex, exceptIndex) {
        if (!section) return;
        section.querySelectorAll('.hero-background.spotlight-media-video[data-lu-slide="' + slideIndex + '"] video').forEach(function (v) {
            var wrap = v.closest('.hero-background');
            var idx = wrap ? parseInt(wrap.getAttribute('data-lu-media'), 10) : -1;
            if (idx === exceptIndex) {
                v.play().catch(function () {});
            } else {
                v.pause();
            }
        });
    }

    function goToMediaInSlide(section, slideIndex, mediaIndex) {
        if (!section) return;
        _mediaIndexBySlide[slideIndex] = mediaIndex;

        section.querySelectorAll('.hero-background[data-lu-slide]').forEach(function (bg) {
            var si = parseInt(bg.getAttribute('data-lu-slide'), 10);
            var idx = parseInt(bg.getAttribute('data-lu-media'), 10);
            var on = si === slideIndex && idx === mediaIndex;
            bg.classList.toggle('active', on);
            if (on) {
                ensureSpotlightBg(bg);
                preloadAdjacentSpotlightBg(section, slideIndex, mediaIndex);
            }
        });
        pauseSlideVideos(section, slideIndex, mediaIndex);
        applyMediaContent(section, slideIndex, mediaIndex);
    }

    function clearMediaTimer(slideIndex) {
        if (_mediaTimers[slideIndex]) {
            clearTimeout(_mediaTimers[slideIndex]);
            delete _mediaTimers[slideIndex];
        }
    }

    function clearAllMediaTimers() {
        Object.keys(_mediaTimers).forEach(function (k) {
            clearTimeout(_mediaTimers[k]);
        });
        _mediaTimers = {};
    }

    function randomMediaDelay() {
        return 3200 + Math.floor(Math.random() * 3800);
    }

    function startMediaRotation(section, slideIndex) {
        clearMediaTimer(slideIndex);
        var slideEl = section.querySelector('.hero-slide[data-lu-slide="' + slideIndex + '"]');
        if (!slideEl || !slideEl.classList.contains('active')) return;

        var count = parseInt(slideEl.getAttribute('data-media-count'), 10) || 0;
        if (count <= 1) return;

        function tick() {
            if (!document.getElementById('home-ongoing')) return;
            var activeSlide = section.querySelector('.hero-slide.active[data-lu-slide]');
            if (!activeSlide || parseInt(activeSlide.getAttribute('data-lu-slide'), 10) !== slideIndex) return;

            var cur = _mediaIndexBySlide[slideIndex] || 0;
            var next = (cur + 1) % count;
            goToMediaInSlide(section, slideIndex, next);

            _mediaTimers[slideIndex] = setTimeout(tick, randomMediaDelay());
        }

        _mediaTimers[slideIndex] = setTimeout(tick, randomMediaDelay());
    }

    function goToSlide(section, index, slides) {
        if (!slides.length) return;
        if (index < 0) index = slides.length - 1;
        if (index >= slides.length) index = 0;
        _slideIndex = index;

        clearAllMediaTimers();

        section.querySelectorAll('.hero-slide[data-lu-slide]').forEach(function (s, i) {
            s.classList.toggle('active', i === index);
        });
        section.querySelectorAll('.on-indicators .on-dot').forEach(function (d, i) {
            d.classList.toggle('active', i === index);
        });

        section.querySelectorAll('.hero-background[data-lu-slide]').forEach(function (bg) {
            var si = parseInt(bg.getAttribute('data-lu-slide'), 10);
            var mi = parseInt(bg.getAttribute('data-lu-media'), 10);
            bg.classList.toggle('active', si === index && mi === 0);
        });

        /* Only show the overlay set (counter + caption + dots) for the active slide. */
        section.querySelectorAll('.lu-media-counter[data-lu-counter]').forEach(function (el) {
            el.toggleAttribute('hidden', parseInt(el.getAttribute('data-lu-counter'), 10) !== index);
        });
        section.querySelectorAll('.lu-media-caption[data-lu-caption]').forEach(function (el) {
            var on = parseInt(el.getAttribute('data-lu-caption'), 10) === index;
            if (!on) el.setAttribute('hidden', '');
        });
        section.querySelectorAll('.lu-media-dots').forEach(function (dotsEl) {
            var firstDot = dotsEl.querySelector('.lu-media-dot[data-lu-slide]');
            if (!firstDot) return;
            var on = parseInt(firstDot.getAttribute('data-lu-slide'), 10) === index;
            dotsEl.style.display = on ? '' : 'none';
        });

        _mediaIndexBySlide[index] = 0;
        goToMediaInSlide(section, index, 0);
        syncMediaNav(section, index);
        startMediaRotation(section, index);
    }

    function startCarousel(section, slides) {
        if (_carouselTimer) clearInterval(_carouselTimer);
        if (slides.length <= 1) return;
        _carouselTimer = setInterval(function () {
            if (!document.getElementById('home-ongoing')) return;
            goToSlide(section, (_slideIndex + 1) % slides.length, slides);
        }, getCarouselInterval());
    }

    function renderLiveUpdatesSection(slides) {
        var section = document.getElementById('home-ongoing');
        if (!section) return;

        if (_carouselTimer) {
            clearInterval(_carouselTimer);
            _carouselTimer = null;
        }
        clearAllMediaTimers();
        _mediaIndexBySlide = {};

        if (!slides || !slides.length) {
            section.classList.remove('ongoing-now--active', 'ongoing-now--live');
            section.setAttribute('hidden', '');
            section.innerHTML = '';
            _lastSlideCount = 0;
            return;
        }

        section.classList.add('ongoing-now--active', 'ongoing-now--live');
        section.removeAttribute('hidden');
        section.style.setProperty('--carousel-interval', getCarouselInterval() + 'ms');
        _slideIndex = 0;

        var slidesHtml = slides.map(function (slide, i) {
            return renderContentSlide(slide, i);
        }).join('');

        var mediaHtml = '';
        var mediaDotsHtml = '';
        var mediaOverlayHtml = '';
        slides.forEach(function (slide, i) {
            var media = normalizeSlideMedia(slide);
            if (!media.length) {
                mediaHtml += renderOneMedia({ type: 'image', url: '' }, i, 0, i === 0);
                return;
            }
            media.forEach(function (m, mi) {
                mediaHtml += renderOneMedia(m, i, mi, i === 0 && mi === 0);
            });
            mediaDotsHtml += renderMediaDots(i, media.length);
            mediaOverlayHtml += renderMediaCounter(i, media) + renderMediaCaption(i, media);
        });

        var dotsHtml = '';
        if (slides.length > 1) {
            dotsHtml =
                '<div class="on-indicators">' +
                slides.map(function (_, i) {
                    return '<span class="on-dot hero-dot' + (i === 0 ? ' active' : '') +
                        '" data-lu-dot="' + i + '" role="button" tabindex="0" aria-label="Show update ' + (i + 1) + '"></span>';
                }).join('') +
                '</div>';
        }

        section.innerHTML =
            '<div class="on-wrap">' +
                '<header class="on-head">' +
                    '<span class="on-eyebrow">' +
                        '<span class="on-eyebrow__pulse" aria-hidden="true"></span>' +
                        esc('Live · Right Now') +
                    '</span>' +
                    '<h2 class="on-heading">Ongoing Now</h2>' +
                    '<p class="on-subheading">Photos from live HOSU events — use arrows to browse or let images rotate automatically.</p>' +
                '</header>' +
                '<div class="on-card">' +
                    '<div class="on-media">' +
                        mediaHtml + mediaDotsHtml + mediaOverlayHtml + renderMediaNav() +
                    '</div>' +
                    '<div class="on-content">' + slidesHtml + '</div>' +
                '</div>' +
                dotsHtml +
            '</div>';

        slides.forEach(function (slide, i) {
            var slideEl = section.querySelector('.hero-slide[data-lu-slide="' + i + '"]');
            if (!slideEl) return;
            var count = normalizeSlideMedia(slide).length;
            slideEl.setAttribute('data-media-count', String(count || 1));
        });

        initImageLightbox(section);
        initMediaNav(section);
        syncMediaNav(section, 0);

        /* Hide overlays for inactive slides on first paint. */
        section.querySelectorAll('.lu-media-counter[data-lu-counter]').forEach(function (el) {
            if (parseInt(el.getAttribute('data-lu-counter'), 10) !== 0) el.setAttribute('hidden', '');
        });
        section.querySelectorAll('.lu-media-caption[data-lu-caption]').forEach(function (el) {
            if (parseInt(el.getAttribute('data-lu-caption'), 10) !== 0) el.setAttribute('hidden', '');
        });
        section.querySelectorAll('.lu-media-dots').forEach(function (dotsEl) {
            var firstDot = dotsEl.querySelector('.lu-media-dot[data-lu-slide]');
            if (firstDot && parseInt(firstDot.getAttribute('data-lu-slide'), 10) !== 0) {
                dotsEl.style.display = 'none';
            }
        });

        startMediaRotation(section, 0);

        if (slides.length > 1) {
            section.querySelectorAll('[data-lu-dot]').forEach(function (dot) {
                function activate() {
                    goToSlide(section, parseInt(dot.getAttribute('data-lu-dot'), 10), slides);
                    startCarousel(section, slides);
                }
                dot.addEventListener('click', activate);
                dot.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
                });
            });
            startCarousel(section, slides);
        }

        slides.forEach(function (slide, i) {
            if (!slide.updates || slide.updates.length <= 1) return;
            (function (feedIndex, items) {
                var feedIdx = 0;
                setInterval(function () {
                    var slideEl = section.querySelector('.hero-slide[data-lu-slide="' + feedIndex + '"]');
                    var feedEl = document.getElementById('live-feed-' + feedIndex);
                    if (!slideEl || !slideEl.classList.contains('active') || !feedEl) return;
                    feedIdx = (feedIdx + 1) % items.length;
                    var label = feedEl.querySelector('.on-feed__label');
                    if (label) {
                        feedEl.innerHTML = label.outerHTML + renderUpdateFeed([items[feedIdx]]);
                    }
                }, getCarouselInterval());
            })(i, slide.updates);
        });

        _lastSlideCount = slides.length;
    }

    function spotlightFingerprint(slides) {
        if (!slides || !slides.length) return '';
        return slides.map(function (s) {
            var media = (s.media || []).map(function (m) {
                return (m.type || 'image') + ':' + (m.url || '');
            }).sort().join('|');
            return [
                s.id || '',
                s.title || '',
                s.headline || '',
                s.body || '',
                s.badge || '',
                s.image || '',
                media,
                s.cta_primary || '',
                s.cta_primary_url || '',
            ].join('~');
        }).join('||');
    }

    function getEmbeddedSpotlight() {
        var boot = window.__HOSU_PAGE_BOOTSTRAP;
        if (boot && boot.success && boot.page === 'home' && boot.spotlight_slides && boot.spotlight_slides.length) {
            return { success: true, spotlight_slides: boot.spotlight_slides };
        }
        return null;
    }

    function fetchSpotlightFromApi() {
        return fetch('api.php?action=get_home_spotlight', {
            credentials: 'same-origin',
            cache: 'no-store',
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function applySpotlightData(data) {
        if (!data || !data.success) return;
        var slides = (data.spotlight_slides || []).filter(function (s) { return s.is_live; });
        _lastSpotlightFingerprint = spotlightFingerprint(slides);
        _lastSlideCount = slides.length;
        renderLiveUpdatesSection(slides);
    }

    function refreshQuietly() {
        fetchSpotlightFromApi()
            .then(function (data) {
                if (!data || !data.success) return;
                var slides = data.spotlight_slides || [];
                var fp = spotlightFingerprint(slides);
                if (fp !== _lastSpotlightFingerprint) {
                    applySpotlightData(data);
                    if (window.__HOSU_PAGE_BOOTSTRAP) {
                        window.__HOSU_PAGE_BOOTSTRAP.spotlight_slides = slides;
                    }
                }
            })
            .catch(function () {});
    }

    function driveImgFallback(img) {
        if (!img) return;
        var id = img.getAttribute('data-drive-id');
        if (!id) return;
        var step = parseInt(img.getAttribute('data-drive-fallback') || '0', 10) + 1;
        var urls = driveDirectUrls(id);
        if (step < urls.length) {
            img.setAttribute('data-drive-fallback', String(step));
            img.src = urls[step];
            return;
        }
        img.classList.add('on-img--failed');
    }

    window.HOSU_SPOTLIGHT = {
        render: applySpotlightData,
        driveImgFallback: driveImgFallback,
        reload: function () {
            return fetchSpotlightFromApi()
                .then(function (data) {
                    applySpotlightData(data);
                    if (window.__HOSU_PAGE_BOOTSTRAP && data && data.success) {
                        window.__HOSU_PAGE_BOOTSTRAP.spotlight_slides = data.spotlight_slides || [];
                    }
                    return data;
                });
        },
    };

    if (!document.getElementById('home-ongoing')) return;

    var embedded = getEmbeddedSpotlight();
    if (embedded) {
        applySpotlightData(embedded);
    } else {
        fetchSpotlightFromApi().then(applySpotlightData).catch(function (err) {
            console.error('Live updates load failed:', err);
        });
    }
    setInterval(refreshQuietly, REFRESH_MS);
})();
