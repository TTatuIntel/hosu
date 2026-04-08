/* ================================================================
   HOSU Unified Payment Engine â€” hosu-payment.js v1.0
   Single payment UI for every page. Exposes window.HosuPay.
   Loaded automatically by donate-float.js (universal include).
   ================================================================ */
(function (global) {
    'use strict';

    /* â”€â”€ Private state â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    var _built      = false;
    var _pollTimer  = null;
    var _pollCount  = 0;
    var _msgHandler = null;
    var _cbSuccess  = null;
    var _cbError    = null;
    var _cbCancel   = null;

    /* â”€â”€ Inject DOM (once, lazily) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function _build() {
        if (_built) return;
        _built = true;

        /* CSS */
        var s = document.createElement('style');
        s.textContent =
            /* Backdrop */
            '#_hpOv{display:none;position:fixed;top:0;left:0;width:100%;height:100%;' +
            'background:rgba(4,12,44,.68);z-index:99800;align-items:center;justify-content:center}' +
            '#_hpOv.hp-on{display:flex;animation:_hpFd .22s ease}' +
            '@keyframes _hpFd{from{opacity:0}to{opacity:1}}' +
            /* Card */
            '#_hpCard{background:#fff;border-radius:20px;padding:36px 28px 28px;max-width:450px;' +
            'width:92vw;text-align:center;box-shadow:0 16px 60px rgba(13,69,147,.24);' +
            'font-family:inherit;animation:_hpSu .28s cubic-bezier(.22,1,.36,1)}' +
            '@keyframes _hpSu{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}' +
            /* Brand bar inside card */
            '#_hpBrand{display:flex;align-items:center;justify-content:center;gap:8px;' +
            'margin-bottom:18px}' +
            '#_hpBrand span{font-size:.75rem;font-weight:700;letter-spacing:.06em;' +
            'color:#c0392b;text-transform:uppercase}' +
            '#_hpTitle{color:#0d4593;font-size:1.12rem;font-weight:700;margin:0 0 10px;line-height:1.35}' +
            '#_hpSummary{display:none;background:#f0f7ff;border:1px solid #c8d9ff;' +
            'border-radius:8px;padding:6px 12px;margin:0 0 18px;font-size:.78rem;' +
            'font-weight:700;color:#0d4593;text-align:center;line-height:1.4}' +
            /* Steps */
            '#_hpSteps{display:flex;align-items:center;justify-content:center;' +
            'margin:0 0 24px;gap:0}' +
            '.hp-sd{width:30px;height:30px;border-radius:50%;border:2.5px solid #cbd5e1;' +
            'background:#fff;display:flex;align-items:center;justify-content:center;' +
            'font-size:.68rem;font-weight:700;color:#94a3b8;transition:all .35s;flex-shrink:0}' +
            '.hp-sd.hp-a{border-color:#0d4593;color:#0d4593;background:#e8f0fe}' +
            '.hp-sd.hp-d{border-color:#16a34a;background:#16a34a;color:#fff}' +
            '.hp-sl{flex:1;max-width:52px;height:3px;background:#e2e8f0;' +
            'border-radius:2px;transition:background .35s}' +
            '.hp-sl.hp-d{background:#16a34a}.hp-sl.hp-a{background:#0d4593}' +
            /* Step labels */
            '#_hpLabels{display:flex;justify-content:space-between;' +
            'margin:-16px 0 20px;padding:0 2px}' +
            '.hp-lbl{font-size:.62rem;color:#94a3b8;text-align:center;' +
            'width:30px;font-weight:600;letter-spacing:.02em}' +
            '.hp-lbl.hp-a{color:#0d4593}.hp-lbl.hp-d{color:#16a34a}' +
            /* Spinner */
            '#_hpSpin{width:46px;height:46px;border:4px solid #e8f0fe;' +
            'border-top-color:#0d4593;border-radius:50%;' +
            'animation:_hpRot .85s linear infinite;margin:0 auto 18px}' +
            '@keyframes _hpRot{to{transform:rotate(360deg)}}' +
            /* Message */
            '#_hpMsg{font-size:1.05rem;font-weight:700;margin:0 0 8px;' +
            'color:#0d4593;transition:color .2s;line-height:1.4}' +
            '#_hpSub{font-size:.88rem;color:#555;line-height:1.6;margin:0 0 4px}' +
            '#_hpCd{font-size:.78rem;color:#94a3b8;margin:8px 0 0;min-height:18px}' +
            /* Close/CTA button */
            '#_hpBtn{display:none;margin:20px auto 0;background:#0d4593;' +
            'color:#fff;border:none;border-radius:9px;padding:11px 38px;' +
            'font-size:.95rem;font-weight:700;cursor:pointer;letter-spacing:.01em}' +
            '#_hpBtn:hover{background:#0b3d80}' +
            /* Full-screen iframe */
            '#_hpIfrW{display:none;position:fixed;top:0;left:0;width:100vw;' +
            'height:100vh;z-index:100000;flex-direction:column;background:#fff}' +
            '#_hpIfrW.hp-on{display:flex}' +
            '#_hpIfrBar{background:#0d4593;color:#fff;padding:10px 16px;' +
            'display:flex;align-items:center;justify-content:space-between;' +
            'flex-shrink:0;gap:12px}' +
            '#_hpIfrBarTxt{flex:1;min-width:0}' +
            '#_hpIfrBarTxt strong{font-size:.9rem;display:block}' +
            '#_hpIfrBarTxt span{font-size:.72rem;opacity:.88}' +
            '#_hpIfrX{background:rgba(255,255,255,.22);border:none;color:#fff;' +
            'width:30px;height:30px;border-radius:50%;font-size:1.1rem;' +
            'cursor:pointer;line-height:1;flex-shrink:0}' +
            '#_hpIfrHint{background:#f0f4ff;padding:5px 14px;font-size:.75rem;' +
            'color:#0d4593;font-weight:600;border-bottom:1px solid #dce8ff;flex-shrink:0}' +
            '#_hpIframe{flex:1;width:100%;border:none;background:#fff}';
        document.head.appendChild(s);

        /* Backdrop + card */
        var ov = document.createElement('div');
        ov.id = '_hpOv';
        ov.innerHTML =
            '<div id="_hpCard">' +
                '<div id="_hpBrand">' +
                    '<span>HOSU &mdash; Secure Payment</span>' +
                '</div>' +
                '<div id="_hpTitle">Processing Payment</div>' +
                '<div id="_hpSummary"></div>' +
                '<div id="_hpSteps">' +
                    '<div class="hp-sd hp-a" id="_hpD1">1</div>' +
                    '<div class="hp-sl" id="_hpL1"></div>' +
                    '<div class="hp-sd" id="_hpD2">2</div>' +
                    '<div class="hp-sl" id="_hpL2"></div>' +
                    '<div class="hp-sd" id="_hpD3">3</div>' +
                '</div>' +
                '<div id="_hpLabels">' +
                    '<span class="hp-lbl hp-a" id="_hpLbl1">Register</span>' +
                    '<span class="hp-lbl" id="_hpLbl2">Authorize</span>' +
                    '<span class="hp-lbl" id="_hpLbl3">Receipt</span>' +
                '</div>' +
                '<div id="_hpSpin"></div>' +
                '<div id="_hpMsg"></div>' +
                '<div id="_hpSub"></div>' +
                '<div id="_hpCd"></div>' +
                '<button id="_hpBtn">Close</button>' +
            '</div>';
        document.body.appendChild(ov);

        /* Full-screen iframe wrapper */
        var iw = document.createElement('div');
        iw.id = '_hpIfrW';
        iw.innerHTML =
            '<div id="_hpIfrBar">' +
                '<div id="_hpIfrBarTxt">' +
                    '<strong id="_hpIfrTitle">Complete Your Payment \u2014 HOSU</strong>' +
                    '<span id="_hpIfrInfo"></span>' +
                '</div>' +
                '<button id="_hpIfrX">\u00D7</button>' +
            '</div>' +
            '<div id="_hpIfrHint">' +
                '\uD83D\uDD12 Complete your payment securely below. Your billing details are pre-filled.' +
            '</div>' +
            '<iframe id="_hpIframe" src="" allowpaymentrequest></iframe>';
        document.body.appendChild(iw);

        /* Button listeners (wired once) */
        document.getElementById('_hpBtn').addEventListener('click', function () {
            _dismiss();
            if (_cbCancel) _cbCancel();
        });
        document.getElementById('_hpIfrX').addEventListener('click', function () {
            _ifrClose();
            _showErr('Payment cancelled. You can try again by re-submitting the form.');
        });
    }

    /* â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function _g(id) { return document.getElementById(id); }
    function _on()  { _g('_hpOv').classList.add('hp-on'); }
    function _off() { _g('_hpOv').classList.remove('hp-on'); }

    function _step(n) {
        for (var i = 1; i <= 3; i++) {
            var d   = _g('_hpD'   + i);
            var l   = i < 3 ? _g('_hpL' + i) : null;
            var lbl = _g('_hpLbl' + i);
            var cDot = 'hp-sd' + (i < n ? ' hp-d' : i === n ? ' hp-a' : '');
            var cLbl = 'hp-lbl' + (i < n ? ' hp-d' : i === n ? ' hp-a' : '');
            if (d)   d.className   = cDot;
            if (l)   l.className   = 'hp-sl' + (i < n ? ' hp-d' : i === n ? ' hp-a' : '');
            if (lbl) lbl.className = cLbl;
        }
    }

    function _msg(t, c)  { var e = _g('_hpMsg'); e.textContent = t; e.style.color = c || '#0d4593'; }
    function _sub(h)     { _g('_hpSub').innerHTML = h; }
    function _cd(t)      { _g('_hpCd').textContent = t || ''; }
    function _spin(v)    { _g('_hpSpin').style.display = v === false ? 'none' : ''; }
    function _btn(show, label) {
        var b = _g('_hpBtn');
        b.style.display = show ? '' : 'none';
        if (label) b.textContent = label;
    }

    function _stopAll() {
        if (_pollTimer)  { clearInterval(_pollTimer); _pollTimer = null; }
        if (_msgHandler) { window.removeEventListener('message', _msgHandler); _msgHandler = null; }
    }

    function _ifrClose() {
        _stopAll();
        _g('_hpIfrW').classList.remove('hp-on');
        _g('_hpIframe').src = '';
    }

    function _dismiss() {
        _stopAll();
        _ifrClose();
        _off();
    }

    /* â”€â”€ Format phone for display â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function _fmtPhone(raw) {
        var d = String(raw || '').replace(/\D/g, '');
        if (d.length >= 11 && !d.startsWith('0')) return '+' + d;
        if (d.startsWith('0')) return '+256' + d.slice(1);
        return '+256' + d;
    }

    /* â”€â”€ Success â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function _showOk(tok) {
        _stopAll();
        _ifrClose();
        _step(3);
        _spin(false);
        _cd('');
        _msg('\u2705 Payment Confirmed!', '#16a34a');
        var html = '<strong>Thank you!</strong> A receipt has been sent to your email.';
        if (tok) {
            html += '<br><br>' +
                '<a href="receipt.php?token=' + encodeURIComponent(tok) + '" ' +
                'style="display:inline-block;background:#0d4593;color:#fff;padding:11px 28px;' +
                'border-radius:9px;text-decoration:none;font-weight:700;font-size:.95rem;">' +
                '\uD83D\uDCC4 View Receipt</a>';
        }
        _sub(html);
        _btn(true, 'Close');
        _on();
        if (_cbSuccess) _cbSuccess(tok);
    }

    /* â”€â”€ Error â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function _showErr(msg, detail) {
        _stopAll();
        _ifrClose();
        _spin(false);
        _cd('');
        _msg('\u274C ' + (msg || 'Payment could not be completed.'), '#dc2626');
        _sub(detail ||
            'If you completed payment by mobile money, provide your proof of payment on ' +
            '<a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>' +
            ' or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" ' +
            'style="color:#0d4593;font-weight:700;">WhatsApp +256&nbsp;709&nbsp;752107</a>.');
        _btn(true, 'Close');
        _on();
        if (_cbError) _cbError(msg);
    }

    /* â”€â”€ Open PesaPal iframe overlay â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function _openIfr(opts) {
        var ht = _g('_hpIfrTitle');
        if (ht) ht.textContent = opts.purpose ? opts.purpose + ' \u2014 HOSU' : 'Complete Your Payment \u2014 HOSU';
        _g('_hpIfrInfo').textContent =
            '\uD83D\uDCF1 ' + _fmtPhone(opts.phone) +
            (opts.amount ? ' \u00B7 UGX ' + Number(opts.amount).toLocaleString() : '');
        _g('_hpIframe').src = opts.redirectUrl;
        _g('_hpIfrW').classList.add('hp-on');
        _off();

        /* postMessage listener â€” instant callback from pesapal_callback.php */
        _msgHandler = function (ev) {
            if (!ev || !ev.data || ev.data.type !== 'hosu_payment') return;
            _ifrClose();
            if (ev.data.status === 'success') {
                _showOk(ev.data.receiptToken || opts.receiptToken);
            } else {
                _showErr(ev.data.message || 'Payment was not completed. Please try again.');
            }
        };
        window.addEventListener('message', _msgHandler);

        /* Background poll via trackingId (concurrent with iframe) */
        if (opts.trackingId) {
            _pollCount = 0;
            _pollTimer = setInterval(async function () {
                if (++_pollCount > 22) {
                    _showErr('Payment timed out. If money was deducted, please contact info@hosu.or.ug.');
                    return;
                }
                try {
                    var url = 'payment.php?action=check_mobile' +
                        '&tracking_id='  + encodeURIComponent(opts.trackingId) +
                        '&payment_id='   + (opts.payId        || 0) +
                        (opts.registrantId ? '&registrant_id=' + opts.registrantId : '');
                    var resp = await fetch(url);
                    var r;
                    try { r = await resp.json(); } catch(je) { return; }
                    if      (r.status === 'completed') { _showOk(r.receipt_token || opts.receiptToken); }
                    else if (r.status === 'failed')    { _ifrClose(); _showErr(r.message || 'Payment declined. Please try again.'); }
                } catch (e) { /* network hiccup â€” keep polling */ }
            }, 8000);
        }
    }

    /* â”€â”€ Direct poll (no iframe) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function _startPoll(opts) {
        var MAX = opts.maxPolls || 15, INT = opts.interval || 8000;
        _pollCount = 0;
        _pollTimer = setInterval(async function () {
            _pollCount++;
            var secs = Math.max(0, (MAX - _pollCount + 1) * (INT / 1000));
            _cd('Time remaining: ' + Math.floor(secs / 60) + 'm ' + (secs % 60) + 's');
            if (_pollCount > MAX) {
                _showErr('Payment timed out (2 minutes). The request was cancelled.',
                    'If money was deducted, contact us at ' +
                    '<a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>' +
                    ' or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" ' +
                    'style="color:#0d4593;font-weight:700;">WhatsApp +256&nbsp;709&nbsp;752107</a>.');
                return;
            }
            try {
                var url = 'payment.php?action=check_mobile' +
                    '&tracking_id='  + encodeURIComponent(opts.trackingId) +
                    '&payment_id='   + (opts.payId        || 0) +
                    (opts.registrantId ? '&registrant_id=' + opts.registrantId : '');
                var resp = await fetch(url);
                var r;
                try { r = await resp.json(); } catch(je) { return; }
                if      (r.status === 'completed') { _showOk(r.receipt_token || opts.receiptToken); }
                else if (r.status === 'failed')    { _showErr(r.message || 'Payment declined. Please try again.'); }
            } catch (e) { /* continue */ }
        }, INT);
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       PUBLIC API â€” window.HosuPay
       â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

    /**
     * Open the payment processing modal.
     *
     * opts = {
     *   step       : 1|2|3          â€” initial step (default 1)
     *   title      : string         â€” modal title
     *   message    : string         â€” main message text
     *   submessage : string         â€” HTML below message
     *   spinner    : bool           â€” show spinner (default true)
     *   onSuccess  : fn(token)      â€” called with receipt token on success
     *   onError    : fn(msg)        â€” called with message string on error
     *   onCancel   : fn()           â€” called when user clicks Close before completion
     * }
     */
    function open(opts) {
        _build();
        opts = opts || {};
        _cbSuccess = opts.onSuccess || null;
        _cbError   = opts.onError   || null;
        _cbCancel  = opts.onCancel  || null;
        _stopAll();
        var t = _g('_hpTitle'); if (t) t.textContent = opts.title || 'Processing Payment';
        var ps = _g('_hpSummary');
        if (ps) { if (opts.purpose) { ps.textContent = '\uD83D\uDCCB ' + opts.purpose; ps.style.display = ''; } else { ps.style.display = 'none'; } }
        _step(opts.step || 1);
        _spin(opts.spinner !== false);
        _msg(opts.message || 'Please wait\u2026', opts.msgColor);
        _sub(opts.submessage || '');
        _cd('');
        _btn(false);
        _on();
    }

    /** Update the step indicator. */
    function setStep(n) { _build(); _step(n); }

    /** Update message / sub-message. */
    function update(msg, sub, color) {
        _build();
        if (msg !== undefined) _msg(msg, color);
        if (sub !== undefined) _sub(sub || '');
    }

    /**
     * Open full-screen PesaPal iframe overlay.
     * opts = { redirectUrl, phone, amount, trackingId, payId, registrantId, receiptToken }
     */
    function openIframe(opts) { _build(); _openIfr(opts || {}); }

    /**
     * Begin polling check_mobile (no iframe).
     * opts = { trackingId, payId, registrantId, receiptToken, maxPolls, interval }
     */
    function startPoll(opts) { _build(); _startPoll(opts || {}); }

    /** Mark as successful (for external callers). */
    function success(tok) { _build(); _showOk(tok); }

    /** Mark as failed (for external callers). */
    function error(msg, detail) { _build(); _showErr(msg, detail); }

    /** Close/dismiss the modal. */
    function close() { _build(); _dismiss(); if (_cbCancel) _cbCancel(); }

    global.HosuPay = {
        open:       open,
        setStep:    setStep,
        update:     update,
        openIframe: openIframe,
        startPoll:  startPoll,
        success:    success,
        error:      error,
        close:      close
    };

}(window));


/* ============================================================
   UNIFIED DONATE / SUPPORT â€” donate-float.js
   Single source of truth for ALL donate/support popups.
   Include on ALL pages alongside donate-float.css.
   ============================================================ */
(function () {
    'use strict';

    /* â”€â”€ AUTO-INJECT CSRF TOKEN INTO ALL POST REQUESTS â”€â”€ */
    if (!window._hosuFetchPatched) {
        var _origFetch = window.fetch;
        window.fetch = function (url, opts) {
            if (opts && opts.method && opts.method.toUpperCase() === 'POST' && opts.body instanceof FormData) {
                if (window._hosuCsrfToken && !opts.body.has('csrf_token')) {
                    opts.body.append('csrf_token', window._hosuCsrfToken);
                }
            }
            return _origFetch.apply(this, arguments);
        };
        window._hosuFetchPatched = true;
    }

    /* â”€â”€ Shared popup HTML (class-based, no IDs â€” scoped per popup) â”€â”€ */
    var popupTemplate =
        '<button class="dfp-close" aria-label="Close">&times;</button>' +
        '<div class="dfp-modal-header"><h3>Support HOSU</h3></div>' +
        '<p class="dfp-subtitle">Your donation transforms cancer care in Uganda.</p>' +
        '<div class="dfp-form-grid">' +
            '<div class="dfp-field dfp-fg-full">' +
                '<label class="dfp-label">Full Name</label>' +
                '<input type="text" class="dfp-input dfp-name" placeholder="Full name" required>' +
            '</div>' +
            '<div class="dfp-field dfp-fg-full">' +
                '<label class="dfp-label">Phone Number</label>' +
                '<input type="tel" class="dfp-input dfp-phone" required>' +
            '</div>' +
            '<div class="dfp-field dfp-fg-full">' +
                '<label class="dfp-label">Email <span style="color:#e63946">*</span></label>' +
                '<input type="email" class="dfp-input dfp-email" placeholder="you@example.com" required>' +
            '</div>' +
            '<div class="dfp-invalid-phone dfp-fg-full" style="display:none">Invalid phone number.</div>' +
            '<div class="dfp-invalid-email dfp-fg-full" style="display:none;color:#e63946;font-size:0.78rem;margin-top:-6px;">Valid email is required.</div>' +
        '</div>' +
        '<div class="dfp-field">' +
            '<label class="dfp-label">Amount (UGX)</label>' +
            '<input type="number" class="dfp-input dfp-amount" placeholder="Enter amount" min="1000">' +
        '</div>' +
        '<div class="dfp-amounts">' +
            '<button type="button" class="dfp-amt" data-amt="5000">5,000</button>' +
            '<button type="button" class="dfp-amt" data-amt="10000">10,000</button>' +
            '<button type="button" class="dfp-amt" data-amt="25000">25,000</button>' +
            '<button type="button" class="dfp-amt" data-amt="50000">50,000</button>' +
        '</div>' +
        '<div class="dfp-pay-divider">Payment Method</div>' +
        '<div class="dfp-pay-chips">' +
            '<button type="button" class="dfp-pay-chip" data-method="bank">' +
                '<i class="fas fa-university"></i> Bank</button>' +
            '<button type="button" class="dfp-pay-chip" data-method="banktobank">' +
                '<i class="fas fa-exchange-alt"></i> Bank to Bank</button>' +
            '<button type="button" class="dfp-pay-chip" data-method="visa">' +
                '<i class="fab fa-cc-visa"></i> Visa</button>' +
            '<button type="button" class="dfp-pay-chip dfp-mtn-chip" data-method="mtn">' +
                '<img src="img/mtn.png" alt="MTN" class="dfp-chip-img"> MTN</button>' +
            '<button type="button" class="dfp-pay-chip dfp-airtel-chip" data-method="airtel">' +
                '<img src="img/airtel.png" alt="Airtel" class="dfp-chip-img"> Airtel</button>' +
        '</div>' +        '<div class="dfp-billing-notice" style="display:none;font-size:0.73rem;font-weight:600;color:#0d4593;background:#eef3ff;border-radius:8px;padding:6px 10px;margin:4px 0;text-align:center;border:1px solid #c8d9ff;line-height:1.5;"></div>' +        '<div class="dfp-bank-info" style="display:none">' +
            '<p><strong>Account:</strong> HOSU Limited</p>' +
            '<p><strong>No:</strong> 9030025235214</p>' +
            '<p><strong>Bank:</strong> Stanbic Bank, Mulago</p>' +
        '</div>' +
        '<button class="dfp-submit" disabled>Process Payment</button>';

    /* â”€â”€ Inject floating button â”€â”€ */
    var floatWrap = document.createElement('div');
    floatWrap.className = 'floating-donate';
    floatWrap.innerHTML =
        '<div class="donate-trigger-wrap donate-trigger-wrap--fixed">' +
            '<button class="donate-button" aria-label="Support Us">' +
                '<span class="donate-icon">&#10084;</span> Support Us' +
            '</button>' +
            '<div class="donate-float-popup"></div>' +
        '</div>';
    document.body.appendChild(floatWrap);

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       POPUP LIFECYCLE â€” works for ANY .donate-float-popup
       â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

    /** Fill a popup with the template, wire all events, return scoped state */
    function initPopup(popup) {
        popup.innerHTML = popupTemplate;
        // Upgrade phone input
        if (window.intlPhone) {
            var ph = popup.querySelector('.dfp-phone');
            if (ph) window.intlPhone.upgrade(ph);
        }
        // Per-popup state
        var st = { method: null, receiptToken: null, paymentId: null, txnRef: null, txnId: null, pollActive: false };
        popup._dfpState = st;

        // Close
        popup.querySelector('.dfp-close').addEventListener('click', function () { closePopup(popup); });
        // Amount presets
        popup.querySelectorAll('.dfp-amt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                popup.querySelectorAll('.dfp-amt').forEach(function (b) { b.classList.remove('selected'); });
                btn.classList.add('selected');
                popup.querySelector('.dfp-amount').value = btn.getAttribute('data-amt');
                validate(popup);
            });
        });
        // Payment method chips
        popup.querySelectorAll('.dfp-pay-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                popup.querySelectorAll('.dfp-pay-chip').forEach(function (c) { c.classList.remove('selected'); });
                chip.classList.add('selected');
                st.method = chip.getAttribute('data-method');
                var bi = popup.querySelector('.dfp-bank-info');
                if (bi) bi.style.display = 'none';
                validate(popup);
                dfpUpdateBillingNotice(popup);
            });
        });
        // Real-time validation
        ['.dfp-name', '.dfp-amount', '.dfp-email'].forEach(function (sel) {
            var el = popup.querySelector(sel);
            if (el) el.addEventListener('input', function () { validate(popup); });
        });
        var phoneWrap = popup.querySelector('.intl-phone-wrap');
        if (phoneWrap && phoneWrap._intlPhone) {
            phoneWrap._intlPhone.input.addEventListener('input', function () { validate(popup); dfpUpdateBillingNotice(popup); });
            phoneWrap._intlPhone.select.addEventListener('change', function () { validate(popup); dfpUpdateBillingNotice(popup); });
        } else {
            var pi = popup.querySelector('.dfp-phone');
            if (pi) pi.addEventListener('input', function () { validate(popup); dfpUpdateBillingNotice(popup); });
        }
        // Auto-detect MTN / Airtel from phone number
        function dfpAutoDetect() {
            var phoneWrap2 = popup.querySelector('.intl-phone-wrap');
            var phoneApi2  = phoneWrap2 ? phoneWrap2._intlPhone : null;
            var raw = phoneApi2 ? phoneApi2.getDigitsOnly() : (popup.querySelector('.dfp-phone').value.replace(/\D/g,''));
            var d = raw;
            if (d.startsWith('256')) d = d.slice(3);
            if (d.startsWith('0'))   d = d.slice(1);
            var p = d.slice(0, 2);
            var net = null;
            if (['77','78','76','31','39'].includes(p)) net = 'mtn';
            else if (['70','71','72','73','74','75','20'].includes(p)) net = 'airtel';
            if (!net) return;
            popup.querySelectorAll('.dfp-pay-chip').forEach(function(c){ c.classList.remove('selected'); });
            var chip = popup.querySelector('.dfp-pay-chip[data-method="' + net + '"]');
            if (chip) { chip.classList.add('selected'); st.method = net; }
            var bi = popup.querySelector('.dfp-bank-info');
            if (bi) bi.style.display = 'none';
            validate(popup);
            dfpUpdateBillingNotice(popup);
        }
        var dfpPhoneEl = popup.querySelector('.dfp-phone');
        if (dfpPhoneEl) {
            dfpPhoneEl.addEventListener('input',  dfpAutoDetect);
            dfpPhoneEl.addEventListener('change', dfpAutoDetect);
        }
        // Submit
        popup.querySelector('.dfp-submit').addEventListener('click', function () { submitPayment(popup); });
        // Stop bubbling
        popup.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    /** Validate form, enable/disable submit */
    function validate(popup) {
        var st = popup._dfpState;
        var name = popup.querySelector('.dfp-name');
        var email = popup.querySelector('.dfp-email');
        var amount = popup.querySelector('.dfp-amount');
        var phoneWrap = popup.querySelector('.intl-phone-wrap');
        var phoneApi = phoneWrap ? phoneWrap._intlPhone : null;
        var phoneInput = phoneApi ? phoneApi.input : popup.querySelector('.dfp-phone');
        var phoneErr = popup.querySelector('.dfp-invalid-phone');
        var emailErr = popup.querySelector('.dfp-invalid-email');
        var submitBtn = popup.querySelector('.dfp-submit');

        var nameOk = name && name.value.trim().length > 0;
        var phoneOk = phoneApi ? phoneApi.isValid() : (phoneInput && phoneInput.value.trim().replace(/\D/g, '').length >= 7);
        var emailVal = email ? email.value.trim() : '';
        var emailOk = emailVal.length > 0 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal);
        var amountOk = amount && amount.value && Number(amount.value) >= 1000;
        var methodOk = !!st.method;

        if (phoneErr) {
            var hasVal = phoneInput && phoneInput.value.trim().length > 0;
            phoneErr.style.display = (hasVal && !phoneOk) ? 'block' : 'none';
            if (!phoneOk && hasVal) phoneErr.textContent = 'Invalid phone number for selected country.';
        }
        if (emailErr) {
            emailErr.style.display = (email && email.value.trim().length > 0 && !emailOk) ? 'block' : 'none';
        }
        if (submitBtn) submitBtn.disabled = !(nameOk && phoneOk && emailOk && amountOk && methodOk);
    }

    /** Smart positioning */
    function positionPopup(popup) {
        popup.classList.remove('pos-top', 'pos-bottom', 'pos-left');
        var wrap = popup.closest('.donate-trigger-wrap');
        if (!wrap) return;
        var btn = wrap.querySelector('.donate-button') || wrap.querySelector('.donate-trigger') || wrap.querySelector('.cta-button');
        if (!btn) btn = wrap;
        var r = btn.getBoundingClientRect();
        var vh = window.innerHeight, vw = window.innerWidth;
        var pH = 450, pW = 320;
        if (r.top >= pH + 20) popup.classList.add('pos-top');
        else if (vh - r.bottom >= pH + 20) popup.classList.add('pos-bottom');
        else if (r.left >= pW + 20) popup.classList.add('pos-left');
        else if (vh - r.bottom >= r.top) popup.classList.add('pos-bottom');
        else popup.classList.add('pos-top');
    }

    /** Open a popup */
    function openPopup(popup) {
        closeAllPopups();
        if (typeof closeLoginPopup === 'function') closeLoginPopup();
        if (typeof closeAllFloatPopups === 'function') closeAllFloatPopups();
        initPopup(popup);
        positionPopup(popup);
        popup.classList.add('active');
        popup.scrollTop = 0;
        setTimeout(function () { popup.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 50);
    }

    /** Close a specific popup */
    function closePopup(popup) {
        if (popup._dfpState) popup._dfpState.pollActive = false;
        popup.classList.remove('active');
    }

    /** Close ALL donate popups */
    function closeAllPopups() {
        document.querySelectorAll('.donate-float-popup.active').forEach(function (p) {
            if (p._dfpState) p._dfpState.pollActive = false;
            p.classList.remove('active');
        });
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       PAYMENT FLOW â€” uses overlay inside the popup (no page swap)
       â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

    /** Update the billing notice in a popup based on the current phone + selected method */
    function dfpUpdateBillingNotice(popup) {
        var notice = popup.querySelector('.dfp-billing-notice');
        if (!notice) return;
        var pSt = popup._dfpState;
        if (!pSt || (pSt.method !== 'mtn' && pSt.method !== 'airtel')) { notice.style.display = 'none'; return; }
        var pWrap  = popup.querySelector('.intl-phone-wrap');
        var pApi   = pWrap ? pWrap._intlPhone : null;
        var digits = pApi ? pApi.getDigitsOnly() : (popup.querySelector('.dfp-phone') ? popup.querySelector('.dfp-phone').value.replace(/\D/g,'') : '');
        if (!digits || digits.length < 7) { notice.style.display = 'none'; return; }
        var e164 = digits.length >= 11 && !digits.startsWith('0') ? '+' + digits
                 : digits.startsWith('0') ? '+256' + digits.slice(1) : '+256' + digits;
        notice.innerHTML = '\uD83D\uDCCC <strong>' + e164 + '</strong> will receive a <strong>'
            + (pSt.method === 'mtn' ? 'MTN' : 'Airtel') + '</strong> payment prompt \u2014 this is the number that will be charged.';
        notice.style.display = '';
    }

    /** Main payment submission â€” delegates to window.HosuPay */
    async function submitPayment(popup) {
        var st = popup._dfpState;
        var nameEl   = popup.querySelector('.dfp-name');
        var emailEl  = popup.querySelector('.dfp-email');
        var amountEl = popup.querySelector('.dfp-amount');
        var phoneWrap = popup.querySelector('.intl-phone-wrap');
        var phoneApi  = phoneWrap ? phoneWrap._intlPhone : null;
        var phone  = phoneApi ? phoneApi.getDigitsOnly()
                              : popup.querySelector('.dfp-phone').value.trim().replace(/\D/g, '');
        var name   = nameEl.value.trim();
        var email  = emailEl.value.trim();
        var amount = parseInt(amountEl.value);

        if (!name)   { alert('Please enter your full name.'); return; }
        if (phoneApi ? !phoneApi.isValid() : (phone.length < 7 || phone.length > 15))
            { alert('Please enter a valid phone number.'); return; }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))
            { alert('Please enter a valid email address.'); return; }
        if (!amount || amount < 1000)
            { alert('Please enter a valid amount (minimum 1,000 UGX).'); return; }
        if (!st.method) { alert('Please select a payment method.'); return; }

        closePopup(popup);   // close donate popup; HosuPay takes over the UI

        var methodLabel = st.method === 'mtn'    ? 'MTN Mobile Money'
                        : st.method === 'airtel' ? 'Airtel Money'
                        : st.method === 'visa'   ? 'Visa/Mastercard'
                        : 'Bank Transfer';

        HosuPay.open({
            step:    1,
            title:   'Processing Donation',
            purpose: 'Donation \u2014 Support HOSU',
            message: 'Preparing donation\u2026',
            onSuccess: function (tok) {
                if (st.paymentId) {
                    var cfd = new FormData();
                    cfd.append('payment_id',    st.paymentId);
                    cfd.append('receipt_token', tok || st.receiptToken || '');
                    fetch('api.php?action=confirm_payment', { method: 'POST', body: cfd })
                        .catch(function () {});
                }
            }
        });

        // Step 1: Pre-register in database
        try {
            var preFd = new FormData();
            preFd.append('fullName',         name);
            preFd.append('email',            email);
            preFd.append('phone',            phone);
            preFd.append('profession',       'Donor');
            preFd.append('institution',      '');
            preFd.append('paymentMethod',    methodLabel);
            preFd.append('paymentType',      'donation');
            preFd.append('membershipPeriod', '');
            preFd.append('amount',           amount);
            preFd.append('transactionId',    '');
            preFd.append('transactionRef',   '');
            var preResp = await fetch('api.php?action=pre_register', { method: 'POST', body: preFd });
            var preRes;
            try { preRes = await preResp.json(); } catch(je) { preRes = { error: 'Unexpected server response (HTTP ' + preResp.status + '). Please try again.' }; }
            if (preRes.error && preRes.csrf_error) {
                preRes.error = 'Session expired. Please refresh the page and try again.';
            }
            if (preRes.success) {
                st.receiptToken = preRes.receipt_token;
                st.paymentId    = preRes.payment_id;
            } else {
                HosuPay.error(preRes.error || 'Registration failed. Please try again.');
                return;
            }
        } catch (e) { HosuPay.error('Connection error. Please check your network and try again.'); return; }

        // Bank / Bank-to-Bank / Visa â€” PesaPal hosted page (iframe)
        if (st.method === 'bank' || st.method === 'banktobank' || st.method === 'visa') {
            HosuPay.setStep(2);
            HosuPay.update('Connecting to payment gateway\u2026');
            try {
                var payFd = new FormData();
                payFd.append('payment_id',    st.paymentId || 0);
                payFd.append('receipt_token', st.receiptToken || '');
                payFd.append('amount',        amount);
                payFd.append('email',         email);
                payFd.append('name',          name);
                payFd.append('phone',         phone);
                payFd.append('type',          'donation');
                var payResp = await fetch('payment.php?action=init_pesapal', { method: 'POST', body: payFd });
                var payRes;
                try { payRes = await payResp.json(); } catch(je) { payRes = { error: 'Payment gateway returned an invalid response. Please try again.' }; }
                if (payRes.error) { HosuPay.error(payRes.error); return; }
                if (payRes.redirect_url) {
                    HosuPay.openIframe({
                        redirectUrl:  payRes.redirect_url,
                        purpose:      'Donation \u2014 Support HOSU',
                        phone:        phone,
                        amount:       amount,
                        trackingId:   payRes.tracking_id || '',
                        payId:        st.paymentId || 0,
                        receiptToken: st.receiptToken || ''
                    });
                } else { HosuPay.error('Could not get payment link. Please try again.'); }
            } catch (e) { HosuPay.error('Network error.'); }
            return;
        }

        // MTN / Airtel â€” PesaPal USSD push
        var dfpChannel = st.method === 'mtn' ? 'UGMTNMOMODIR' : 'UGAIRTELMODIR';
        HosuPay.setStep(2);
        HosuPay.update(
            'Sending prompt to your ' + (st.method === 'mtn' ? 'MTN' : 'Airtel') + ' phone\u2026',
            'Please wait a moment\u2026'
        );
        try {
            var mobFd = new FormData();
            mobFd.append('phone',         phone);
            mobFd.append('amount',        amount);
            mobFd.append('email',         email);
            mobFd.append('name',          name);
            mobFd.append('payment_id',    st.paymentId || 0);
            mobFd.append('receipt_token', st.receiptToken || '');
            mobFd.append('channel',       dfpChannel);
            mobFd.append('type',          'donation');
            var mobResp = await fetch('payment.php?action=pay_mobile', { method: 'POST', body: mobFd });
            var mobRes;
            try { mobRes = await mobResp.json(); } catch(je) { mobRes = { error: 'Payment gateway returned an invalid response. Please try again.' }; }

            if (mobRes.error) { HosuPay.error(mobRes.error); return; }
            if (!mobRes.tracking_id && !mobRes.redirect_url) {
                HosuPay.error('Could not initiate payment. Please try again.'); return;
            }
            // PesaPal fires USSD push when user visits redirect_url â€” always show iframe
            if (mobRes.redirect_url) {
                HosuPay.openIframe({
                    redirectUrl:  mobRes.redirect_url,
                    purpose:      'Donation \u2014 Support HOSU',
                    phone:        phone,
                    amount:       amount,
                    trackingId:   mobRes.tracking_id || '',
                    payId:        st.paymentId || 0,
                    receiptToken: st.receiptToken || ''
                });
                return;
            }
            // No redirect_url â€” poll check_mobile directly
            HosuPay.update(
                '\uD83D\uDCF1 Check your ' + (st.method === 'mtn' ? 'MTN' : 'Airtel') + ' phone!',
                'Enter your mobile money PIN to approve <strong>UGX ' + amount.toLocaleString() + '</strong>.'
            );
            HosuPay.startPoll({
                trackingId:   mobRes.tracking_id,
                payId:        st.paymentId || 0,
                receiptToken: st.receiptToken || ''
            });
        } catch (e) { HosuPay.error('Network error. Please check your connection.'); }
    }

        /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       GLOBAL EVENT WIRING
       â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

    // Floating button
    floatWrap.querySelector('.donate-button').addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var popup = floatWrap.querySelector('.donate-float-popup');
        if (popup.classList.contains('active')) closeAllPopups();
        else openPopup(popup);
    });

    // Wire ALL .donate-trigger buttons on this page (e.g. CTA section)
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.donate-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var wrap = trigger.closest('.donate-trigger-wrap');
                if (wrap) {
                    var popup = wrap.querySelector('.donate-float-popup');
                    if (popup) {
                        if (popup.classList.contains('active')) closeAllPopups();
                        else openPopup(popup);
                        return;
                    }
                }
                // Fallback: use the floating popup
                var fp = floatWrap.querySelector('.donate-float-popup');
                if (fp.classList.contains('active')) closeAllPopups();
                else openPopup(fp);
            });
        });
    });

    // Outside click
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.donate-trigger-wrap') && !e.target.closest('.floating-donate')) {
            closeAllPopups();
        }
    });

    // Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllPopups();
            if (typeof closeLoginPopup === 'function') closeLoginPopup();
            if (typeof closeAllFloatPopups === 'function') closeAllFloatPopups();
        }
    });

    /* â”€â”€ Public API â”€â”€ */
    window._dfpToggle = function () {
        var popup = floatWrap.querySelector('.donate-float-popup');
        if (popup.classList.contains('active')) closeAllPopups();
        else openPopup(popup);
    };
    window._dfpClose = function () { closeAllPopups(); };
    window.closeDonatePopups = function () { closeAllPopups(); };
})();

