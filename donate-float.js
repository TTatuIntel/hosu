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
        '</div>' +
        '<div class="dfp-bank-info" style="display:none">' +
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
                if (bi) bi.style.display = (st.method === 'bank' || st.method === 'banktobank') ? 'block' : 'none';
                validate(popup);
            });
        });
        // Real-time validation
        ['.dfp-name', '.dfp-amount', '.dfp-email'].forEach(function (sel) {
            var el = popup.querySelector(sel);
            if (el) el.addEventListener('input', function () { validate(popup); });
        });
        var phoneWrap = popup.querySelector('.intl-phone-wrap');
        if (phoneWrap && phoneWrap._intlPhone) {
            phoneWrap._intlPhone.input.addEventListener('input', function () { validate(popup); });
            phoneWrap._intlPhone.select.addEventListener('change', function () { validate(popup); });
        } else {
            var pi = popup.querySelector('.dfp-phone');
            if (pi) pi.addEventListener('input', function () { validate(popup); });
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

        // Bank / Bank-to-Bank
        if (st.method === 'bank' || st.method === 'banktobank') {
            overlay.style.display = 'flex';
            spinner.style.display = 'none';
            countdownEl.style.display = 'none';
            setStep(overlay, 1);
            msgEl.textContent = 'Transfer UGX ' + amount.toLocaleString() + ' to:';
            msgEl.style.color = '';
            subEl.innerHTML = '<strong>HOSU Limited</strong><br>A/C: 9030025235214<br>Stanbic Bank, Mulago Branch<br><br>Provide your proof of payment on <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a> or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" style="color:#0d4593;font-weight:700;">WhatsApp +256 709 752107</a>.';
            closeBtnEl.textContent = 'Close';
            closeBtnEl.style.display = '';
            closeBtnEl.onclick = function () { overlay.style.display = 'none'; };
            return;
        }
        // Visa
        if (st.method === 'visa') {
            overlay.style.display = 'flex';
            spinner.style.display = 'none';
            countdownEl.style.display = 'none';
            setStep(overlay, 1);
            msgEl.textContent = 'Visa card payments coming soon';
            msgEl.style.color = '';
            subEl.innerHTML = 'Please use <strong>MTN Mobile Money</strong> or <strong>Airtel Money</strong> for instant payment.';
            closeBtnEl.textContent = 'Close';
            closeBtnEl.style.display = '';
            closeBtnEl.onclick = function () { overlay.style.display = 'none'; };
            return;
        }

        // ── MTN or Airtel — REAL payment flow ──
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

        var methodLabel = st.method === 'mtn' ? 'MTN Mobile Money' : 'Airtel Money';

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

        // Step 2: Send payment to phone
        var donationProofOfPaymentHtml = 'If you complete payment by mobile money, provide your proof of payment on <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a> or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" style="color:#0d4593;font-weight:700;">WhatsApp +256 709 752107</a>.';
        setStep(overlay, 1);
        msgEl.textContent = 'Sending payment request to ' + (st.method === 'mtn' ? 'MTN' : 'Airtel') + '…';
        subEl.innerHTML = 'A payment prompt will appear on your phone.<br><br>' + donationProofOfPaymentHtml;

        try {
            var payFd = new FormData();
            payFd.append('phone', phone);
            payFd.append('amount', amount);
            payFd.append('email', email);
            payFd.append('payment_id', st.paymentId || 0);

            var payAction = st.method === 'mtn' ? 'pay_mtn' : 'pay_airtel';
            var payRes = await fetch('payment.php?action=' + payAction, { method: 'POST', body: payFd }).then(function (r) { return r.json(); });

            if (payRes.error) { showError(overlay, payRes.error); return; }

            st.txnRef = payRes.txn_ref || null;
            st.txnId = payRes.txn_id || null;

            if (payRes.status === 'completed') {
                st.pollActive = false;
                showSuccess(overlay, popup);
            } else if (payRes.status === 'pending') {
                // Step 3: Poll for confirmation
                setStep(overlay, 2);
                msgEl.textContent = '\uD83D\uDCF1 Check your phone now!';
                subEl.innerHTML = (payRes.message || 'Approve the payment on your phone') + '<br><br>' + donationProofOfPaymentHtml;
                st.pollActive = true;

                var pollAction = st.method === 'mtn' ? 'check_mtn' : 'check_airtel';
                var pollKey = st.method === 'mtn' ? 'txn_ref' : 'txn_id';
                var pollVal = st.method === 'mtn' ? st.txnRef : (st.txnId || st.txnRef);
                var MAX_POLLS = st.method === 'mtn' ? 12 : 18;
                var INTERVAL = 10000;

                for (var i = 0; i < MAX_POLLS; i++) {
                    if (!st.pollActive) return;
                    var secsLeft = (MAX_POLLS - i) * (INTERVAL / 1000);
                    countdownEl.style.display = '';
                    countdownEl.textContent = 'Time remaining: ' + Math.floor(secsLeft / 60) + 'm ' + (secsLeft % 60) + 's';

                    await new Promise(function (r) { setTimeout(r, INTERVAL); });
                    if (!st.pollActive) return;

                    try {
                        var params = 'action=' + pollAction +
                            '&' + pollKey + '=' + encodeURIComponent(pollVal) +
                            '&payment_id=' + (st.paymentId || 0) +
                            (st.txnId ? '&txn_id=' + encodeURIComponent(st.txnId) : '');
                        var result = await fetch('payment.php?' + params).then(function (r) { return r.json(); });

                        if (result.status === 'completed') {
                            st.pollActive = false;
                            showSuccess(overlay, popup);
                            return;
                        } else if (result.status === 'failed' || result.status === 'expired') {
                            st.pollActive = false;
                            showError(overlay, result.message || 'Payment was declined. Please try again.');
                            return;
                        }
                    } catch (e) { /* continue polling */ }
                }
                st.pollActive = false;
                showError(overlay, 'Payment timed out.', 'If money was deducted, provide your proof of payment on <a href="mailto:info@hosu.or.ug" style="color:#0d4593;font-weight:700;">info@hosu.or.ug</a> or <a href="https://wa.me/256709752107" target="_blank" rel="noopener" style="color:#0d4593;font-weight:700;">WhatsApp +256 709 752107</a>.');
            } else {
                showError(overlay, payRes.message || 'Payment failed. Please try again.');
            }
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
