/* ============================================================
   SHARED LOGIN POPUP — shared-login.js
   Include on ALL pages alongside shared-login.css.
   Must be loaded AFTER shared-components.js (needs #loginPopup).
   ============================================================ */
(function () {
    'use strict';

    var _loginState = null;

    /* Escape HTML to prevent XSS when injecting user data via innerHTML */
    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    /* Expose login state globally for page-specific code (e.g. blog admin) */
    function _syncGlobalState() {
        window._hosuIsAdmin = !!(_loginState && _loginState.loggedIn && _loginState.user && _loginState.user.role === 'admin');
        window._hosuUser = (_loginState && _loginState.loggedIn) ? _loginState.user : null;
    }

    /* ── CSRF token management ── */
    window._hosuCsrfToken = '';

    /** Store CSRF token from server response */
    function _storeCsrf(data) {
        if (data && data.csrf_token) {
            window._hosuCsrfToken = data.csrf_token;
        }
    }

    /**
     * Append CSRF token to a FormData object before sending.
     * Usage: var fd = new FormData(); hosuAppendCsrf(fd); fetch(url, {method:'POST', body:fd});
     */
    window.hosuAppendCsrf = function (fd) {
        if (window._hosuCsrfToken) {
            fd.append('csrf_token', window._hosuCsrfToken);
        }
        return fd;
    };

    function renderLoginPopup(popup) {
        if (_loginState && _loginState.loggedIn) {
            var u = _loginState.user;
            var isAdmin = u.role === 'admin';
            popup.innerHTML =
                '<button class="lfp-close" onclick="this.closest(\'.login-float-popup\').classList.remove(\'active\')">&times;</button>'
                + '<div style="text-align:center;padding-bottom:0.5rem;">'
                + '<div style="width:42px;height:42px;border-radius:50%;background:var(--secondary-color);color:#fff;font-size:1.2rem;display:flex;align-items:center;justify-content:center;margin:0 auto 0.4rem;">'
                + escHtml(u.username.charAt(0).toUpperCase()) + '</div>'
                + '<div style="font-weight:700;font-size:0.9rem;color:var(--secondary-color);">' + escHtml(u.username) + '</div>'
                + '<div style="font-size:0.72rem;color:var(--text-light);text-transform:capitalize;">' + escHtml(u.role) + ' Account</div>'
                + '</div>'
                + (isAdmin ? '<a href="admin.html" style="display:block;margin:0.75rem 0 0.3rem;width:100%;text-align:center;padding:0.5rem;background:var(--secondary-color);color:#fff;border-radius:6px;font-weight:600;font-size:0.82rem;text-decoration:none;">&#9881; Admin Dashboard</a>' : '')
                + '<button onclick="doLogout()" style="width:100%;margin-top:0.4rem;padding:0.45rem;background:rgba(230,57,70,0.08);color:var(--primary-color);border:1px solid rgba(230,57,70,0.2);border-radius:6px;font-weight:600;font-size:0.82rem;cursor:pointer;">Sign Out</button>';
        } else {
            popup.innerHTML =
                '<button class="lfp-close" onclick="this.closest(\'.login-float-popup\').classList.remove(\'active\')">&times;</button>'
                + '<h3>&#128274; Member Portal</h3>'
                + '<p style="font-size:0.73rem;color:var(--text-light);margin-bottom:0.75rem;">Sign in to your HOSU account</p>'
                + '<div id="lfp-error" style="display:none;font-size:0.75rem;color:var(--primary-color);background:rgba(230,57,70,0.07);border-radius:5px;padding:0.35rem 0.6rem;margin-bottom:0.5rem;"></div>'
                + '<label for="lfp-identity" style="font-size:0.72rem;font-weight:600;color:var(--text-color);display:block;margin-bottom:0.2rem;">Username or Email</label>'
                + '<input type="text" id="lfp-identity" placeholder="username or email" autocomplete="username" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid var(--gray-300,#d1d5db);border-radius:6px;font-size:0.82rem;margin-bottom:0.5rem;font-family:inherit;">'
                + '<label for="lfp-pass" style="font-size:0.72rem;font-weight:600;color:var(--text-color);display:block;margin-bottom:0.2rem;">Password</label>'
                + '<input type="password" id="lfp-pass" placeholder="Enter password" autocomplete="current-password" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid var(--gray-300,#d1d5db);border-radius:6px;font-size:0.82rem;margin-bottom:0.65rem;font-family:inherit;">'
                + '<button id="lfp-submit" onclick="doLogin()" style="width:100%;padding:0.5rem;background:var(--primary-color);color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-family:inherit;font-size:0.82rem;">Sign In</button>'
                + '<div id="lfp-lockout" style="display:none;font-size:0.73rem;color:#92400e;background:#fef3c7;border-radius:6px;padding:0.4rem 0.6rem;margin-top:0.45rem;text-align:center;"></div>'
                + '<div style="text-align:center;margin-top:0.45rem;"><a href="#" onclick="showResetForm(event)" style="font-size:0.72rem;color:var(--secondary-color);font-weight:600;text-decoration:none;">Forgot Password?</a></div>';
        }
    }

    window.toggleLoginPopup = function (e) {
        e.preventDefault();
        var popup = document.getElementById('loginPopup');
        var isActive = popup.classList.contains('active');
        if (isActive) { popup.classList.remove('active'); return; }
        if (typeof closeDonatePopups === 'function') closeDonatePopups();
        if (typeof closeAllFloatPopups === 'function') closeAllFloatPopups();
        renderLoginPopup(popup);
        popup.classList.add('active');
        fetch('auth.php?action=check_session', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                _loginState = d.logged_in ? { loggedIn: true, user: d.user } : { loggedIn: false };
                _syncGlobalState();
                _storeCsrf(d);
                renderLoginPopup(popup);
            }).catch(function () {});
    };

    window.closeLoginPopup = function () {
        var popup = document.getElementById('loginPopup');
        if (popup) popup.classList.remove('active');
    };

    window.doLogin = function () {
        var identity = document.getElementById('lfp-identity').value.trim();
        var password = document.getElementById('lfp-pass').value;
        var errEl = document.getElementById('lfp-error');
        var lockEl = document.getElementById('lfp-lockout');
        var btn = document.getElementById('lfp-submit');
        if (lockEl) lockEl.style.display = 'none';
        if (!identity || !password) { errEl.textContent = 'Please fill in all fields.'; errEl.style.display = 'block'; return; }
        btn.textContent = 'Signing in\u2026'; btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'login'); fd.append('identity', identity); fd.append('password', password);
        fetch('auth.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    _loginState = { loggedIn: true, user: d.user };
                    _syncGlobalState();
                    _storeCsrf(d);
                    var popup = document.getElementById('loginPopup');
                    renderLoginPopup(popup);
                    var trigger = document.querySelector('.login-trigger');
                    if (trigger) trigger.textContent = d.user.username;
                    // Prompt password change if required
                    if (d.must_change_password) {
                        window._hosuMustChangePassword = true;
                        if (typeof window.showForcePasswordChange === 'function') {
                            window.showForcePasswordChange();
                        } else {
                            alert('You must change your default password before continuing.');
                        }
                    }
                } else {
                    errEl.textContent = d.error || 'Invalid credentials.'; errEl.style.display = 'block';
                    btn.textContent = 'Sign In'; btn.disabled = false;
                    if (d.locked && lockEl) {
                        var mins = Math.ceil((d.retry_after || 900) / 60);
                        lockEl.innerHTML = '&#128274; Account locked. Try again in <strong>' + mins + '</strong> minute(s).';
                        lockEl.style.display = 'block';
                        btn.disabled = true; btn.textContent = 'Locked';
                        btn.style.background = '#94a3b8';
                        setTimeout(function () { btn.disabled = false; btn.textContent = 'Sign In'; btn.style.background = ''; if (lockEl) lockEl.style.display = 'none'; }, (d.retry_after || 900) * 1000);
                    } else if (d.attempts_left !== undefined && d.attempts_left <= 2 && lockEl) {
                        lockEl.innerHTML = '&#9888; ' + escHtml(String(d.attempts_left)) + ' attempt(s) remaining before lockout.';
                        lockEl.style.display = 'block';
                    }
                }
            }).catch(function () {
                errEl.textContent = 'Connection error. Please try again.'; errEl.style.display = 'block';
                btn.textContent = 'Sign In'; btn.disabled = false;
            });
    };

    window.doLogout = function () {
        fetch('auth.php?action=logout', { credentials: 'same-origin' })
            .then(function () {
                _loginState = { loggedIn: false };
                _syncGlobalState();
                var trigger = document.querySelector('.login-trigger');
                if (trigger) trigger.textContent = 'Member Portal';
                var popup = document.getElementById('loginPopup');
                renderLoginPopup(popup);
            }).catch(function () {});
    };

    /* ── Password Reset Flow ─────────────────────────────── */
    var _resetToken = null;

    window.showResetForm = function (e) {
        if (e) e.preventDefault();
        var popup = document.getElementById('loginPopup');
        popup.innerHTML =
            '<button class="lfp-close" onclick="this.closest(\'.login-float-popup\').classList.remove(\'active\')">&times;</button>'
            + '<h3>&#128273; Reset Password</h3>'
            + '<p style="font-size:0.73rem;color:var(--text-light);margin-bottom:0.65rem;">Enter your admin email or phone number</p>'
            + '<div id="rst-msg" style="display:none;font-size:0.75rem;border-radius:5px;padding:0.35rem 0.6rem;margin-bottom:0.5rem;"></div>'
            + '<div id="rst-step1">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">Email</label>'
            + '<input type="email" id="rst-email" placeholder="info@mcare.or.ug" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.4rem;font-family:inherit;">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">Or Phone Number</label>'
            + '<input type=\"tel\" id=\"rst-phone\" placeholder=\"Phone number with country code\" style=\"width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.65rem;font-family:inherit;\">'
            + '<button onclick="requestReset()" style="width:100%;padding:0.5rem;background:var(--secondary-color);color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-family:inherit;font-size:0.82rem;">Send Reset Code</button>'
            + '</div>'
            + '<div id="rst-step2" style="display:none;">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">6-Digit Code</label>'
            + '<input type="text" id="rst-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.4rem;font-family:inherit;text-align:center;letter-spacing:0.3rem;font-weight:700;">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">New Password</label>'
            + '<input type="password" id="rst-newpw" placeholder="Min 8 chars, uppercase+lowercase+number" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.65rem;font-family:inherit;">'
            + '<button onclick="submitReset()" style="width:100%;padding:0.5rem;background:var(--primary-color);color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-family:inherit;font-size:0.82rem;">Reset Password</button>'
            + '</div>'
            + '<div style="text-align:center;margin-top:0.6rem;"><a href="#" onclick="backToLogin(event)" style="font-size:0.72rem;color:var(--secondary-color);font-weight:600;text-decoration:none;">&larr; Back to Login</a></div>';
    };

    window.backToLogin = function (e) {
        if (e) e.preventDefault();
        _resetToken = null;
        var popup = document.getElementById('loginPopup');
        renderLoginPopup(popup);
    };

    window.requestReset = function () {
        var email = document.getElementById('rst-email').value.trim();
        var phone = document.getElementById('rst-phone').value.trim();
        var msg = document.getElementById('rst-msg');
        if (!email && !phone) { msg.textContent = 'Enter email or phone'; msg.style.display = 'block'; msg.style.background = 'rgba(230,57,70,0.07)'; msg.style.color = 'var(--primary-color)'; return; }
        var fd = new FormData();
        fd.append('email', email); fd.append('phone', phone);
        fetch('auth.php?action=request_reset', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                msg.style.display = 'block';
                if (d.success) {
                    _resetToken = d.token || null;
                    msg.style.background = '#f0fdf4'; msg.style.color = '#166534';
                    msg.innerHTML = escHtml(d.message || 'Reset code sent.');
                    document.getElementById('rst-step1').style.display = 'none';
                    document.getElementById('rst-step2').style.display = 'block';
                } else {
                    msg.style.background = 'rgba(230,57,70,0.07)'; msg.style.color = 'var(--primary-color)';
                    msg.textContent = d.error || 'Error';
                }
            }).catch(function () { msg.textContent = 'Network error'; msg.style.display = 'block'; msg.style.background = 'rgba(230,57,70,0.07)'; msg.style.color = 'var(--primary-color)'; });
    };

    window.submitReset = function () {
        var code = document.getElementById('rst-code').value.trim();
        var newpw = document.getElementById('rst-newpw').value;
        var msg = document.getElementById('rst-msg');
        if (!code || !newpw) { msg.textContent = 'Enter code and new password'; msg.style.display = 'block'; msg.style.background = 'rgba(230,57,70,0.07)'; msg.style.color = 'var(--primary-color)'; return; }
        if (!_resetToken) { msg.textContent = 'No reset token. Start over.'; msg.style.display = 'block'; msg.style.background = 'rgba(230,57,70,0.07)'; msg.style.color = 'var(--primary-color)'; return; }
        var fd = new FormData();
        fd.append('token', _resetToken); fd.append('code', code); fd.append('new_password', newpw);
        fetch('auth.php?action=reset_password', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                msg.style.display = 'block';
                if (d.success) {
                    msg.style.background = '#f0fdf4'; msg.style.color = '#166534';
                    msg.innerHTML = '&#10003; ' + escHtml(d.message || 'Password reset!');
                    _resetToken = null;
                    document.getElementById('rst-step2').style.display = 'none';
                    setTimeout(function () { backToLogin(); }, 2500);
                } else {
                    msg.style.background = 'rgba(230,57,70,0.07)'; msg.style.color = 'var(--primary-color)';
                    msg.textContent = d.error || 'Error';
                }
            }).catch(function () { msg.textContent = 'Network error'; msg.style.display = 'block'; msg.style.background = 'rgba(230,57,70,0.07)'; msg.style.color = 'var(--primary-color)'; });
    };

    /* ── Close on outside click ──────────────────────────── */
    document.addEventListener('click', function (e) {
        var popup = document.getElementById('loginPopup');
        if (popup && popup.classList.contains('active') && !e.target.closest('.login-trigger-wrap')) {
            popup.classList.remove('active');
        }
    });

    /* ── Auto-check session on load ──────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        fetch('auth.php?action=check_session', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.logged_in) {
                    _loginState = { loggedIn: true, user: d.user };
                    _syncGlobalState();
                    _storeCsrf(d);
                    var trigger = document.querySelector('.login-trigger');
                    if (trigger) trigger.textContent = d.user.username;
                }
            }).catch(function () {});
    });
})();
