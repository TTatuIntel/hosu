/**
 * Quiet background refresh for the homepage — polls for admin updates without
 * reloading the page or resetting what the visitor is viewing.
 */
(function () {
    'use strict';

    if (!document.getElementById('home')) return;

    var REV_KEYS = ['hosu_home_rev', 'hosu_ongoing_rev'];
    var REFRESH_MS = 12000;
    var _lastRevs = {};

    function readRevs() {
        var out = {};
        REV_KEYS.forEach(function (key) {
            try {
                out[key] = localStorage.getItem(key) || '';
            } catch (e) {
                out[key] = '';
            }
        });
        return out;
    }

    function revsChanged(current, previous) {
        return REV_KEYS.some(function (key) {
            return current[key] && current[key] !== previous[key];
        });
    }

    function refreshAll(force) {
        var tasks = [];

        if (window.HOSU_HERO && typeof window.HOSU_HERO.refreshQuietly === 'function') {
            tasks.push(window.HOSU_HERO.refreshQuietly(!!force));
        }
        if (window.HOSU_EXTRAS && typeof window.HOSU_EXTRAS.refreshQuietly === 'function') {
            tasks.push(window.HOSU_EXTRAS.refreshQuietly(!!force));
        }
        if (window.HOSU_SPOTLIGHT) {
            if (force && typeof window.HOSU_SPOTLIGHT.reload === 'function') {
                tasks.push(window.HOSU_SPOTLIGHT.reload());
            }
        }

        return Promise.all(tasks).catch(function () {});
    }

    function tick(force) {
        if (!force && document.hidden) return;
        refreshAll(!!force);
    }

    function onExternalRevision() {
        _lastRevs = readRevs();
        tick(true);
    }

    _lastRevs = readRevs();

    setInterval(function () {
        tick(false);
    }, REFRESH_MS);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) return;
        var now = readRevs();
        if (revsChanged(now, _lastRevs)) {
            _lastRevs = now;
            tick(true);
        } else {
            tick(false);
        }
    });

    window.addEventListener('storage', function (e) {
        if (REV_KEYS.indexOf(e.key) >= 0) {
            onExternalRevision();
        }
    });
})();
