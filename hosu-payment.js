/* ================================================================
   HOSU Unified Payment Engine — hosu-payment.js v1.0
   Single payment UI for every page. Exposes window.HosuPay.
   Loaded automatically by donate-float.js (universal include).
   ================================================================ */
(function (global) {
    'use strict';

    /* ── Private state ────────────────────────────────────────── */
    var _built      = false;
    var _pollTimer  = null;
    var _pollCount  = 0;
    var _msgHandler = null;
    var _cbSuccess  = null;
    var _cbError    = null;
    var _cbCancel   = null;
    var _lastPayId  = null;
    var _lastRecTok = null;

    /* ── Inject DOM (once, lazily) ────────────────────────────── */
    function _build() {
        if (_built) return;
        _built = true;

        /* CSS */
        var s = document.createElement('style');
        s.textContent =
            /* Backdrop */
            '#_hpOv{display:none;position:fixed;top:0;left:0;width:100%;height:100%;' +
            'background:rgba(4,12,44,.55);z-index:99800;align-items:center;justify-content:center;' +
            'backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}' +
            '#_hpOv.hp-on{display:flex;animation:_hpFd .22s ease}' +
            '@keyframes _hpFd{from{opacity:0}to{opacity:1}}' +
            /* Card — compact */
            '#_hpCard{background:#fff;border-radius:14px;padding:18px 18px 16px;max-width:340px;' +
            'width:88vw;text-align:center;box-shadow:0 12px 40px rgba(13,69,147,.22),0 1px 3px rgba(0,0,0,.06);' +
            'font-family:Inter,system-ui,-apple-system,sans-serif;' +
            'animation:_hpSu .28s cubic-bezier(.22,1,.36,1);position:relative;overflow:hidden}' +
            '@keyframes _hpSu{from{opacity:0;transform:translateY(16px) scale(.98)}to{opacity:1;transform:none}}' +
            /* Decorative top accent bar */
            '#_hpCard::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;' +
            'background:linear-gradient(90deg,#0d4593 0%,#2563eb 40%,#16a34a 70%,#f59e0b 100%)}' +
            /* Close X button on card */
            '#_hpCloseX{position:absolute;top:8px;right:10px;width:26px;height:26px;border-radius:50%;' +
            'border:none;background:rgba(0,0,0,.06);color:#64748b;font-size:1.1rem;cursor:pointer;' +
            'display:flex;align-items:center;justify-content:center;line-height:1;z-index:2;transition:all .2s}' +
            '#_hpCloseX:hover{background:rgba(230,57,70,.12);color:#e63946}' +
            /* Brand bar inside card */
            '#_hpBrand{display:flex;align-items:center;justify-content:center;gap:6px;' +
            'margin-bottom:8px;padding-top:2px}' +
            '#_hpBrand svg{width:16px;height:16px;flex-shrink:0}' +
            '#_hpBrand span{font-size:.62rem;font-weight:700;letter-spacing:.08em;' +
            'color:#64748b;text-transform:uppercase}' +
            '#_hpTitle{color:#0f172a;font-size:.95rem;font-weight:800;margin:0 0 4px;line-height:1.3;' +
            'letter-spacing:-.01em}' +
            '#_hpSummary{display:none;background:linear-gradient(135deg,#f0f7ff 0%,#e8f4fe 100%);' +
            'border:1px solid #bdd5f7;border-radius:8px;padding:5px 10px;margin:0 0 10px;' +
            'font-size:.72rem;font-weight:700;color:#0d4593;text-align:center;line-height:1.35}' +
            /* Steps — compact */
            '#_hpSteps{display:flex;align-items:center;justify-content:center;' +
            'margin:0 auto 14px;gap:0;max-width:200px}' +
            '.hp-sd{width:26px;height:26px;border-radius:50%;border:2px solid #e2e8f0;' +
            'background:#fff;display:flex;align-items:center;justify-content:center;' +
            'font-size:.62rem;font-weight:800;color:#94a3b8;transition:all .35s cubic-bezier(.4,0,.2,1);flex-shrink:0}' +
            '.hp-sd.hp-a{border-color:#0d4593;color:#fff;background:#0d4593;' +
            'box-shadow:0 0 0 3px rgba(13,69,147,.12)}' +
            '.hp-sd.hp-d{border-color:#16a34a;background:#16a34a;color:#fff;' +
            'box-shadow:0 0 0 3px rgba(22,163,106,.08)}' +
            '.hp-sl{flex:1;max-width:36px;height:2px;background:#e2e8f0;' +
            'border-radius:2px;transition:background .4s}' +
            '.hp-sl.hp-d{background:#16a34a}.hp-sl.hp-a{background:#0d4593}' +
            /* Step labels */
            '#_hpLabels{display:flex;justify-content:space-between;' +
            'margin:-8px auto 12px;padding:0 2px;max-width:220px}' +
            '.hp-lbl{font-size:.56rem;color:#94a3b8;text-align:center;' +
            'width:50px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}' +
            '.hp-lbl.hp-a{color:#0d4593}.hp-lbl.hp-d{color:#16a34a}' +
            /* Spinner — smaller */
            '#_hpSpin{width:32px;height:32px;border:3px solid #e8f0fe;' +
            'border-top-color:#0d4593;border-radius:50%;' +
            'animation:_hpRot .8s linear infinite;margin:0 auto 10px}' +
            '@keyframes _hpRot{to{transform:rotate(360deg)}}' +
            /* Message */
            '#_hpMsg{font-size:.88rem;font-weight:700;margin:0 0 4px;' +
            'color:#0f172a;transition:color .25s;line-height:1.35}' +
            '#_hpSub{font-size:.76rem;color:#64748b;line-height:1.55;margin:0 0 2px}' +
            '#_hpSub a{color:#0d4593;font-weight:700;text-decoration:none}' +
            '#_hpSub a:hover{text-decoration:underline}' +
            '#_hpCd{font-size:.68rem;color:#94a3b8;margin:4px 0 0;min-height:14px}' +
            /* Close/CTA button — compact */
            '#_hpBtn{display:none;margin:12px auto 0;background:linear-gradient(135deg,#0d4593,#1d5bb5);' +
            'color:#fff;border:none;border-radius:8px;padding:9px 28px;' +
            'font-size:.82rem;font-weight:700;cursor:pointer;letter-spacing:.01em;' +
            'transition:all .2s;box-shadow:0 2px 8px rgba(13,69,147,.2)}' +
            '#_hpBtn:hover{background:linear-gradient(135deg,#0b3d80,#174ea6);transform:translateY(-1px);' +
            'box-shadow:0 4px 12px rgba(13,69,147,.3)}' +
            '#_hpBtn:active{transform:translateY(0)}' +
            /* Iframe overlay — centered modal instead of full-screen */
            '#_hpIfrW{display:none;position:fixed;top:0;left:0;width:100%;height:100%;' +
            'z-index:100000;align-items:center;justify-content:center;' +
            'background:rgba(4,12,44,.55);backdrop-filter:blur(3px)}' +
            '#_hpIfrW.hp-on{display:flex}' +
            '#_hpIfrInner{background:#fff;border-radius:12px;overflow:hidden;width:94vw;max-width:480px;' +
            'height:85vh;max-height:700px;display:flex;flex-direction:column;' +
            'box-shadow:0 16px 48px rgba(0,0,0,.25)}' +
            '#_hpIfrBar{background:linear-gradient(135deg,#0d4593,#1a55a8);color:#fff;padding:10px 14px;' +
            'display:flex;align-items:center;justify-content:space-between;' +
            'flex-shrink:0;gap:10px}' +
            '#_hpIfrBarTxt{flex:1;min-width:0}' +
            '#_hpIfrBarTxt strong{font-size:.82rem;display:block}' +
            '#_hpIfrBarTxt span{font-size:.66rem;opacity:.88}' +
            '#_hpIfrX{background:rgba(255,255,255,.18);border:none;color:#fff;' +
            'width:30px;height:30px;border-radius:50%;font-size:1.1rem;' +
            'cursor:pointer;line-height:1;flex-shrink:0;transition:background .2s}' +
            '#_hpIfrX:hover{background:rgba(255,255,255,.32)}' +
            '#_hpIfrHint{background:#f0f4ff;padding:5px 12px;font-size:.68rem;' +
            'color:#0d4593;font-weight:600;border-bottom:1px solid #dce8ff;flex-shrink:0}' +
            '#_hpIframe{flex:1;width:100%;border:none;background:#fff}';
        document.head.appendChild(s);

        /* Backdrop + card */
        var ov = document.createElement('div');
        ov.id = '_hpOv';
        ov.innerHTML =
            '<div id="_hpCard">' +
                '<button id="_hpCloseX" aria-label="Close">&times;</button>' +
                '<div id="_hpBrand">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4" stroke="#16a34a"/></svg>' +
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
                    '<span class="hp-lbl hp-a" id="_hpLbl1">Pay</span>' +
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
            '<div id="_hpIfrInner">' +
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
            '<iframe id="_hpIframe" src="" allowpaymentrequest></iframe>' +
            '</div>';
        document.body.appendChild(iw);

        /* Click-outside-to-close: clicking the backdrop dismisses the overlay */
        ov.addEventListener('click', function (e) {
            if (e.target === ov) {
                _dismiss();
                if (_cbCancel) _cbCancel();
            }
        });

        /* Close X on card */
        document.getElementById('_hpCloseX').addEventListener('click', function () {
            _dismiss();
            if (_cbCancel) _cbCancel();
        });

        /* Button listeners (wired once) */
        document.getElementById('_hpBtn').addEventListener('click', function () {
            _dismiss();
            if (_cbCancel) _cbCancel();
        });

        /* Click-outside iframe overlay to close */
        iw.addEventListener('click', function (e) {
            if (e.target === iw) {
                _ifrClose();
                _showErr('Payment cancelled. You can try again by re-submitting the form.');
            }
        });
        document.getElementById('_hpIfrX').addEventListener('click', function () {
            _ifrClose();
            _showErr('Payment cancelled. You can try again by re-submitting the form.');
        });

        /* Escape key to close */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (_g('_hpIfrW') && _g('_hpIfrW').classList.contains('hp-on')) {
                    _ifrClose();
                    _showErr('Payment cancelled. You can try again by re-submitting the form.');
                } else if (_g('_hpOv') && _g('_hpOv').classList.contains('hp-on')) {
                    _dismiss();
                    if (_cbCancel) _cbCancel();
                }
            }
        });
    }

    /* ── Helpers ──────────────────────────────────────────────── */
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

    function _msg(t, c)  { var e = _g('_hpMsg'); e.textContent = t; e.style.color = c || '#0f172a'; }
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
        _autoCancelPending();
        _off();
    }

    /* ── Format phone for display ─────────────────────────────── */
    function _fmtPhone(raw) {
        var d = String(raw || '').replace(/\D/g, '');
        if (d.length >= 11 && !d.startsWith('0')) return '+' + d;
        if (d.startsWith('0')) return '+256' + d.slice(1);
        return '+256' + d;
    }

    /* ── Success ──────────────────────────────────────────────── */
    function _showOk(tok) {
        _stopAll();
        _ifrClose();
        _lastPayId = null; _lastRecTok = null;
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

    /* ── Auto-cancel pending payment from DB ─────────────────── */
    function _autoCancelPending() {
        if (!_lastPayId || !_lastRecTok) return;
        var pid = _lastPayId, tok = _lastRecTok;
        _lastPayId = null; _lastRecTok = null;
        var fd = new FormData();
        fd.append('action', 'cancel_pending_payment');
        fd.append('payment_id', pid);
        fd.append('receipt_token', tok);
        fetch('api.php', { method: 'POST', body: fd }).catch(function () {});
    }

    /* ── Error ────────────────────────────────────────────────── */
    function _showErr(msg, detail) {
        _stopAll();
        _ifrClose();
        _spin(false);
        _cd('');
        _msg('\u274C ' + (msg || 'Payment could not be completed.'), '#dc2626');

        /* Auto-cancel pending payment record so it doesn't linger in the DB */
        _autoCancelPending();

        var helpHtml = detail ||
            'Your pending payment has been cancelled automatically.<br>' +
            'If money was deducted from your account, contact us at ' +
            '<a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>' +
            ' or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" ' +
            'style="color:#0d4593;font-weight:700;">WhatsApp +256&nbsp;709&nbsp;752107</a>.';
        _sub(helpHtml);
        _btn(true, 'Try Again');
        _on();
        if (_cbError) _cbError(msg);
    }

    /* ── Open PesaPal iframe overlay ──────────────────────────── */
    function _openIfr(opts) {
        var ht = _g('_hpIfrTitle');
        if (ht) ht.textContent = opts.purpose ? opts.purpose + ' \u2014 HOSU' : 'Complete Your Payment \u2014 HOSU';
        _g('_hpIfrInfo').textContent =
            '\uD83D\uDCF1 ' + _fmtPhone(opts.phone) +
            (opts.amount ? ' \u00B7 UGX ' + Number(opts.amount).toLocaleString() : '');
        _g('_hpIframe').src = opts.redirectUrl;
        _g('_hpIfrW').classList.add('hp-on');
        _off();

        /* postMessage listener — instant callback from pesapal_callback.php */
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
                if (++_pollCount > 23) {
                    _showErr('Payment timed out. Your pending payment has been cancelled.');
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
                } catch (e) { /* network hiccup — keep polling */ }
            }, 8000);
        }
    }

    /* ── Direct poll (no iframe) ──────────────────────────────── */
    function _startPoll(opts) {
        var MAX = opts.maxPolls || 15, INT = opts.interval || 8000;
        _pollCount = 0;
        _pollTimer = setInterval(async function () {
            _pollCount++;
            var secs = Math.max(0, (MAX - _pollCount + 1) * (INT / 1000));
            _cd('Time remaining: ' + Math.floor(secs / 60) + 'm ' + (secs % 60) + 's');
            if (_pollCount > MAX) {
                _showErr('Payment timed out. Your pending payment has been cancelled.',
                    'No money was deducted. You can try again.<br>' +
                    'If you believe a payment was made, contact ' +
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

    /* ══════════════════════════════════════════════════════════
       PUBLIC API — window.HosuPay
       ══════════════════════════════════════════════════════════ */

    /**
     * Open the payment processing modal.
     *
     * opts = {
     *   step       : 1|2|3          — initial step (default 1)
     *   title      : string         — modal title
     *   message    : string         — main message text
     *   submessage : string         — HTML below message
     *   spinner    : bool           — show spinner (default true)
     *   onSuccess  : fn(token)      — called with receipt token on success
     *   onError    : fn(msg)        — called with message string on error
     *   onCancel   : fn()           — called when user clicks Close before completion
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

    /**
     * Full payment flow: pre-register → process → poll/redirect.
     * ALL methods go through PesaPal uniformly.
     *
     * opts = {
     *   purpose      : string — "Membership (1yr)", "Donation", "Event: Title"
     *   type         : string — "membership" | "donation" | "event_registration"
     *   name, email, phone (digits), amount
     *   method       : "mtn" | "airtel" | "visa" | "bank" | "banktobank"
     *   preRegUrl    : string (default "api.php?action=pre_register")
     *   preRegFields : {} extra FormData fields for pre-registration
     *   onSuccess    : fn(token)
     *   onError      : fn(msg)
     *   onCancel     : fn()
     * }
     */
    async function processPayment(opts) {
        _build();
        opts = opts || {};
        _cbSuccess = opts.onSuccess || null;
        _cbError   = opts.onError   || null;
        _cbCancel  = opts.onCancel  || null;
        _stopAll();

        var purpose = opts.purpose || 'Payment';
        var type    = opts.type    || 'membership';
        var method  = opts.method;

        /* Build display label for payment type */
        var typeLabels = {
            membership: 'Membership',
            donation: 'Donation',
            event_registration: 'Event Registration'
        };
        var typeLabel = typeLabels[type] || 'Payment';

        /* Show processing modal */
        open({
            title: 'Processing ' + typeLabel,
            purpose: purpose,
            step: 1,
            message: 'Preparing your ' + typeLabel.toLowerCase() + ' payment\u2026',
            onSuccess: opts.onSuccess,
            onError: opts.onError,
            onCancel: opts.onCancel
        });

        /* Step 1: Pre-register */
        var preUrl = opts.preRegUrl || 'api.php?action=pre_register';
        var fd = new FormData();
        fd.append('fullName', opts.name);
        fd.append('email',    opts.email);
        fd.append('phone',    opts.phone);
        fd.append('amount',   opts.amount);
        var extra = opts.preRegFields || {};
        for (var k in extra) { if (extra.hasOwnProperty(k)) fd.append(k, extra[k]); }

        var preRes;
        try {
            var preRaw = await fetch(preUrl, { method: 'POST', body: fd });
            var preText = await preRaw.text();
            try { preRes = JSON.parse(preText); }
            catch (_) { _showErr('Server returned an unexpected response.', 'This may be a temporary server issue. Please try again in a moment or contact <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>.'); console.error('Pre-register non-JSON:', preText.substring(0, 500)); return; }
        } catch (e) { _showErr('Connection error', 'Please check your internet and try again.'); return; }
        if (!preRes.success) { _showErr(preRes.error || 'Registration failed'); return; }


        // Normalize phone: strip non-digits, add Uganda country code if missing
        var phone = (opts.phone || '').replace(/\D/g, '');
        if (phone.charAt(0) === '0' && phone.length >= 9) phone = '256' + phone.substring(1);
        else if (phone.length >= 7 && phone.length <= 9) phone = '256' + phone;

        var paymentId    = preRes.payment_id    || 0;
        var registrantId = preRes.registrant_id || 0;
        var receiptToken = preRes.receipt_token || '';
        _lastPayId  = paymentId;
        _lastRecTok = receiptToken;

        /* Step 2 */
        _step(2);

        /* All methods — PesaPal hosted page in iframe.
           Provides a consistent, reliable payment experience
           for MTN, Airtel, Visa, and all other methods. */
        _msg('Connecting to PesaPal\u2026');
        var pFd = new FormData();
        pFd.append('payment_id',    paymentId);
        pFd.append('registrant_id', registrantId);
        pFd.append('receipt_token', receiptToken);
        pFd.append('amount',        opts.amount);
        pFd.append('email',         opts.email);
        pFd.append('name',          opts.name);
        pFd.append('phone',         phone);
        pFd.append('type',          type);
        pFd.append('purpose',       purpose);

        var pRes;
        try { pRes = await (await fetch('payment.php?action=init_pesapal', { method: 'POST', body: pFd })).json(); }
        catch (e) { _showErr('Network error. Please try again.'); return; }
        if (pRes.error) { _showErr(pRes.error); return; }

        if (pRes.redirect_url) {
            _msg('Opening secure payment page\u2026');
            _openIfr({
                redirectUrl: pRes.redirect_url, phone: phone, amount: opts.amount,
                trackingId: pRes.tracking_id, payId: paymentId, registrantId: registrantId,
                receiptToken: receiptToken, purpose: typeLabel + ': ' + purpose
            });
        } else { _showErr('Could not get payment link. Please try again.'); }
    }

    global.HosuPay = {
        open:           open,
        setStep:        setStep,
        update:         update,
        openIframe:     openIframe,
        startPoll:      startPoll,
        success:        success,
        error:          error,
        close:          close,
        processPayment: processPayment
    };

}(window));
