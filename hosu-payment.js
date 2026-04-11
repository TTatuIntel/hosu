/* ================================================================
   HOSU Unified Payment Engine — hosu-payment.js v2.0
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
            'background:rgba(4,12,44,.5);z-index:99800;align-items:center;justify-content:center;' +
            'backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}' +
            '#_hpOv.hp-on{display:flex;animation:_hpFd .25s ease}' +
            '@keyframes _hpFd{from{opacity:0}to{opacity:1}}' +

            /* Card */
            '#_hpCard{background:#fff;border-radius:16px;padding:0;max-width:380px;' +
            'width:90vw;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.18),0 1px 3px rgba(0,0,0,.06);' +
            'font-family:Inter,system-ui,-apple-system,sans-serif;' +
            'animation:_hpSu .3s cubic-bezier(.22,1,.36,1);position:relative;overflow:hidden}' +
            '@keyframes _hpSu{from{opacity:0;transform:translateY(20px) scale(.97)}to{opacity:1;transform:none}}' +

            /* Top gradient accent */
            '#_hpCard::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;' +
            'background:linear-gradient(90deg,#0d4593 0%,#2563eb 35%,#16a34a 65%,#22c55e 100%)}' +

            /* Close X */
            '#_hpCloseX{position:absolute;top:12px;right:12px;width:28px;height:28px;border-radius:50%;' +
            'border:none;background:rgba(0,0,0,.05);color:#94a3b8;font-size:1.15rem;cursor:pointer;' +
            'display:flex;align-items:center;justify-content:center;line-height:1;z-index:2;transition:all .2s}' +
            '#_hpCloseX:hover{background:rgba(230,57,70,.1);color:#e63946}' +

            /* Card body padding */
            '#_hpBody{padding:20px 22px 18px}' +

            /* Brand bar */
            '#_hpBrand{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:10px}' +
            '#_hpBrand svg{width:15px;height:15px;flex-shrink:0}' +
            '#_hpBrand span{font-size:.6rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase}' +

            /* Title */
            '#_hpTitle{color:#0f172a;font-size:1.05rem;font-weight:800;margin:0 0 2px;line-height:1.3;letter-spacing:-.01em}' +

            /* Payment summary badge */
            '#_hpSummary{display:none;background:linear-gradient(135deg,#f0f7ff 0%,#e8f4fe 100%);' +
            'border:1px solid rgba(13,69,147,.12);border-radius:10px;padding:10px 14px;margin:10px 0 14px;' +
            'text-align:left;line-height:1.5}' +
            '#_hpSummary .hp-sum-title{font-size:.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase;' +
            'letter-spacing:.08em;margin-bottom:4px}' +
            '#_hpSummary .hp-sum-row{display:flex;justify-content:space-between;align-items:center;' +
            'font-size:.75rem;color:#334155;padding:1px 0}' +
            '#_hpSummary .hp-sum-row strong{color:#0d4593}' +
            '#_hpSummary .hp-sum-amount{font-size:.95rem;font-weight:800;color:#0d4593}' +
            '#_hpSummary .hp-sum-method{display:inline-flex;align-items:center;gap:4px;' +
            'background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px;font-size:.68rem;font-weight:600}' +
            '#_hpSummary .hp-sum-method img{width:14px;height:14px;object-fit:contain}' +

            /* Steps — horizontal stepper */
            '#_hpSteps{display:flex;align-items:center;justify-content:center;margin:0 auto 6px;gap:0;max-width:210px}' +
            '.hp-sd{width:28px;height:28px;border-radius:50%;border:2.5px solid #e2e8f0;' +
            'background:#fff;display:flex;align-items:center;justify-content:center;' +
            'font-size:.65rem;font-weight:800;color:#94a3b8;transition:all .4s cubic-bezier(.4,0,.2,1);flex-shrink:0;position:relative}' +
            '.hp-sd.hp-a{border-color:#0d4593;color:#fff;background:linear-gradient(135deg,#0d4593,#1d5bb5);' +
            'box-shadow:0 0 0 4px rgba(13,69,147,.1),0 2px 8px rgba(13,69,147,.2)}' +
            '.hp-sd.hp-d{border-color:#16a34a;background:#16a34a;color:#fff;' +
            'box-shadow:0 0 0 3px rgba(22,163,106,.08)}' +
            /* Checkmark for done steps */
            '.hp-sd.hp-d::after{content:"✓";font-size:.7rem;font-weight:900}' +
            '.hp-sd.hp-d span{display:none}' +
            '.hp-sl{flex:1;max-width:36px;height:2.5px;background:#e2e8f0;border-radius:2px;transition:background .4s}' +
            '.hp-sl.hp-d{background:#16a34a}.hp-sl.hp-a{background:linear-gradient(90deg,#16a34a,#0d4593)}' +

            /* Step labels */
            '#_hpLabels{display:flex;justify-content:space-between;margin:-2px auto 14px;padding:0 2px;max-width:230px}' +
            '.hp-lbl{font-size:.55rem;color:#94a3b8;text-align:center;width:54px;font-weight:700;' +
            'letter-spacing:.04em;text-transform:uppercase;transition:color .3s}' +
            '.hp-lbl.hp-a{color:#0d4593}.hp-lbl.hp-d{color:#16a34a}' +

            /* Spinner */
            '#_hpSpin{width:36px;height:36px;border:3px solid #e8f0fe;border-top-color:#0d4593;' +
            'border-radius:50%;animation:_hpRot .7s linear infinite;margin:0 auto 12px}' +
            '@keyframes _hpRot{to{transform:rotate(360deg)}}' +

            /* Messages */
            '#_hpMsg{font-size:.88rem;font-weight:700;margin:0 0 4px;color:#0f172a;transition:color .25s;line-height:1.35}' +
            '#_hpSub{font-size:.76rem;color:#64748b;line-height:1.55;margin:0 0 2px}' +
            '#_hpSub a{color:#0d4593;font-weight:700;text-decoration:none}' +
            '#_hpSub a:hover{text-decoration:underline}' +
            '#_hpCd{font-size:.68rem;color:#94a3b8;margin:4px 0 0;min-height:14px}' +

            /* Action button */
            '#_hpBtn{display:none;margin:14px auto 0;background:linear-gradient(135deg,#0d4593,#1d5bb5);' +
            'color:#fff;border:none;border-radius:10px;padding:10px 32px;' +
            'font-size:.82rem;font-weight:700;cursor:pointer;letter-spacing:.01em;' +
            'transition:all .2s;box-shadow:0 2px 10px rgba(13,69,147,.2)}' +
            '#_hpBtn:hover{background:linear-gradient(135deg,#0b3d80,#174ea6);transform:translateY(-1px);' +
            'box-shadow:0 4px 14px rgba(13,69,147,.3)}' +
            '#_hpBtn:active{transform:translateY(0)}' +
            /* Retry button (red variant) */
            '#_hpBtn.hp-retry{background:linear-gradient(135deg,#e63946,#dc2626)}' +
            '#_hpBtn.hp-retry:hover{background:linear-gradient(135deg,#c81d2a,#b91c1c)}' +

            /* Success state */
            '.hp-success-icon{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#22c55e);' +
            'display:flex;align-items:center;justify-content:center;margin:0 auto 12px;' +
            'box-shadow:0 4px 14px rgba(22,163,106,.25);animation:_hpPop .4s cubic-bezier(.22,1,.36,1)}' +
            '.hp-success-icon svg{width:28px;height:28px;stroke:#fff;stroke-width:2.5;fill:none}' +
            '@keyframes _hpPop{from{transform:scale(.5);opacity:0}to{transform:scale(1);opacity:1}}' +
            /* Error state */
            '.hp-error-icon{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#e63946,#dc2626);' +
            'display:flex;align-items:center;justify-content:center;margin:0 auto 12px;' +
            'box-shadow:0 4px 14px rgba(230,57,70,.25);animation:_hpPop .4s cubic-bezier(.22,1,.36,1)}' +
            '.hp-error-icon svg{width:28px;height:28px;stroke:#fff;stroke-width:2.5;fill:none}' +

            /* Receipt button */
            '.hp-receipt-btn{display:inline-block;background:linear-gradient(135deg,#0d4593,#1d5bb5);' +
            'color:#fff;padding:10px 28px;border-radius:10px;text-decoration:none;font-weight:700;' +
            'font-size:.82rem;margin-top:8px;transition:all .2s;box-shadow:0 2px 8px rgba(13,69,147,.2)}' +
            '.hp-receipt-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(13,69,147,.3)}' +

            /* ── Iframe overlay ── */
            '#_hpIfrW{display:none;position:fixed;top:0;left:0;width:100%;height:100%;' +
            'z-index:100000;align-items:center;justify-content:center;' +
            'background:rgba(4,12,44,.5);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}' +
            '#_hpIfrW.hp-on{display:flex;animation:_hpFd .25s ease}' +

            '#_hpIfrInner{background:#fff;border-radius:14px;overflow:hidden;width:94vw;max-width:480px;' +
            'height:85vh;max-height:720px;display:flex;flex-direction:column;' +
            'box-shadow:0 20px 60px rgba(0,0,0,.25);animation:_hpSu .3s cubic-bezier(.22,1,.36,1)}' +

            /* Iframe top bar */
            '#_hpIfrBar{background:linear-gradient(135deg,#0d4593,#1a55a8);color:#fff;padding:12px 16px;' +
            'display:flex;align-items:center;justify-content:space-between;flex-shrink:0;gap:10px}' +
            '#_hpIfrBarTxt{flex:1;min-width:0}' +
            '#_hpIfrBarTxt strong{font-size:.82rem;display:block;line-height:1.3}' +
            '#_hpIfrBarTxt span{font-size:.66rem;opacity:.85}' +
            '#_hpIfrX{background:rgba(255,255,255,.15);border:none;color:#fff;' +
            'width:32px;height:32px;border-radius:50%;font-size:1.15rem;' +
            'cursor:pointer;line-height:1;flex-shrink:0;transition:all .2s;display:flex;align-items:center;justify-content:center}' +
            '#_hpIfrX:hover{background:rgba(255,255,255,.3)}' +

            '#_hpIfrHint{background:linear-gradient(135deg,#f0f7ff,#e8f4fe);padding:8px 14px;font-size:.7rem;' +
            'color:#0d4593;font-weight:600;border-bottom:1px solid rgba(13,69,147,.1);flex-shrink:0;' +
            'display:flex;align-items:center;gap:6px}' +
            '#_hpIfrHint svg{width:14px;height:14px;flex-shrink:0}' +

            /* Iframe loading state */
            '#_hpIfrLoad{display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'flex:1;background:#f8fafc;gap:12px}' +
            '#_hpIfrLoad .hp-ifr-spinner{width:32px;height:32px;border:3px solid #e8f0fe;' +
            'border-top-color:#0d4593;border-radius:50%;animation:_hpRot .7s linear infinite}' +
            '#_hpIfrLoad p{font-size:.78rem;color:#64748b;font-weight:600;margin:0}' +

            '#_hpIframe{flex:1;width:100%;border:none;background:#fff}' +

            /* Responsive */
            '@media(max-width:480px){#_hpCard{width:94vw;max-width:none;border-radius:14px}' +
            '#_hpBody{padding:16px 16px 14px}' +
            '#_hpIfrInner{width:100vw;max-width:none;height:100vh;max-height:none;border-radius:0}}';
        document.head.appendChild(s);

        /* Backdrop + card */
        var ov = document.createElement('div');
        ov.id = '_hpOv';
        ov.innerHTML =
            '<div id="_hpCard">' +
                '<button id="_hpCloseX" aria-label="Close">&times;</button>' +
                '<div id="_hpBody">' +
                    '<div id="_hpBrand">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4" stroke="#16a34a"/></svg>' +
                        '<span>HOSU &mdash; Secure Payment</span>' +
                    '</div>' +
                    '<div id="_hpTitle">Processing Payment</div>' +
                    '<div id="_hpSummary"></div>' +
                    '<div id="_hpSteps">' +
                        '<div class="hp-sd hp-a" id="_hpD1"><span>1</span></div>' +
                        '<div class="hp-sl" id="_hpL1"></div>' +
                        '<div class="hp-sd" id="_hpD2"><span>2</span></div>' +
                        '<div class="hp-sl" id="_hpL2"></div>' +
                        '<div class="hp-sd" id="_hpD3"><span>3</span></div>' +
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
                '</div>' +
            '</div>';
        document.body.appendChild(ov);

        /* Iframe wrapper */
        var iw = document.createElement('div');
        iw.id = '_hpIfrW';
        iw.innerHTML =
            '<div id="_hpIfrInner">' +
            '<div id="_hpIfrBar">' +
                '<div id="_hpIfrBarTxt">' +
                    '<strong id="_hpIfrTitle">Complete Your Payment \u2014 HOSU</strong>' +
                    '<span id="_hpIfrInfo"></span>' +
                '</div>' +
                '<button id="_hpIfrX">&times;</button>' +
            '</div>' +
            '<div id="_hpIfrHint">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' +
                'Complete your payment securely below. Your billing details are pre-filled.' +
            '</div>' +
            '<div id="_hpIfrLoad">' +
                '<div class="hp-ifr-spinner"></div>' +
                '<p>Loading payment gateway\u2026</p>' +
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
        var iframe = _g('_hpIframe');
        if (iframe) { iframe.src = ''; iframe.style.display = 'none'; iframe.onload = null; }
        var ifrLoad = _g('_hpIfrLoad');
        if (ifrLoad) ifrLoad.style.display = 'flex';
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
        _g('_hpSummary').style.display = 'none';

        /* Success icon */
        _g('_hpMsg').innerHTML =
            '<div class="hp-success-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>';
        _g('_hpMsg').style.color = '#16a34a';

        var html = '<strong style="font-size:.95rem;color:#16a34a">Payment Confirmed!</strong><br>' +
            '<span style="color:#64748b">A receipt has been sent to your email.</span>';
        if (tok) {
            html += '<br><a href="receipt.php?token=' + encodeURIComponent(tok) + '" class="hp-receipt-btn">' +
                '\uD83D\uDCC4 View Receipt</a>';
        }
        _sub(html);
        _btn(true, 'Close');
        _g('_hpBtn').className = '';
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
        _g('_hpSummary').style.display = 'none';

        /* Error icon */
        _g('_hpMsg').innerHTML =
            '<div class="hp-error-icon"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>';
        _g('_hpMsg').style.color = '#dc2626';

        /* Auto-cancel pending payment record so it doesn't linger in the DB */
        _autoCancelPending();

        var errTitle = '<strong style="font-size:.88rem;color:#dc2626">' + (msg || 'Payment could not be completed.') + '</strong>';
        var helpHtml = detail ||
            '<span style="color:#64748b">Your pending payment has been cancelled automatically.</span><br>' +
            'If money was deducted, contact ' +
            '<a href="mailto:info@hosu.or.ug">info@hosu.or.ug</a>' +
            ' or <a href="https://wa.me/256709752107" target="_blank" rel="noopener">' +
            'WhatsApp +256&nbsp;709&nbsp;752107</a>.';
        _sub(errTitle + '<br>' + helpHtml);
        _btn(true, 'Try Again');
        _g('_hpBtn').className = 'hp-retry';
        _on();
        if (_cbError) _cbError(msg);
    }

    /* ── Open PesaPal iframe overlay ──────────────────────────── */
    function _openIfr(opts) {
        var ht = _g('_hpIfrTitle');
        if (ht) ht.textContent = opts.purpose ? opts.purpose + ' \u2014 HOSU' : 'Complete Your Payment \u2014 HOSU';
        _g('_hpIfrInfo').textContent =
            _fmtPhone(opts.phone) +
            (opts.amount ? ' \u00B7 UGX ' + Number(opts.amount).toLocaleString() : '');

        /* Show loading state first, hide iframe */
        var ifrLoad = _g('_hpIfrLoad');
        var iframe = _g('_hpIframe');
        if (ifrLoad) ifrLoad.style.display = 'flex';
        if (iframe) iframe.style.display = 'none';

        iframe.src = opts.redirectUrl;
        _g('_hpIfrW').classList.add('hp-on');
        _off();

        /* When iframe finishes loading, hide the loading state */
        iframe.onload = function () {
            if (ifrLoad) ifrLoad.style.display = 'none';
            if (iframe) iframe.style.display = '';
        };

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

        /* Payment summary card */
        var ps = _g('_hpSummary');
        if (ps) {
            if (opts.summaryData) {
                var sd = opts.summaryData;
                var methodIcons = {
                    mtn: 'img/mtn.png', airtel: 'img/airtel.png',
                    visa: 'img/visa.png', bank: 'img/visa.png', banktobank: 'img/visa.png'
                };
                var methodNames = { mtn: 'MTN MoMo', airtel: 'Airtel Money', visa: 'Card', bank: 'Card', banktobank: 'Bank Transfer' };
                var mIcon = methodIcons[sd.method] || '';
                var mName = methodNames[sd.method] || sd.method || '';
                var sumHtml = '<div class="hp-sum-title">Payment Summary</div>';
                if (sd.name) sumHtml += '<div class="hp-sum-row"><span>Name</span><strong>' + sd.name + '</strong></div>';
                if (sd.amount) sumHtml += '<div class="hp-sum-row"><span>Amount</span><span class="hp-sum-amount">UGX ' + Number(sd.amount).toLocaleString() + '</span></div>';
                if (mName) sumHtml += '<div class="hp-sum-row"><span>Method</span><span class="hp-sum-method">' +
                    (mIcon ? '<img src="' + mIcon + '" alt="">' : '') + mName + '</span></div>';
                ps.innerHTML = sumHtml;
                ps.style.display = '';
            } else if (opts.purpose) {
                ps.innerHTML = '<div class="hp-sum-title">' + opts.purpose + '</div>';
                ps.style.display = '';
            } else {
                ps.style.display = 'none';
            }
        }

        _step(opts.step || 1);
        _spin(opts.spinner !== false);
        _msg(opts.message || 'Please wait\u2026', opts.msgColor);
        _sub(opts.submessage || '');
        _cd('');
        _btn(false);
        _g('_hpBtn').className = '';
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

        /* Show processing modal with summary */
        open({
            title: 'Processing ' + typeLabel,
            summaryData: { name: opts.name, amount: opts.amount, method: method },
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
        try {
            var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var tmo = ctrl ? setTimeout(function(){ ctrl.abort(); }, 35000) : null;
            var fetchOpts = { method: 'POST', body: pFd };
            if (ctrl) fetchOpts.signal = ctrl.signal;
            var pRaw = await fetch('payment.php?action=init_pesapal', fetchOpts);
            if (tmo) clearTimeout(tmo);
            var pText = await pRaw.text();
            try { pRes = JSON.parse(pText); }
            catch (_) {
                console.error('init_pesapal non-JSON (HTTP ' + pRaw.status + '):', pText.substring(0, 500));
                _showErr('Server returned an unexpected response.',
                    'HTTP ' + pRaw.status + '. This may be a temporary server issue. Please try again or contact <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>.');
                return;
            }
        } catch (e) {
            var errMsg = e && e.name === 'AbortError' ? 'Connection to PesaPal timed out.' : 'Network error connecting to payment server.';
            console.error('init_pesapal fetch error:', e);
            _showErr(errMsg, 'Please check your internet connection and try again. If problem persists, contact <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>.');
            return;
        }
        if (pRes.error) {
            console.error('PesaPal init error:', pRes.error, 'HTTP:', pRes.pesapal_code, 'amount:', pRes.amount);
            var errDetail = '<span style="color:#64748b">PesaPal was unable to create the payment page.</span><br>' +
                'Please try again, or contact ' +
                '<a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a>' +
                ' or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" style="color:#0d4593;font-weight:700;">' +
                'WhatsApp +256&nbsp;709&nbsp;752107</a> for help.';
            _showErr(pRes.error, errDetail);
            return;
        }

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
