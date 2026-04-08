/* ============================================================
   UNIFIED DONATE / SUPPORT — donate-float.js
   Single source of truth for ALL donate/support popups.
   Include on ALL pages alongside donate-float.css.
   ============================================================ */
(function () {
    'use strict';

    /* ── AUTO-INJECT CSRF TOKEN INTO ALL POST REQUESTS ── */
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

    /* ── Shared popup HTML (class-based, no IDs — scoped per popup) ── */
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
        '<button class="dfp-submit" disabled>Process Payment</button>' +
        '<div class="dfp-process-overlay" style="display:none">' +
            '<div class="dfp-proc-steps">' +
                '<span class="dfp-step active"></span>' +
                '<span class="dfp-step"></span>' +
                '<span class="dfp-step"></span>' +
            '</div>' +
            '<div class="dfp-proc-spinner"></div>' +
            '<div class="dfp-proc-msg">Preparing\u2026</div>' +
            '<div class="dfp-proc-sub"></div>' +
            '<div class="dfp-proc-countdown" style="display:none"></div>' +
            '<button class="dfp-proc-close" style="display:none">Close</button>' +
        '</div>';

    /* ── Inject floating button ── */
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

    /* ═════════════════════════════════════════════════════════
       POPUP LIFECYCLE — works for ANY .donate-float-popup
       ═════════════════════════════════════════════════════════ */

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

    /* ═════════════════════════════════════════════════════════
       PAYMENT FLOW — uses overlay inside the popup (no page swap)
       ═════════════════════════════════════════════════════════ */

    function setStep(overlay, n) {
        overlay.querySelectorAll('.dfp-step').forEach(function (s, i) {
            s.className = 'dfp-step' + (i < n ? ' done' : '') + (i === n ? ' active' : '');
        });
    }

    function showError(overlay, errMsg, helpHtml) {
        overlay.querySelector('.dfp-proc-spinner').style.display = 'none';
        overlay.querySelector('.dfp-proc-countdown').style.display = 'none';
        var msg = overlay.querySelector('.dfp-proc-msg');
        var sub = overlay.querySelector('.dfp-proc-sub');
        msg.textContent = '\u274C ' + errMsg;
        msg.style.color = '#e63946';
        if (helpHtml) sub.innerHTML = helpHtml;
        else sub.innerHTML = 'If you completed payment by mobile money, provide your proof of payment on <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a> or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" style="color:#0d4593;font-weight:700;">WhatsApp +256 709 752107</a>.';
        var btn = overlay.querySelector('.dfp-proc-close');
        btn.textContent = 'Close';
        btn.style.display = '';
        btn.onclick = function () { overlay.style.display = 'none'; msg.style.color = ''; };
    }

    function showSuccess(overlay, popup) {
        var st = popup._dfpState;
        setStep(overlay, 3);
        overlay.querySelector('.dfp-proc-spinner').style.display = 'none';
        overlay.querySelector('.dfp-proc-countdown').style.display = 'none';
        var msg = overlay.querySelector('.dfp-proc-msg');
        var sub = overlay.querySelector('.dfp-proc-sub');
        msg.style.color = '#27ae60';
        var btn = overlay.querySelector('.dfp-proc-close');

        if (st.receiptToken && st.paymentId) {
            msg.textContent = 'Confirming payment\u2026';
            var fd = new FormData();
            fd.append('payment_id', st.paymentId);
            fd.append('receipt_token', st.receiptToken);
            fetch('api.php?action=confirm_payment', { method: 'POST', body: fd })
                .catch(function () {})
                .then(function () {
                    msg.textContent = '\u2705 Donation confirmed!';
                    sub.innerHTML = 'A receipt has been sent to your email.<br><br>' +
                        '<a href="receipt.php?token=' + encodeURIComponent(st.receiptToken) + '" ' +
                        'style="display:inline-block;background:#0d4593;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.75rem;">' +
                        '\uD83D\uDCC4 View Receipt</a>';
                    btn.textContent = 'Done';
                    btn.style.display = '';
                    btn.onclick = function () {
                        overlay.style.display = 'none';
                        msg.style.color = '';
                        closePopup(popup);
                    };
                });
        } else {
            msg.textContent = '\u2705 Payment Successful!';
            sub.textContent = 'Thank you for your donation!';
            btn.textContent = 'Done';
            btn.style.display = '';
            btn.onclick = function () {
                overlay.style.display = 'none';
                msg.style.color = '';
                closePopup(popup);
            };
        }
    }

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

    /** Embed PesaPal payment page as a full-screen inline overlay instead of redirecting away */
    function dfpShowPesapalIframe(redirectUrl, phoneDigits, amount, trackingId, payId, receiptToken, overlay, popup) {
        var pSt  = popup._dfpState;
        var d    = String(phoneDigits).replace(/\D/g,'');
        var disp = d.length >= 11 && !d.startsWith('0') ? '+' + d
                 : d.startsWith('0') ? '+256' + d.slice(1) : '+256' + d;
        var ifrWrap = document.createElement('div');
        ifrWrap.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2147483647;display:flex;flex-direction:column;background:#fff;';
        ifrWrap.innerHTML =
            '<div style="background:#0d4593;color:#fff;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">'
            + '<div><strong style="font-size:0.9rem;">Complete Payment \u2014 HOSU</strong><br>'
            + '<span style="font-size:0.72rem;opacity:0.88;">\uD83D\uDCF1 ' + disp.replace(/</g,'&lt;') + ' &middot; UGX ' + Number(amount).toLocaleString() + '</span></div>'
            + '<button id="_dfpIfrClose" style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:28px;height:28px;border-radius:50%;font-size:1rem;cursor:pointer;line-height:1;">\u00D7</button>'
            + '</div>'
            + '<div style="background:#f0f4ff;padding:5px 14px;font-size:0.75rem;color:#0d4593;font-weight:600;border-bottom:1px solid #dce8ff;flex-shrink:0;">'
            + '\uD83D\uDD12 Complete your payment securely below. Your billing details are pre-filled.'
            + '</div>'
            + '<iframe src="' + redirectUrl.replace(/"/g,'&quot;') + '" style="flex:1;width:100%;border:none;background:#fff;" allowpaymentrequest></iframe>';
        document.body.appendChild(ifrWrap);
        pSt.pollActive = true;
        var _dfpIfrPolls = 0;
        var _dfpIfrTimer = setInterval(function () {
            if (!pSt.pollActive) { clearInterval(_dfpIfrTimer); window.removeEventListener('message', _dfpMsgHandler); return; }
            _dfpIfrPolls++;
            if (_dfpIfrPolls > 22) {
                clearInterval(_dfpIfrTimer); pSt.pollActive = false;
                window.removeEventListener('message', _dfpMsgHandler);
                if (document.body.contains(ifrWrap)) document.body.removeChild(ifrWrap);
                showError(overlay, 'Payment timed out. If money was deducted, contact us at info@hosu.or.ug.');
                return;
            }
            fetch('payment.php?action=check_mobile&tracking_id=' + encodeURIComponent(trackingId) + '&payment_id=' + payId)
                .then(function(r){ return r.json(); })
                .then(function(result) {
                    if (result.status === 'completed') {
                        clearInterval(_dfpIfrTimer); pSt.pollActive = false;
                        window.removeEventListener('message', _dfpMsgHandler);
                        if (document.body.contains(ifrWrap)) document.body.removeChild(ifrWrap);
                        overlay.querySelector('.dfp-proc-spinner').style.display = 'none';
                        overlay.querySelector('.dfp-proc-countdown').style.display = 'none';
                        var msg = overlay.querySelector('.dfp-proc-msg');
                        var sub = overlay.querySelector('.dfp-proc-sub');
                        var btn = overlay.querySelector('.dfp-proc-close');
                        msg.textContent = '\u2705 Payment confirmed!';
                        msg.style.color = '#27ae60';
                        var tok = result.receipt_token || receiptToken;
                        sub.innerHTML = 'Thank you! A receipt has been sent to your email.'
                            + (tok ? '<br><br><a href="receipt.php?token=' + encodeURIComponent(tok)
                              + '" style="display:inline-block;background:#0d4593;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;">\uD83D\uDCC4 View Receipt</a>' : '');
                        btn.textContent = 'Close'; btn.style.display = '';
                        btn.onclick = function () { overlay.style.display = 'none'; closePopup(popup); };
                    } else if (result.status === 'failed') {
                        clearInterval(_dfpIfrTimer); pSt.pollActive = false;
                        window.removeEventListener('message', _dfpMsgHandler);
                        if (document.body.contains(ifrWrap)) document.body.removeChild(ifrWrap);
                        showError(overlay, result.message || 'Payment was declined. Please try again.');
                    }
                }).catch(function () { /* network hiccup — continue polling */ });
        }, 8000);
        // Instant completion via postMessage from pesapal_callback.php when inside iframe
        var _dfpMsgHandler = function(event) {
            if (!event || !event.data || event.data.type !== 'hosu_payment') return;
            window.removeEventListener('message', _dfpMsgHandler);
            clearInterval(_dfpIfrTimer);
            pSt.pollActive = false;
            if (document.body.contains(ifrWrap)) document.body.removeChild(ifrWrap);
            if (event.data.status === 'success') {
                overlay.querySelector('.dfp-proc-spinner').style.display = 'none';
                overlay.querySelector('.dfp-proc-countdown').style.display = 'none';
                var pmMsg = overlay.querySelector('.dfp-proc-msg');
                var pmSub = overlay.querySelector('.dfp-proc-sub');
                var pmBtn = overlay.querySelector('.dfp-proc-close');
                pmMsg.textContent = '\u2705 Payment confirmed!';
                pmMsg.style.color = '#27ae60';
                var pmTok = (event.data.receiptToken) || receiptToken;
                pmSub.innerHTML = 'Thank you! A receipt has been sent to your email.'
                    + (pmTok ? '<br><br><a href="receipt.php?token=' + encodeURIComponent(pmTok)
                      + '" style="display:inline-block;background:#0d4593;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;">\uD83D\uDCC4 View Receipt</a>' : '');
                pmBtn.textContent = 'Close'; pmBtn.style.display = '';
                pmBtn.onclick = function () { overlay.style.display = 'none'; closePopup(popup); };
            } else {
                showError(overlay, (event.data && event.data.message) || 'Payment was not completed. Please try again.');
            }
        };
        window.addEventListener('message', _dfpMsgHandler);
        ifrWrap.querySelector('#_dfpIfrClose').addEventListener('click', function () {
            pSt.pollActive = false; clearInterval(_dfpIfrTimer);
            window.removeEventListener('message', _dfpMsgHandler);
            if (document.body.contains(ifrWrap)) document.body.removeChild(ifrWrap);
            showError(overlay, 'Payment cancelled. Please try again if needed.');
        });
    }

    /** Main payment submission */
    async function submitPayment(popup) {
        var st = popup._dfpState;
        var nameEl = popup.querySelector('.dfp-name');
        var emailEl = popup.querySelector('.dfp-email');
        var amountEl = popup.querySelector('.dfp-amount');
        var phoneWrap = popup.querySelector('.intl-phone-wrap');
        var phoneApi = phoneWrap ? phoneWrap._intlPhone : null;
        var phone = phoneApi ? phoneApi.getDigitsOnly() : popup.querySelector('.dfp-phone').value.trim().replace(/\D/g, '');
        var name = nameEl.value.trim();
        var email = emailEl.value.trim();
        var amount = parseInt(amountEl.value);

        if (!name) { alert('Please enter your full name.'); return; }
        if (phoneApi ? !phoneApi.isValid() : (phone.length < 7 || phone.length > 15)) { alert('Please enter a valid phone number.'); return; }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('Please enter a valid email address.'); return; }
        if (!amount || amount < 1000) { alert('Please enter a valid amount (minimum 1,000 UGX).'); return; }
        if (!st.method) { alert('Please select a payment method.'); return; }

        var overlay = popup.querySelector('.dfp-process-overlay');
        var spinner = overlay.querySelector('.dfp-proc-spinner');
        var msgEl = overlay.querySelector('.dfp-proc-msg');
        var subEl = overlay.querySelector('.dfp-proc-sub');
        var countdownEl = overlay.querySelector('.dfp-proc-countdown');
        var closeBtnEl = overlay.querySelector('.dfp-proc-close');

        // Bank / Bank-to-Bank — route through PesaPal hosted page
        if (st.method === 'bank' || st.method === 'banktobank') {
            overlay.style.display = 'flex';
            spinner.style.display = '';
            closeBtnEl.style.display = 'none';
            countdownEl.style.display = 'none';
            setStep(overlay, 0);
            msgEl.textContent = 'Preparing donation\u2026';
            msgEl.style.color = '';
            subEl.textContent = '';
            st.receiptToken = null;
            st.paymentId = null;

            try {
                var bPreFd = new FormData();
                bPreFd.append('fullName', name);
                bPreFd.append('email', email);
                bPreFd.append('phone', phone);
                bPreFd.append('profession', 'Donor');
                bPreFd.append('institution', '');
                bPreFd.append('paymentMethod', 'Bank Transfer');
                bPreFd.append('paymentType', 'donation');
                bPreFd.append('membershipPeriod', '');
                bPreFd.append('amount', amount);
                bPreFd.append('transactionId', '');
                bPreFd.append('transactionRef', '');
                var bPreRes = await fetch('api.php?action=pre_register', { method: 'POST', body: bPreFd }).then(function(r){ return r.json(); });
                if (bPreRes.success) {
                    st.receiptToken = bPreRes.receipt_token;
                    st.paymentId    = bPreRes.payment_id;
                } else {
                    showError(overlay, bPreRes.error || 'Registration failed.');
                    return;
                }
            } catch(e) { showError(overlay, 'Connection error.'); return; }

            setStep(overlay, 1);
            msgEl.textContent = 'Connecting to payment gateway\u2026';
            try {
                var bPayFd = new FormData();
                bPayFd.append('payment_id',    st.paymentId || 0);
                bPayFd.append('receipt_token', st.receiptToken || '');
                bPayFd.append('amount',        amount);
                bPayFd.append('email',         email);
                bPayFd.append('name',          name);
                bPayFd.append('phone',         phone);
                bPayFd.append('type',          'donation');
                var bPayRes = await fetch('payment.php?action=init_pesapal', { method: 'POST', body: bPayFd }).then(function(r){ return r.json(); });
                if (bPayRes.error) { showError(overlay, bPayRes.error); return; }
                if (bPayRes.redirect_url) {
                    setStep(overlay, 2);
                    msgEl.textContent = '\uD83C\uDFE6 Complete payment below\u2026';
                    subEl.textContent = '';
                    spinner.style.display = 'none';
                    closeBtnEl.style.display = 'none';
                    dfpShowPesapalIframe(
                        bPayRes.redirect_url, phone, amount,
                        bPayRes.tracking_id || '', st.paymentId || 0, st.receiptToken || '',
                        overlay, popup
                    );
                } else { showError(overlay, 'Could not get payment link. Please try again.'); }
            } catch(e) { showError(overlay, 'Network error.'); }
            return;
        }
        // Visa — PesaPal hosted redirect
        if (st.method === 'visa') {
            overlay.style.display = 'flex';
            spinner.style.display = '';
            closeBtnEl.style.display = 'none';
            countdownEl.style.display = 'none';
            setStep(overlay, 0);
            msgEl.textContent = 'Preparing donation…';
            msgEl.style.color = '';
            subEl.textContent = '';
            st.receiptToken = null;
            st.paymentId = null;

            try {
                var vPreFd = new FormData();
                vPreFd.append('fullName', name);
                vPreFd.append('email', email);
                vPreFd.append('phone', phone);
                vPreFd.append('profession', 'Donor');
                vPreFd.append('institution', '');
                vPreFd.append('paymentMethod', 'Visa/Mastercard');
                vPreFd.append('paymentType', 'donation');
                vPreFd.append('membershipPeriod', '');
                vPreFd.append('amount', amount);
                vPreFd.append('transactionId', '');
                vPreFd.append('transactionRef', '');

                var vPreRes = await fetch('api.php?action=pre_register', { method: 'POST', body: vPreFd }).then(function(r){ return r.json(); });
                if (vPreRes.success) {
                    st.receiptToken = vPreRes.receipt_token;
                    st.paymentId    = vPreRes.payment_id;
                } else {
                    showError(overlay, vPreRes.error || 'Registration failed.');
                    return;
                }
            } catch(e) { showError(overlay, 'Connection error.'); return; }

            setStep(overlay, 1);
            msgEl.textContent = 'Connecting to payment gateway…';
            try {
                var vPayFd = new FormData();
                vPayFd.append('payment_id',    st.paymentId || 0);
                vPayFd.append('receipt_token', st.receiptToken || '');
                vPayFd.append('amount',        amount);
                vPayFd.append('email',         email);
                vPayFd.append('name',          name);
                vPayFd.append('phone',         phone);
                vPayFd.append('type',          'donation');

                var vPayRes = await fetch('payment.php?action=init_pesapal', { method: 'POST', body: vPayFd }).then(function(r){ return r.json(); });
                if (vPayRes.error) { showError(overlay, vPayRes.error); return; }
                if (vPayRes.redirect_url) {
                    setStep(overlay, 2);
                    msgEl.textContent = '\uD83D\uDCB3 Complete payment below\u2026';
                    subEl.textContent = '';
                    spinner.style.display = 'none';
                    closeBtnEl.style.display = 'none';
                    dfpShowPesapalIframe(
                        vPayRes.redirect_url, phone, amount,
                        vPayRes.tracking_id || '', st.paymentId || 0, st.receiptToken || '',
                        overlay, popup
                    );
                } else { showError(overlay, 'Could not get payment link. Please try again.'); }
            } catch(e) { showError(overlay, 'Network error.'); }
            return;
        }

        // ── MTN or Airtel — PesaPal direct USSD push ──
        overlay.style.display = 'flex';
        spinner.style.display = '';
        closeBtnEl.style.display = 'none';
        countdownEl.style.display = 'none';
        setStep(overlay, 0);
        msgEl.textContent = 'Preparing your donation\u2026';
        msgEl.style.color = '';
        subEl.textContent = '';
        st.receiptToken = null;
        st.paymentId = null;
        st.txnRef = null;
        st.txnId = null;

        var methodLabel  = st.method === 'mtn' ? 'MTN Mobile Money' : 'Airtel Money';
        var dfpChannel   = st.method === 'mtn' ? 'UGMTNMOMODIR' : 'UGAIRTELMODIR';

        // Step 1: Pre-register
        try {
            var preFd = new FormData();
            preFd.append('fullName', name);
            preFd.append('email', email);
            preFd.append('phone', phone);
            preFd.append('profession', 'Donor');
            preFd.append('institution', '');
            preFd.append('paymentMethod', methodLabel);
            preFd.append('paymentType', 'donation');
            preFd.append('membershipPeriod', '');
            preFd.append('amount', amount);
            preFd.append('transactionId', '');
            preFd.append('transactionRef', '');

            var preRes = await fetch('api.php?action=pre_register', { method: 'POST', body: preFd }).then(function (r) { return r.json(); });
            if (preRes.success) {
                st.receiptToken = preRes.receipt_token;
                st.paymentId = preRes.payment_id;
            } else {
                showError(overlay, preRes.error || 'Registration failed.');
                return;
            }
        } catch (e) {
            showError(overlay, 'Connection error. Please check your network.');
            return;
        }

        // Step 2: Trigger USSD push via PesaPal pay_mobile
        setStep(overlay, 1);
        msgEl.textContent = 'Sending prompt to your ' + (st.method === 'mtn' ? 'MTN' : 'Airtel') + ' phone…';
        subEl.textContent = 'Please wait a moment…';

        try {
            var payFd = new FormData();
            payFd.append('phone',         phone);
            payFd.append('amount',        amount);
            payFd.append('email',         email);
            payFd.append('name',          name);
            payFd.append('payment_id',    st.paymentId || 0);
            payFd.append('receipt_token', st.receiptToken || '');
            payFd.append('channel',       dfpChannel);
            payFd.append('type',          'donation');

            var payRes = await fetch('payment.php?action=pay_mobile', { method: 'POST', body: payFd }).then(function (r) { return r.json(); });

            if (payRes.error) { showError(overlay, payRes.error); return; }

            // Fallback: PesaPal wants hosted page — embed inline, no full-page redirect
            if (payRes.redirect_url) {
                setStep(overlay, 2);
                msgEl.textContent = '\uD83D\uDCF1 Complete payment below\u2026';
                subEl.textContent = 'Enter your PIN when prompted on your phone.';
                spinner.style.display = 'none';
                closeBtnEl.style.display = 'none';
                dfpShowPesapalIframe(
                    payRes.redirect_url, phone, amount,
                    payRes.tracking_id || st.txnRef || '',
                    st.paymentId || 0, st.receiptToken || '',
                    overlay, popup
                );
                return;
            }

            st.txnRef = payRes.tracking_id || null;
            setStep(overlay, 2);
            msgEl.textContent = '📱 Check your ' + (st.method === 'mtn' ? 'MTN' : 'Airtel') + ' phone!';
            subEl.innerHTML = 'Enter your mobile money PIN to approve <strong>UGX ' + amount.toLocaleString() + '</strong>.';
            st.pollActive = true;

            // Step 3: Poll check_mobile
            var MAX_POLLS = 15;
            var INTERVAL  = 8000;

            for (var i = 0; i < MAX_POLLS; i++) {
                if (!st.pollActive) return;
                var secsLeft = (MAX_POLLS - i) * (INTERVAL / 1000);
                countdownEl.style.display = '';
                countdownEl.textContent = 'Time remaining: ' + Math.floor(secsLeft / 60) + 'm ' + (secsLeft % 60) + 's';

                await new Promise(function (r) { setTimeout(r, INTERVAL); });
                if (!st.pollActive) return;

                try {
                    var params = 'action=check_mobile&tracking_id=' + encodeURIComponent(st.txnRef || '')
                        + '&payment_id=' + (st.paymentId || 0);
                    var result = await fetch('payment.php?' + params).then(function (r) { return r.json(); });

                    if (result.status === 'completed') {
                        st.pollActive = false;
                        countdownEl.style.display = 'none';
                        spinner.style.display = 'none';
                        msgEl.textContent = '✅ Payment confirmed!';
                        msgEl.style.color = '#27ae60';
                        subEl.innerHTML = 'Thank you for your donation! A receipt has been sent to your email.'
                            + (result.receipt_token || st.receiptToken
                                ? '<br><br><a href="receipt.php?token=' + encodeURIComponent(result.receipt_token || st.receiptToken)
                                  + '" style="display:inline-block;background:#0d4593;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;">📄 View Receipt</a>'
                                : '');
                        closeBtnEl.textContent = 'Close';
                        closeBtnEl.style.display = '';
                        closeBtnEl.onclick = function(){ overlay.style.display = 'none'; };
                        return;
                    } else if (result.status === 'failed') {
                        st.pollActive = false;
                        showError(overlay, result.message || 'Payment was declined. Please try again.');
                        return;
                    }
                } catch (e) { /* continue polling */ }
            }
            st.pollActive = false;
            showError(overlay, 'Payment timed out (2 minutes). The request was cancelled.',
                'If money was deducted, contact us at <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a> or '
                + '<a href="https://wa.me/256709752107" target="_blank" rel="noopener" style="color:#0d4593;font-weight:700;">WhatsApp +256 709 752107</a>.');
        } catch (e) {
            showError(overlay, 'Network error. Please check your connection.');
        }
    }

    /* ═════════════════════════════════════════════════════════
       GLOBAL EVENT WIRING
       ═════════════════════════════════════════════════════════ */

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

    /* ── Public API ── */
    window._dfpToggle = function () {
        var popup = floatWrap.querySelector('.donate-float-popup');
        if (popup.classList.contains('active')) closeAllPopups();
        else openPopup(popup);
    };
    window._dfpClose = function () { closeAllPopups(); };
    window.closeDonatePopups = function () { closeAllPopups(); };
})();
