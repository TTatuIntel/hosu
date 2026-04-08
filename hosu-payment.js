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

    /* ── Inject DOM (once, lazily) ────────────────────────────── */
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

    /* ── Error ────────────────────────────────────────────────── */
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
     * Unified for membership, donation, and event payments.
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

        /* Bank / Bank-to-Bank — show transfer instructions */
        if (method === 'bank' || method === 'banktobank') {
            open({
                title: 'Bank Transfer',
                purpose: 'HOSU \u2014 ' + purpose,
                step: 1, spinner: false,
                message: 'Transfer UGX ' + Number(opts.amount).toLocaleString() + ' to:',
                submessage:
                    '<strong>HOSU Limited</strong><br>A/C: 9030025235214<br>Stanbic Bank, Mulago Branch<br><br>' +
                    'Send proof of payment to ' +
                    '<a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>' +
                    ' or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" ' +
                    'style="color:#0d4593;font-weight:700;">WhatsApp +256\u00A0709\u00A0752107</a>.',
                onCancel: opts.onCancel
            });
            _btn(true, 'Close');
            return;
        }

        /* Show processing modal */
        open({
            title: 'Processing Payment',
            purpose: 'HOSU \u2014 ' + purpose,
            step: 1,
            message: 'Preparing your payment\u2026',
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
            preRes = await (await fetch(preUrl, { method: 'POST', body: fd })).json();
        } catch (e) { _showErr('Connection error', 'Please check your internet and try again.'); return; }
        if (!preRes.success) { _showErr(preRes.error || 'Registration failed'); return; }

        var paymentId    = preRes.payment_id    || 0;
        var registrantId = preRes.registrant_id || 0;
        var receiptToken = preRes.receipt_token || '';

        /* Step 2 */
        _step(2);

        if (method === 'visa') {
            /* Visa — PesaPal hosted page (opens in iframe overlay) */
            _msg('Connecting to payment gateway\u2026');
            var vFd = new FormData();
            vFd.append('payment_id',    paymentId);
            vFd.append('registrant_id', registrantId);
            vFd.append('receipt_token', receiptToken);
            vFd.append('amount',        opts.amount);
            vFd.append('email',         opts.email);
            vFd.append('name',          opts.name);
            vFd.append('phone',         opts.phone);
            vFd.append('type',          type);
            vFd.append('purpose',       purpose);

            var vRes;
            try { vRes = await (await fetch('payment.php?action=init_pesapal', { method: 'POST', body: vFd })).json(); }
            catch (e) { _showErr('Network error'); return; }
            if (vRes.error) { _showErr(vRes.error); return; }

            if (vRes.redirect_url) {
                _msg('Redirecting to PesaPal\u2026');
                _openIfr({
                    redirectUrl: vRes.redirect_url, phone: opts.phone, amount: opts.amount,
                    trackingId: vRes.tracking_id, payId: paymentId, registrantId: registrantId,
                    receiptToken: receiptToken, purpose: 'HOSU \u2014 ' + purpose
                });
            } else { _showErr('Could not get payment link. Please try again.'); }
            return;
        }

        /* MTN / Airtel — USSD push via PesaPal */
        var isMtn        = method === 'mtn';
        var channel      = isMtn ? 'UGMTNMOMODIR' : 'UGAIRTELMODIR';
        var merchantCode = isMtn ? '721212' : '4373226';
        var netName      = isMtn ? 'MTN' : 'Airtel';

        _msg('Sending prompt to your ' + netName + ' phone\u2026');

        var mFd = new FormData();
        mFd.append('phone',         opts.phone);
        mFd.append('amount',        opts.amount);
        mFd.append('email',         opts.email);
        mFd.append('name',          opts.name);
        mFd.append('payment_id',    paymentId);
        mFd.append('registrant_id', registrantId);
        mFd.append('receipt_token', receiptToken);
        mFd.append('channel',       channel);
        mFd.append('type',          type);
        mFd.append('purpose',       purpose);

        var mRes;
        try { mRes = await (await fetch('payment.php?action=pay_mobile', { method: 'POST', body: mFd })).json(); }
        catch (e) { _showErr('Network error. Please try again.'); return; }
        if (mRes.error) { _showErr(mRes.error); return; }

        /* Fallback: PesaPal redirect (some account configurations) */
        if (mRes.redirect_url) {
            _msg('Redirecting to PesaPal\u2026');
            _openIfr({
                redirectUrl: mRes.redirect_url, phone: opts.phone, amount: opts.amount,
                trackingId: mRes.tracking_id, payId: paymentId, registrantId: registrantId,
                receiptToken: receiptToken, purpose: 'HOSU \u2014 ' + purpose
            });
            return;
        }

        /* USSD push sent — show instructions and poll */
        _step(2);
        _msg('\uD83D\uDCF1 Check your ' + netName + ' phone!');
        _sub(
            'Enter your PIN to approve <strong>UGX ' + Number(opts.amount).toLocaleString() + '</strong>.<br>' +
            '<small style="color:#94a3b8">Merchant code <strong>' + merchantCode + '</strong> (HOSU)</small>'
        );

        _startPoll({
            trackingId: mRes.tracking_id || '', payId: paymentId,
            registrantId: registrantId, receiptToken: receiptToken,
            maxPolls: 15, interval: 8000
        });
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
