/* ============================================================
   FLOATING DONATE BUTTON — donate-float.js
   SINGLE SOURCE for ALL "Support Us" / donate functionality.
   Include on ALL pages alongside donate-float.css.
   ============================================================ */
(function () {
    'use strict';

    /* ── AUTO-INJECT CSRF TOKEN INTO ALL POST REQUESTS ── */
    if (!window._hosuFetchPatched) {
        var _origFetch = window.fetch;
        window.fetch = function(url, opts){
            if(opts && opts.method && opts.method.toUpperCase()==='POST' && opts.body instanceof FormData){
                if(window._hosuCsrfToken && !opts.body.has('csrf_token')){
                    opts.body.append('csrf_token', window._hosuCsrfToken);
                }
            }
            return _origFetch.apply(this, arguments);
        };
        window._hosuFetchPatched = true;
    }

    /* ── POPUP HTML (form + processing overlay) ── */
    var POPUP_HTML =
        '<button class="dfp-close" aria-label="Close">&times;</button>' +
        '<div class="dfp-form-area">' +
            '<div class="dfp-modal-header"><h3>Support HOSU</h3></div>' +
            '<p class="dfp-subtitle">Your donation transforms cancer care in Uganda.</p>' +
            '<div class="dfp-form-grid">' +
                '<div class="dfp-field dfp-fg-full">' +
                    '<label class="dfp-label">Full Name</label>' +
                    '<input class="dfp-input dfp-name" placeholder="Full name" required>' +
                '</div>' +
                '<div class="dfp-field dfp-fg-full">' +
                    '<label class="dfp-label">Phone Number</label>' +
                    '<input class="dfp-input dfp-phone" type="tel" required>' +
                '</div>' +
                '<div class="dfp-field dfp-fg-full">' +
                    '<label class="dfp-label">Email <span style="color:#e63946">*</span></label>' +
                    '<input class="dfp-input dfp-email" type="email" placeholder="you@example.com" required>' +
                '</div>' +
                '<div class="dfp-invalid-phone dfp-fg-full" style="display:none">Invalid phone number.</div>' +
                '<div class="dfp-invalid-email dfp-fg-full" style="display:none;color:#e63946;font-size:0.78rem;margin-top:-6px;">Valid email is required.</div>' +
            '</div>' +
            '<div class="dfp-field">' +
                '<label class="dfp-label">Amount (UGX)</label>' +
                '<input class="dfp-input dfp-amount" type="number" min="1000" placeholder="Enter amount">' +
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
                    '<i class="fas fa-university"></i> Bank' +
                '</button>' +
                '<button type="button" class="dfp-pay-chip" data-method="banktobank">' +
                    '<i class="fas fa-exchange-alt"></i> Bank to Bank' +
                '</button>' +
                '<button type="button" class="dfp-pay-chip" data-method="visa">' +
                    '<i class="fab fa-cc-visa"></i> Visa' +
                '</button>' +
                '<button type="button" class="dfp-pay-chip dfp-mtn-chip" data-method="mtn">' +
                    '<img src="img/mtn.png" alt="MTN" class="dfp-chip-img"> MTN' +
                '</button>' +
                '<button type="button" class="dfp-pay-chip dfp-airtel-chip" data-method="airtel">' +
                    '<img src="img/airtel.png" alt="Airtel" class="dfp-chip-img"> Airtel' +
                '</button>' +
            '</div>' +
            '<div class="dfp-bank-info" style="display:none">' +
                '<p><strong>Account:</strong> HOSU Limited</p>' +
                '<p><strong>No:</strong> 9030025235214</p>' +
                '<p><strong>Bank:</strong> Stanbic Bank, Mulago</p>' +
            '</div>' +
            '<button class="dfp-submit" disabled>Process Payment</button>' +
        '</div>' +
        /* Processing overlay — sits on top of form */
        '<div class="dfp-process-overlay" style="display:none">' +
            '<div class="dfp-modal-header"><h3 class="dfp-proc-title">Processing Payment</h3></div>' +
            '<div class="dfp-steps">' +
                '<div class="dfp-step"><div class="dfp-step-dot active">1</div><span>Register</span></div>' +
                '<div class="dfp-step-line"></div>' +
                '<div class="dfp-step"><div class="dfp-step-dot">2</div><span>Authorize</span></div>' +
                '<div class="dfp-step-line"></div>' +
                '<div class="dfp-step"><div class="dfp-step-dot">3</div><span>Receipt</span></div>' +
            '</div>' +
            '<div class="dfp-spinner"></div>' +
            '<p class="dfp-process-msg">Processing, please wait...</p>' +
            '<p class="dfp-process-sub"></p>' +
            '<div class="dfp-countdown" style="display:none"></div>' +
            '<button class="dfp-proc-close" style="display:none">Close</button>' +
        '</div>';

    /* ── INJECT FLOATING BUTTON ── */
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

    /* ── STATE ── */
    var _selectedMethod = null;
    var _receiptToken = null;
    var _paymentId = null;
    var _txnRef = null;
    var _txnId = null;
    var _pollActive = false;
    var _activePopup = null; // Currently open popup element

    /* ── HELPERS ── */

    /** Get elements from the currently active popup */
    function $(sel) { return _activePopup ? _activePopup.querySelector(sel) : null; }
    function $$(sel) { return _activePopup ? _activePopup.querySelectorAll(sel) : []; }

    /** Smart positioning — pick best direction based on viewport */
    function positionPopup(popup) {
        popup.classList.remove('pos-top', 'pos-bottom', 'pos-left');
        var wrap = popup.closest('.donate-trigger-wrap');
        if (!wrap) return;
        var rect = wrap.getBoundingClientRect();
        var vh = window.innerHeight;
        var ph = 450;
        var pw = 320;

        if (rect.top >= ph + 20) popup.classList.add('pos-top');
        else if (vh - rect.bottom >= ph + 20) popup.classList.add('pos-bottom');
        else if (rect.left >= pw + 20) popup.classList.add('pos-left');
        else if (vh - rect.bottom >= rect.top) popup.classList.add('pos-bottom');
        else popup.classList.add('pos-top');
    }

    /** Initialize a popup with HTML and wire all events */
    function initPopup(popup) {
        popup.innerHTML = POPUP_HTML;
        _activePopup = popup;
        _selectedMethod = null;
        _receiptToken = null;
        _paymentId = null;
        _txnRef = null;
        _txnId = null;
        _pollActive = false;

        // Upgrade phone to intl format
        var phoneEl = popup.querySelector('.dfp-phone');
        if (window.intlPhone && phoneEl) window.intlPhone.upgrade(phoneEl);

        // Close button
        popup.querySelector('.dfp-close').addEventListener('click', function(e) {
            e.stopPropagation();
            closeAllPopups();
        });

        // Amount preset buttons
        popup.querySelectorAll('.dfp-amt').forEach(function(btn) {
            btn.addEventListener('click', function() {
                popup.querySelectorAll('.dfp-amt').forEach(function(b) { b.classList.remove('selected'); });
                btn.classList.add('selected');
                popup.querySelector('.dfp-amount').value = btn.getAttribute('data-amt');
                validateForm();
            });
        });

        // Payment method chips
        popup.querySelectorAll('.dfp-pay-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                popup.querySelectorAll('.dfp-pay-chip').forEach(function(c) { c.classList.remove('selected'); });
                chip.classList.add('selected');
                _selectedMethod = chip.getAttribute('data-method');
                var bankInfo = popup.querySelector('.dfp-bank-info');
                if (bankInfo) bankInfo.style.display = (_selectedMethod === 'bank' || _selectedMethod === 'banktobank') ? 'block' : 'none';
                validateForm();
            });
        });

        // Real-time form validation
        popup.querySelectorAll('.dfp-name, .dfp-amount, .dfp-email').forEach(function(el) {
            el.addEventListener('input', validateForm);
        });
        var phoneWrap = popup.querySelector('.intl-phone-wrap');
        if (phoneWrap && phoneWrap._intlPhone) {
            phoneWrap._intlPhone.input.addEventListener('input', validateForm);
            phoneWrap._intlPhone.select.addEventListener('change', validateForm);
        } else if (phoneEl) {
            phoneEl.addEventListener('input', validateForm);
        }

        // Submit button
        popup.querySelector('.dfp-submit').addEventListener('click', function() { submitPayment(); });

        // Process close button
        popup.querySelector('.dfp-proc-close').addEventListener('click', function() {
            resetToForm();
        });

        // Stop propagation inside popup
        popup.addEventListener('click', function(e) { e.stopPropagation(); });
    }

    /** Validate form and enable/disable submit */
    function validateForm() {
        if (!_activePopup) return;
        var name = $('.dfp-name');
        var phone = $('.dfp-phone');
        var email = $('.dfp-email');
        var amount = $('.dfp-amount');

        var nameOk = name && name.value.trim().length > 0;

        var phoneWrap = _activePopup.querySelector('.intl-phone-wrap');
        var phoneApi = phoneWrap ? phoneWrap._intlPhone : null;
        var phoneInput = phoneApi ? phoneApi.input : phone;
        var phoneOk = phoneApi ? phoneApi.isValid() : (phoneInput && phoneInput.value.trim().replace(/\D/g, '').length >= 7);

        var emailVal = email ? email.value.trim() : '';
        var emailOk = emailVal.length > 0 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal);

        var amountOk = amount && amount.value && Number(amount.value) >= 1000;
        var methodOk = !!_selectedMethod;

        // Phone error
        var phoneErr = $('.dfp-invalid-phone');
        if (phoneErr) {
            var hasVal = phoneApi ? phoneApi.input.value.trim().length > 0 : (phone && phone.value.trim().length > 0);
            phoneErr.style.display = (hasVal && !phoneOk) ? 'block' : 'none';
            phoneErr.textContent = phoneOk ? '' : 'Invalid phone number for selected country.';
        }
        // Email error
        var emailErr = $('.dfp-invalid-email');
        if (emailErr) {
            emailErr.style.display = (email && email.value.trim().length > 0 && !emailOk) ? 'block' : 'none';
        }

        var submitBtn = $('.dfp-submit');
        if (submitBtn) submitBtn.disabled = !(nameOk && phoneOk && emailOk && amountOk && methodOk);
    }

    /* ── OPEN / CLOSE ── */

    function closeAllPopups() {
        _pollActive = false;
        document.querySelectorAll('.donate-float-popup.active').forEach(function(p) {
            p.classList.remove('active');
        });
        _activePopup = null;
        _selectedMethod = null;
    }

    function openPopup(popup) {
        closeAllPopups();
        // Close login popup if exists
        if (typeof closeLoginPopup === 'function') closeLoginPopup();
        if (typeof closeAllFloatPopups === 'function') closeAllFloatPopups();

        initPopup(popup);
        positionPopup(popup);
        popup.classList.add('active');
        popup.scrollTop = 0;

        setTimeout(function() {
            popup.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 50);
    }

    // Expose globally so index.html CTA and other triggers can use it
    window._dfpToggle = function() {
        var popup = floatWrap.querySelector('.donate-float-popup');
        if (popup && popup.classList.contains('active')) {
            closeAllPopups();
        } else {
            openPopup(popup);
        }
    };
    window._dfpClose = function() { closeAllPopups(); };
    window._dfpOpenPopup = function(popup) { openPopup(popup); };
    // Alias for backward compat
    window.closeDonatePopups = function() { closeAllPopups(); };

    // Floating button click
    floatWrap.querySelector('.donate-button').addEventListener('click', function(e) {
        e.stopPropagation();
        window._dfpToggle();
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.donate-float-popup') && !e.target.closest('.donate-button') && !e.target.closest('.donate-trigger')) {
            closeAllPopups();
        }
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAllPopups();
    });

    /* ── PROCESSING OVERLAY CONTROL ── */

    function showOverlay() {
        var overlay = $('.dfp-process-overlay');
        if (overlay) overlay.style.display = 'flex';
    }

    function setStep(n) {
        var dots = $$('.dfp-step-dot');
        var lines = $$('.dfp-step-line');
        dots.forEach(function(dot, i) {
            dot.className = 'dfp-step-dot' + (i < n ? ' done' : '') + (i === n ? ' active' : '');
        });
        lines.forEach(function(line, i) {
            line.className = 'dfp-step-line' + (i < n ? ' done' : '') + (i === n ? ' active' : '');
        });
    }

    function showError(errMsg) {
        _pollActive = false;
        var spinner = $('.dfp-spinner');
        var msg = $('.dfp-process-msg');
        var sub = $('.dfp-process-sub');
        var countdown = $('.dfp-countdown');
        var closeBtn = $('.dfp-proc-close');
        if (spinner) spinner.style.display = 'none';
        if (countdown) countdown.style.display = 'none';
        if (msg) { msg.textContent = '\u274c ' + errMsg; msg.style.color = '#e63946'; }
        if (sub) sub.textContent = '';
        if (closeBtn) closeBtn.style.display = '';
    }

    function showSuccess() {
        _pollActive = false;
        setStep(3);
        var spinner = $('.dfp-spinner');
        var msg = $('.dfp-process-msg');
        var sub = $('.dfp-process-sub');
        var countdown = $('.dfp-countdown');
        var closeBtn = $('.dfp-proc-close');
        if (spinner) spinner.style.display = 'none';
        if (countdown) countdown.style.display = 'none';

        // Call confirm_payment backend
        if (_receiptToken && _paymentId) {
            try {
                var fd = new FormData();
                fd.append('payment_id', _paymentId);
                fd.append('receipt_token', _receiptToken);
                fetch('api.php?action=confirm_payment', { method: 'POST', body: fd });
            } catch(e) {}
            if (msg) { msg.textContent = '\u2705 Payment confirmed!'; msg.style.color = '#27ae60'; }
            if (sub) sub.innerHTML =
                'A receipt has been sent to your email.<br><br>' +
                '<a href="receipt.php?token=' + encodeURIComponent(_receiptToken) + '" ' +
                'style="display:inline-block;background:#0d4593;color:#fff;padding:8px 20px;' +
                'border-radius:8px;text-decoration:none;font-weight:600;font-size:0.78rem;">' +
                '\uD83D\uDCC4 View Receipt</a>';
        } else {
            if (msg) { msg.textContent = '\u2705 Thank you for your donation!'; msg.style.color = '#27ae60'; }
        }
        if (closeBtn) { closeBtn.textContent = 'Done'; closeBtn.style.display = ''; }
    }

    function resetToForm() {
        _pollActive = false;
        var overlay = $('.dfp-process-overlay');
        var spinner = $('.dfp-spinner');
        var msg = $('.dfp-process-msg');
        var sub = $('.dfp-process-sub');
        var countdown = $('.dfp-countdown');
        var closeBtn = $('.dfp-proc-close');
        if (overlay) overlay.style.display = 'none';
        if (spinner) spinner.style.display = '';
        if (msg) { msg.textContent = 'Processing, please wait...'; msg.style.color = ''; }
        if (sub) { sub.textContent = ''; sub.innerHTML = ''; }
        if (countdown) countdown.style.display = 'none';
        if (closeBtn) closeBtn.style.display = 'none';
    }

    /* ── SUBMIT — UNIFIED PAYMENT LOGIC ── */

    async function submitPayment() {
        if (!_activePopup) return;

        var nameEl = $('.dfp-name');
        var emailEl = $('.dfp-email');
        var phoneEl = $('.dfp-phone');
        var amountEl = $('.dfp-amount');

        var name = nameEl ? nameEl.value.trim() : '';
        var email = emailEl ? emailEl.value.trim() : '';
        var phoneWrap = _activePopup.querySelector('.intl-phone-wrap');
        var phoneApi = phoneWrap ? phoneWrap._intlPhone : null;
        var phone = phoneApi ? phoneApi.getDigitsOnly() : (phoneEl ? phoneEl.value.trim().replace(/\D/g, '') : '');
        var amount = amountEl ? parseInt(amountEl.value) : 0;

        // Validate
        if (!name) { alert('Please enter your full name.'); return; }
        if (phoneApi ? !phoneApi.isValid() : (phone.length < 7 || phone.length > 15)) {
            alert('Please enter a valid phone number with country code.'); return;
        }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert('Please enter a valid email address for your receipt.'); return;
        }
        if (!amount || amount < 1000) { alert('Please enter an amount (min 1,000 UGX).'); return; }
        if (!_selectedMethod) { alert('Please select a payment method.'); return; }

        // ── Bank / Bank-to-Bank: show info ──
        if (_selectedMethod === 'bank' || _selectedMethod === 'banktobank') {
            showOverlay();
            setStep(1);
            var spinner = $('.dfp-spinner');
            var msg = $('.dfp-process-msg');
            var sub = $('.dfp-process-sub');
            var closeBtn = $('.dfp-proc-close');
            if (spinner) spinner.style.display = 'none';
            if (msg) msg.textContent = 'Transfer UGX ' + amount.toLocaleString() + ' to:';
            if (sub) sub.innerHTML = '<strong>HOSU Limited</strong><br>A/C: 9030025235214<br>Stanbic Bank, Mulago Branch<br><br>Contact us after transferring.';
            if (closeBtn) closeBtn.style.display = '';
            return;
        }

        // ── Visa: coming soon ──
        if (_selectedMethod === 'visa') {
            showOverlay();
            setStep(1);
            var spinner = $('.dfp-spinner');
            var msg = $('.dfp-process-msg');
            var sub = $('.dfp-process-sub');
            var closeBtn = $('.dfp-proc-close');
            if (spinner) spinner.style.display = 'none';
            if (msg) msg.textContent = 'Visa card payments coming soon';
            if (sub) sub.innerHTML = 'Please use <strong>MTN Mobile Money</strong> or <strong>Airtel Money</strong> for instant payment.';
            if (closeBtn) closeBtn.style.display = '';
            return;
        }

        // ── MTN or Airtel: real payment flow ──
        var methodLabel = _selectedMethod === 'mtn' ? 'MTN Mobile Money' : 'Airtel Money';

        showOverlay();
        setStep(0);
        var spinner = $('.dfp-spinner');
        var msg = $('.dfp-process-msg');
        var sub = $('.dfp-process-sub');
        var countdown = $('.dfp-countdown');
        var closeBtn = $('.dfp-proc-close');
        if (spinner) spinner.style.display = '';
        if (msg) { msg.textContent = 'Preparing your donation\u2026'; msg.style.color = ''; }
        if (sub) { sub.textContent = ''; sub.innerHTML = ''; }
        if (countdown) countdown.style.display = 'none';
        if (closeBtn) closeBtn.style.display = 'none';
        _receiptToken = null;
        _paymentId = null;
        _txnRef = null;
        _txnId = null;

        // STEP 1: Pre-register donation
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

            var preRes = await fetch('api.php?action=pre_register', { method: 'POST', body: preFd }).then(function(r) { return r.json(); });
            if (preRes.success) {
                _receiptToken = preRes.receipt_token;
                _paymentId = preRes.payment_id;
            } else {
                showError(preRes.error || 'Registration failed. Please try again.');
                return;
            }
        } catch(e) {
            showError('Connection error. Please check your network.');
            return;
        }

        // STEP 2: Send payment request to gateway
        setStep(1);
        if (msg) msg.textContent = 'Sending payment request to ' + (_selectedMethod === 'mtn' ? 'MTN' : 'Airtel') + '\u2026';
        if (sub) sub.textContent = 'A payment prompt will appear on your phone';

        try {
            var payFd = new FormData();
            payFd.append('phone', phone);
            payFd.append('amount', amount);
            payFd.append('email', email);
            payFd.append('payment_id', _paymentId || 0);

            var payAction = _selectedMethod === 'mtn' ? 'pay_mtn' : 'pay_airtel';
            var payRes = await fetch('payment.php?action=' + payAction, { method: 'POST', body: payFd }).then(function(r) { return r.json(); });

            if (payRes.error) {
                showError(payRes.error);
                return;
            }

            _txnRef = payRes.txn_ref || null;
            _txnId = payRes.txn_id || null;

            if (payRes.status === 'completed') {
                showSuccess();
            } else if (payRes.status === 'pending') {
                // STEP 3: Poll for payment approval
                setStep(2);
                if (msg) msg.textContent = '\uD83D\uDCF1 Check your phone now!';
                if (sub) sub.textContent = payRes.message || 'Approve the payment on your phone';
                await pollForStatus();
            } else {
                showError(payRes.message || 'Payment failed. Please try again.');
            }
        } catch(e) {
            showError('Network error. Please check your connection.');
        }
    }

    /* ── POLL FOR PAYMENT STATUS ── */

    async function pollForStatus() {
        _pollActive = true;
        var pollAction = _selectedMethod === 'mtn' ? 'check_mtn' : 'check_airtel';
        var MAX_POLLS = _selectedMethod === 'mtn' ? 12 : 18;
        var INTERVAL = 10000;

        var msg = $('.dfp-process-msg');
        var sub = $('.dfp-process-sub');
        var countdown = $('.dfp-countdown');

        if (msg) msg.textContent = 'Waiting for your approval\u2026';
        if (sub) sub.textContent = _selectedMethod === 'mtn'
            ? 'Approve the MTN Mobile Money prompt on your phone'
            : 'Enter your Airtel Money PIN on your phone';

        for (var i = 0; i < MAX_POLLS; i++) {
            if (!_pollActive) return;
            var secsLeft = (MAX_POLLS - i) * (INTERVAL / 1000);
            if (countdown) {
                countdown.style.display = '';
                countdown.textContent = 'Time remaining: ' + Math.floor(secsLeft / 60) + 'm ' + (secsLeft % 60) + 's';
            }

            await new Promise(function(r) { setTimeout(r, INTERVAL); });
            if (!_pollActive) return;

            try {
                var params = 'action=' + pollAction;
                if (_selectedMethod === 'mtn') {
                    params += '&txn_ref=' + encodeURIComponent(_txnRef || '');
                    if (_txnId) params += '&txn_id=' + encodeURIComponent(_txnId);
                } else {
                    params += '&txn_id=' + encodeURIComponent(_txnId || _txnRef || '');
                }
                params += '&payment_id=' + (_paymentId || 0);

                var result = await fetch('payment.php?' + params).then(function(r) { return r.json(); });

                if (result.status === 'completed') {
                    _pollActive = false;
                    showSuccess();
                    return;
                } else if (result.status === 'failed' || result.status === 'expired') {
                    _pollActive = false;
                    showError(result.message || 'Payment was declined. Please try again.');
                    return;
                }
            } catch(e) { /* retry on network error */ }
        }
        _pollActive = false;
        showError('Payment timed out. If money was deducted, please contact HOSU support.');
    }

    /* ── WIRE UP CTA TRIGGERS ON ANY PAGE ──
       Any element with class "donate-trigger" inside a "donate-trigger-wrap"
       will open the floating popup when clicked. */
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.donate-trigger');
        if (!trigger) return;
        e.preventDefault();
        e.stopPropagation();
        window._dfpToggle();
    });

})();
