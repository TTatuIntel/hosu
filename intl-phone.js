/* ============================================================
   International Phone Input Helper — intl-phone.js
   Adds country code dropdown to all phone inputs on the page.
   Include AFTER the page loads or call window.initIntlPhones().
   ============================================================ */
(function () {
    'use strict';

    /* ── Country data: [dial code, ISO2, name, phone digits length range] ── */
    var COUNTRIES = [
        ['+256', 'UG', 'Uganda', [9]],
        ['+254', 'KE', 'Kenya', [9]],
        ['+255', 'TZ', 'Tanzania', [9]],
        ['+250', 'RW', 'Rwanda', [9]],
        ['+211', 'SS', 'South Sudan', [9]],
        ['+243', 'CD', 'DR Congo', [9]],
        ['+251', 'ET', 'Ethiopia', [9]],
        ['+233', 'GH', 'Ghana', [9]],
        ['+234', 'NG', 'Nigeria', [10]],
        ['+27',  'ZA', 'South Africa', [9]],
        ['+233', 'GH', 'Ghana', [9]],
        ['+237', 'CM', 'Cameroon', [9]],
        ['+221', 'SN', 'Senegal', [9]],
        ['+225', 'CI', "Côte d'Ivoire", [10]],
        ['+260', 'ZM', 'Zambia', [9]],
        ['+263', 'ZW', 'Zimbabwe', [9]],
        ['+265', 'MW', 'Malawi', [9]],
        ['+258', 'MZ', 'Mozambique', [9]],
        ['+267', 'BW', 'Botswana', [7, 8]],
        ['+257', 'BI', 'Burundi', [8]],
        ['+1',   'US', 'United States', [10]],
        ['+1',   'CA', 'Canada', [10]],
        ['+44',  'GB', 'United Kingdom', [10]],
        ['+91',  'IN', 'India', [10]],
        ['+61',  'AU', 'Australia', [9]],
        ['+49',  'DE', 'Germany', [10, 11]],
        ['+33',  'FR', 'France', [9]],
        ['+86',  'CN', 'China', [11]],
        ['+81',  'JP', 'Japan', [10]],
        ['+82',  'KR', 'South Korea', [10]],
        ['+971', 'AE', 'UAE', [9]],
        ['+966', 'SA', 'Saudi Arabia', [9]],
        ['+20',  'EG', 'Egypt', [10]],
        ['+212', 'MA', 'Morocco', [9]],
        ['+216', 'TN', 'Tunisia', [8]],
        ['+46',  'SE', 'Sweden', [9]],
        ['+47',  'NO', 'Norway', [8]],
        ['+45',  'DK', 'Denmark', [8]],
        ['+31',  'NL', 'Netherlands', [9]],
        ['+39',  'IT', 'Italy', [10]],
        ['+34',  'ES', 'Spain', [9]],
        ['+351', 'PT', 'Portugal', [9]],
        ['+55',  'BR', 'Brazil', [11]],
        ['+52',  'MX', 'Mexico', [10]],
        ['+57',  'CO', 'Colombia', [10]],
        ['+56',  'CL', 'Chile', [9]],
        ['+63',  'PH', 'Philippines', [10]],
        ['+60',  'MY', 'Malaysia', [9, 10]],
        ['+65',  'SG', 'Singapore', [8]],
        ['+66',  'TH', 'Thailand', [9]],
        ['+84',  'VN', 'Vietnam', [9]],
        ['+62',  'ID', 'Indonesia', [10, 11, 12]],
        ['+92',  'PK', 'Pakistan', [10]],
        ['+880', 'BD', 'Bangladesh', [10]],
        ['+94',  'LK', 'Sri Lanka', [9]],
        ['+977', 'NP', 'Nepal', [10]],
        ['+353', 'IE', 'Ireland', [9]],
        ['+41',  'CH', 'Switzerland', [9]],
        ['+43',  'AT', 'Austria', [10]],
        ['+48',  'PL', 'Poland', [9]],
        ['+7',   'RU', 'Russia', [10]],
        ['+380', 'UA', 'Ukraine', [9]],
        ['+90',  'TR', 'Turkey', [10]],
        ['+972', 'IL', 'Israel', [9]],
    ];

    // De-duplicate by ISO2 (keep first occurrence)
    var seen = {};
    COUNTRIES = COUNTRIES.filter(function (c) {
        if (seen[c[1]]) return false;
        seen[c[1]] = true;
        return true;
    });

    var DEFAULT_COUNTRY = 'UG';

    /* ── Flag emoji from ISO2 ─────────────────────────────── */
    function flag(iso) {
        return iso.toUpperCase().replace(/./g, function (c) {
            return String.fromCodePoint(0x1F1E6 - 65 + c.charCodeAt(0));
        });
    }

    /* ── Inject CSS once ──────────────────────────────────── */
    var cssInjected = false;
    function injectCSS() {
        if (cssInjected) return;
        cssInjected = true;
        var style = document.createElement('style');
        style.textContent =
            '.intl-phone-wrap{display:flex;align-items:stretch;border:1.5px solid #d1d5db;border-radius:6px;overflow:hidden;background:#fff;transition:border-color .2s;}' +
            '.intl-phone-wrap:focus-within{border-color:#0d4593;box-shadow:0 0 0 2px rgba(13,69,147,.12);}' +
            '.intl-phone-select{appearance:none;-webkit-appearance:none;border:none;background:#f8f9fa;padding:0 4px 0 6px;font-size:0.78rem;cursor:pointer;width:72px;min-width:72px;max-width:72px;font-family:inherit;outline:none;color:#333;border-right:1px solid #e5e7eb;}' +
            '.intl-phone-select:focus{background:#eef2ff;}' +
            '.intl-phone-input{flex:1;border:none;padding:0.45rem 0.5rem;font-size:0.82rem;font-family:inherit;outline:none;min-width:0;background:transparent;}' +
            '.intl-phone-input::placeholder{color:#aaa;}' +
            '.intl-phone-hint{display:block;font-size:0.68rem;color:#888;margin-top:2px;}' +
            '.dfp-field .intl-phone-wrap,.dfp-form-grid .intl-phone-wrap{border:1px solid var(--gray-300,#e2e8f0);border-radius:6px;}' +
            '.dfp-field .intl-phone-select,.dfp-form-grid .intl-phone-select{font-size:0.72rem;padding:0.35rem 3px 0.35rem 5px;width:64px;min-width:64px;max-width:64px;}' +
            '.dfp-field .intl-phone-input,.dfp-form-grid .intl-phone-input{font-size:0.72rem;padding:0.35rem 0.4rem;}';
        document.head.appendChild(style);
    }

    /* ── Get country data by ISO2 ─────────────────────────── */
    function getCountry(iso) {
        for (var i = 0; i < COUNTRIES.length; i++) {
            if (COUNTRIES[i][1] === iso) return COUNTRIES[i];
        }
        return COUNTRIES[0]; // fallback to Uganda
    }

    /* ── Upgrade a single phone input ─────────────────────── */
    function upgradeInput(input) {
        if (input._intlPhoneUpgraded) return;
        input._intlPhoneUpgraded = true;

        injectCSS();

        var wrap = document.createElement('div');
        wrap.className = 'intl-phone-wrap';

        // Country select
        var select = document.createElement('select');
        select.className = 'intl-phone-select';
        select.setAttribute('aria-label', 'Country code');
        COUNTRIES.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c[1];
            opt.textContent = flag(c[1]) + ' ' + c[0];
            opt.title = c[2] + ' (' + c[0] + ')';
            if (c[1] === DEFAULT_COUNTRY) opt.selected = true;
            select.appendChild(opt);
        });

        // Clone input attributes
        var newInput = document.createElement('input');
        newInput.type = 'tel';
        newInput.className = 'intl-phone-input';
        newInput.id = input.id;
        newInput.name = input.name;
        newInput.required = input.required;
        newInput.placeholder = '7XX XXX XXX';
        newInput.setAttribute('autocomplete', 'tel-national');

        wrap.appendChild(select);
        wrap.appendChild(newInput);

        // Replace original input
        input.parentNode.insertBefore(wrap, input);
        input.style.display = 'none';
        input.removeAttribute('id');
        input.removeAttribute('name');
        input.removeAttribute('required');

        // Update placeholder on country change
        select.addEventListener('change', function () {
            var c = getCountry(select.value);
            newInput.placeholder = '7XX XXX XXX';
        });

        // Expose full E.164 number
        function getFullNumber() {
            var c = getCountry(select.value);
            var digits = newInput.value.replace(/\D/g, '');
            // Strip leading 0 if present
            if (digits.charAt(0) === '0') digits = digits.substring(1);
            // Strip country code if user typed it
            var codeDigits = c[0].replace(/\D/g, '');
            if (digits.indexOf(codeDigits) === 0 && digits.length > codeDigits.length + 5) {
                digits = digits.substring(codeDigits.length);
            }
            return c[0] + digits;
        }

        function getDigitsOnly() {
            var c = getCountry(select.value);
            var digits = newInput.value.replace(/\D/g, '');
            if (digits.charAt(0) === '0') digits = digits.substring(1);
            var codeDigits = c[0].replace(/\D/g, '');
            if (digits.indexOf(codeDigits) === 0 && digits.length > codeDigits.length + 5) {
                digits = digits.substring(codeDigits.length);
            }
            return codeDigits + digits;
        }

        function getSelectedCountry() {
            return getCountry(select.value);
        }

        function isValid() {
            var c = getCountry(select.value);
            var digits = newInput.value.replace(/\D/g, '');
            if (digits.charAt(0) === '0') digits = digits.substring(1);
            var codeDigits = c[0].replace(/\D/g, '');
            if (digits.indexOf(codeDigits) === 0 && digits.length > codeDigits.length + 5) {
                digits = digits.substring(codeDigits.length);
            }
            var validLengths = c[3];
            for (var i = 0; i < validLengths.length; i++) {
                if (digits.length === validLengths[i]) return true;
            }
            return false;
        }

        // Store helpers on the wrapper
        wrap._intlPhone = {
            getFullNumber: getFullNumber,
            getDigitsOnly: getDigitsOnly,
            getCountry: getSelectedCountry,
            isValid: isValid,
            input: newInput,
            select: select
        };

        return wrap;
    }

    /* ── Auto-upgrade all tel inputs with class or data attribute ── */
    function initIntlPhones(scope) {
        var root = scope || document;
        var inputs = root.querySelectorAll('input[type="tel"], input[data-intl-phone]');
        inputs.forEach(function (input) {
            upgradeInput(input);
        });
    }

    /* ── Get intl-phone API for an input by ID ────────────── */
    function getIntlPhone(id) {
        var wrap = document.querySelector('.intl-phone-wrap');
        // Find by input ID inside wraps
        var wraps = document.querySelectorAll('.intl-phone-wrap');
        for (var i = 0; i < wraps.length; i++) {
            var inp = wraps[i].querySelector('.intl-phone-input');
            if (inp && inp.id === id) return wraps[i]._intlPhone;
        }
        return null;
    }

    /* ── Expose globally ──────────────────────────────────── */
    window.intlPhone = {
        init: initIntlPhones,
        upgrade: upgradeInput,
        get: getIntlPhone,
        getCountries: function () { return COUNTRIES; },
        flag: flag
    };

    /* ── Auto-init on DOMContentLoaded ────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initIntlPhones(); });
    } else {
        initIntlPhones();
    }
})();
