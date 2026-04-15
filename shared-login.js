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
        // Enter key support for login
        setTimeout(function () {
            var idEl = document.getElementById('lfp-identity');
            var pwEl = document.getElementById('lfp-pass');
            if (idEl) idEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { var pw = document.getElementById('lfp-pass'); if (pw) pw.focus(); } });
            if (pwEl) pwEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') doLogin(); });
            if (idEl) idEl.focus();
        }, 50);
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
                    // Seed admin detected: must create personal account
                    if (d.must_create_account) {
                        window._hosuMustCreateAccount = true;
                        setTimeout(function () {
                            if (typeof window.showCreateAdminAccount === 'function') {
                                window.showCreateAdminAccount();
                            }
                        }, 300);
                    }
                    // Prompt password change if required (non-seed accounts)
                    else if (d.must_change_password) {
                        window._hosuMustChangePassword = true;
                        if (typeof window.showForcePasswordChange === 'function') {
                            window.showForcePasswordChange();
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
            + '<p style="font-size:0.73rem;color:var(--text-light);margin-bottom:0.65rem;">A 6-digit code will be sent to <strong>info@hosu.or.ug</strong></p>'
            + '<div id="rst-msg" style="display:none;font-size:0.75rem;border-radius:5px;padding:0.35rem 0.6rem;margin-bottom:0.5rem;"></div>'
            + '<div id="rst-step1">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">Username or Email</label>'
            + '<input type="text" id="rst-email" placeholder="admin or info@hosu.or.ug" autocomplete="username" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.4rem;font-family:inherit;">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">Or Phone Number</label>'
            + '<input type="tel" id="rst-phone" placeholder="Phone number with country code" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.65rem;font-family:inherit;">'
            + '<button id="rst-send-btn" onclick="requestReset()" style="width:100%;padding:0.5rem;background:var(--secondary-color);color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-family:inherit;font-size:0.82rem;">Send Reset Code</button>'
            + '</div>'
            + '<div id="rst-step2" style="display:none;">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">6-Digit Code</label>'
            + '<input type="text" id="rst-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" inputmode="numeric" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.4rem;font-family:inherit;text-align:center;letter-spacing:0.3rem;font-weight:700;">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">New Password</label>'
            + '<input type="password" id="rst-newpw" placeholder="Min 8 chars, uppercase+lowercase+number" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.4rem;font-family:inherit;">'
            + '<label style="font-size:0.72rem;font-weight:600;display:block;margin-bottom:0.2rem;">Confirm Password</label>'
            + '<input type="password" id="rst-cfpw" placeholder="Re-enter new password" style="width:100%;padding:0.45rem 0.65rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.82rem;margin-bottom:0.65rem;font-family:inherit;">'
            + '<button id="rst-submit-btn" onclick="submitReset()" style="width:100%;padding:0.5rem;background:var(--primary-color);color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-family:inherit;font-size:0.82rem;">Reset Password</button>'
            + '</div>'
            + '<div style="text-align:center;margin-top:0.6rem;"><a href="#" onclick="backToLogin(event)" style="font-size:0.72rem;color:var(--secondary-color);font-weight:600;text-decoration:none;">&larr; Back to Login</a></div>';

        // Enter key support
        setTimeout(function () {
            var emailEl = document.getElementById('rst-email');
            var phoneEl = document.getElementById('rst-phone');
            var codeEl = document.getElementById('rst-code');
            var newpwEl = document.getElementById('rst-newpw');
            var cfpwEl = document.getElementById('rst-cfpw');
            if (emailEl) emailEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') requestReset(); });
            if (phoneEl) phoneEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') requestReset(); });
            if (codeEl) codeEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { var np = document.getElementById('rst-newpw'); if (np) np.focus(); } });
            if (newpwEl) newpwEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { var cf = document.getElementById('rst-cfpw'); if (cf) cf.focus(); } });
            if (cfpwEl) cfpwEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') submitReset(); });
            if (emailEl) emailEl.focus();
        }, 50);
    };

    window.backToLogin = function (e) {
        if (e) e.preventDefault();
        _resetToken = null;
        var popup = document.getElementById('loginPopup');
        renderLoginPopup(popup);
    };

    function _rstMsg(text, isError) {
        var msg = document.getElementById('rst-msg');
        if (!msg) return;
        msg.style.display = 'block';
        if (isError) {
            msg.style.background = 'rgba(230,57,70,0.07)';
            msg.style.color = 'var(--primary-color)';
            msg.textContent = text;
        } else {
            msg.style.background = '#f0fdf4';
            msg.style.color = '#166534';
            msg.innerHTML = text;
        }
    }

    function _rstBtn(id, loading) {
        var btn = document.getElementById(id);
        if (!btn) return;
        btn.disabled = loading;
        if (id === 'rst-send-btn') {
            btn.textContent = loading ? 'Sending\u2026' : 'Send Reset Code';
        } else {
            btn.textContent = loading ? 'Resetting\u2026' : 'Reset Password';
        }
    }

    window.requestReset = function () {
        var email = document.getElementById('rst-email').value.trim();
        var phone = document.getElementById('rst-phone').value.trim();
        if (!email && !phone) { _rstMsg('Enter your email or phone number', true); return; }

        _rstBtn('rst-send-btn', true);
        var fd = new FormData();
        fd.append('email', email);
        fd.append('phone', phone);
        fetch('auth.php?action=request_reset', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                _rstBtn('rst-send-btn', false);
                if (d.success) {
                    _resetToken = d.token || null;
                    _rstMsg('&#10003; ' + escHtml(d.message || 'Reset code sent to info@hosu.or.ug'), false);
                    document.getElementById('rst-step1').style.display = 'none';
                    document.getElementById('rst-step2').style.display = 'block';
                    setTimeout(function () { var c = document.getElementById('rst-code'); if (c) c.focus(); }, 100);
                } else {
                    _rstMsg(d.error || 'Something went wrong. Try again.', true);
                }
            }).catch(function () {
                _rstBtn('rst-send-btn', false);
                _rstMsg('Network error. Check your connection.', true);
            });
    };

    window.submitReset = function () {
        var code = document.getElementById('rst-code').value.trim();
        var newpw = document.getElementById('rst-newpw').value;
        var cfpw = document.getElementById('rst-cfpw').value;
        if (!code) { _rstMsg('Enter the 6-digit code from your email', true); return; }
        if (!newpw) { _rstMsg('Enter a new password', true); return; }
        if (newpw !== cfpw) { _rstMsg('Passwords do not match', true); return; }
        if (newpw.length < 8 || !/[A-Z]/.test(newpw) || !/[a-z]/.test(newpw) || !/[0-9]/.test(newpw)) {
            _rstMsg('Password must be 8+ chars with uppercase, lowercase, and a number', true); return;
        }
        if (!_resetToken) { _rstMsg('Reset session expired. Please start over.', true); return; }

        _rstBtn('rst-submit-btn', true);
        var fd = new FormData();
        fd.append('token', _resetToken);
        fd.append('code', code);
        fd.append('new_password', newpw);
        fetch('auth.php?action=reset_password', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                _rstBtn('rst-submit-btn', false);
                if (d.success) {
                    _rstMsg('&#10003; ' + escHtml(d.message || 'Password reset successfully!'), false);
                    _resetToken = null;
                    document.getElementById('rst-step2').style.display = 'none';
                    setTimeout(function () { backToLogin(); }, 2500);
                } else {
                    _rstMsg(d.error || 'Failed to reset password. Try again.', true);
                }
            }).catch(function () {
                _rstBtn('rst-submit-btn', false);
                _rstMsg('Network error. Check your connection.', true);
            });
    };

    /* ── Force Password Change (works on all pages) ── */
    window.showForcePasswordChange = window.showForcePasswordChange || function () {
        if (document.getElementById('force-pw-overlay')) return;
        var overlay = document.createElement('div');
        overlay.id = 'force-pw-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';
        overlay.innerHTML = '<div style="background:#fff;border-radius:12px;padding:2rem;max-width:400px;width:90%;box-shadow:0 4px 24px rgba(0,0,0,0.3);">'
            + '<h3 style="margin:0 0 0.5rem;color:#e63946;">&#128274; Password Change Required</h3>'
            + '<p style="font-size:0.85rem;color:#555;margin-bottom:1rem;">You are using the default password. Please change it now for security.</p>'
            + '<div id="fpw-err" style="display:none;color:#e63946;font-size:0.8rem;margin-bottom:0.5rem;background:rgba(230,57,70,0.07);padding:0.4rem;border-radius:6px;"></div>'
            + '<label style="font-size:0.78rem;font-weight:600;">Current Password</label>'
            + '<input type="password" id="fpw-current" style="width:100%;padding:0.45rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.85rem;margin-bottom:0.5rem;">'
            + '<label style="font-size:0.78rem;font-weight:600;">New Password</label>'
            + '<input type="password" id="fpw-new" placeholder="Min 8 chars, upper+lower+number" style="width:100%;padding:0.45rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.85rem;margin-bottom:0.5rem;">'
            + '<label style="font-size:0.78rem;font-weight:600;">Confirm New Password</label>'
            + '<input type="password" id="fpw-confirm" style="width:100%;padding:0.45rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.85rem;margin-bottom:1rem;">'
            + '<button id="fpw-submit" onclick="submitForcePasswordChange()" style="width:100%;padding:0.55rem;background:#e63946;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:0.88rem;">Change Password</button>'
            + '<p style="font-size:0.7rem;color:#9ca3af;margin:0.5rem 0 0;text-align:center;">You will be reminded on each login until you change it.</p>'
            + '</div>';
        document.body.appendChild(overlay);

        // Enter key support
        setTimeout(function () {
            var cur = document.getElementById('fpw-current');
            var np = document.getElementById('fpw-new');
            var cf = document.getElementById('fpw-confirm');
            if (cur) cur.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && np) np.focus(); });
            if (np) np.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && cf) cf.focus(); });
            if (cf) cf.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') submitForcePasswordChange(); });
            if (cur) cur.focus();
        }, 50);
    };

    window.submitForcePasswordChange = window.submitForcePasswordChange || function () {
        var cur = document.getElementById('fpw-current').value;
        var np = document.getElementById('fpw-new').value;
        var cf = document.getElementById('fpw-confirm').value;
        var err = document.getElementById('fpw-err');
        var btn = document.getElementById('fpw-submit');
        if (!cur || !np || !cf) { err.textContent = 'All fields are required.'; err.style.display = 'block'; return; }
        if (np !== cf) { err.textContent = 'Passwords do not match.'; err.style.display = 'block'; return; }
        if (np.length < 8 || !/[A-Z]/.test(np) || !/[a-z]/.test(np) || !/[0-9]/.test(np)) {
            err.textContent = 'Password must be 8+ chars with uppercase, lowercase, and a number.'; err.style.display = 'block'; return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'Changing\u2026'; }
        var fd = new FormData();
        fd.append('action', 'change_password');
        fd.append('current_password', cur);
        fd.append('new_password', np);
        if (window._hosuCsrfToken) fd.append('csrf_token', window._hosuCsrfToken);
        fetch('auth.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    window._hosuMustChangePassword = false;
                    var ov = document.getElementById('force-pw-overlay');
                    if (ov) ov.remove();
                    // Show brief success feedback
                    var toast = document.createElement('div');
                    toast.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#166534;color:#fff;padding:0.6rem 1.5rem;border-radius:8px;font-size:0.85rem;font-weight:600;z-index:10000;box-shadow:0 4px 12px rgba(0,0,0,0.2);';
                    toast.textContent = '\u2713 Password changed successfully!';
                    document.body.appendChild(toast);
                    setTimeout(function () { toast.remove(); }, 3000);
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = 'Change Password'; }
                    err.textContent = d.error || 'Failed to change password.';
                    err.style.display = 'block';
                }
            }).catch(function () {
                if (btn) { btn.disabled = false; btn.textContent = 'Change Password'; }
                err.textContent = 'Network error. Try again.';
                err.style.display = 'block';
            });
    };

    /* ── Create Admin Account (seed/default admin gateway) ── */
    window.showCreateAdminAccount = function () {
        if (document.getElementById('create-admin-overlay')) return;
        var overlay = document.createElement('div');
        overlay.id = 'create-admin-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.75);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;';
        overlay.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:520px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,0.3);overflow:hidden;">'
            // Header bar
            + '<div style="background:linear-gradient(135deg,#0d4593,#1a6dd4);padding:0.8rem 1.2rem;display:flex;align-items:center;justify-content:space-between;">'
            + '<div style="display:flex;align-items:center;gap:0.5rem;">'
            + '<span style="font-size:1.2rem;">🔐</span>'
            + '<div><div style="color:#fff;font-weight:700;font-size:0.92rem;">Create Your Admin Account</div>'
            + '<div style="color:rgba(255,255,255,0.7);font-size:0.68rem;">Set up your personal credentials to continue</div></div>'
            + '</div>'
            + '<button onclick="closeCreateAdminOverlay()" style="background:rgba(255,255,255,0.15);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;" title="Close (you\'ll be signed out)">&times;</button>'
            + '</div>'
            // Body
            + '<div style="padding:1rem 1.2rem 1.2rem;">'
            + '<div id="caa-err" style="display:none;color:#e63946;font-size:0.75rem;margin-bottom:0.5rem;background:rgba(230,57,70,0.07);padding:0.35rem 0.5rem;border-radius:5px;"></div>'
            + '<div id="caa-success" style="display:none;color:#166534;font-size:0.75rem;margin-bottom:0.5rem;background:#f0fdf4;padding:0.35rem 0.5rem;border-radius:5px;"></div>'
            // Row 1: Name + Email
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.4rem;">'
            + '<div><label style="font-size:0.7rem;font-weight:600;display:block;margin-bottom:0.1rem;">Full Name <span style="color:#e63946;">*</span></label>'
            + '<input type="text" id="caa-name" placeholder="Dr. Jane Onyango" autocomplete="name" style="width:100%;padding:0.38rem 0.5rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.8rem;font-family:inherit;box-sizing:border-box;"></div>'
            + '<div><label style="font-size:0.7rem;font-weight:600;display:block;margin-bottom:0.1rem;">Email <span style="color:#e63946;">*</span></label>'
            + '<input type="email" id="caa-email" placeholder="you@gmail.com" autocomplete="email" style="width:100%;padding:0.38rem 0.5rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.8rem;font-family:inherit;box-sizing:border-box;"></div>'
            + '</div>'
            // Row 2: Phone (full width)
            + '<div style="margin-bottom:0.4rem;"><label style="font-size:0.7rem;font-weight:600;display:block;margin-bottom:0.1rem;">Phone Number</label>'
            + '<input type="tel" id="caa-phone" placeholder="+256 7XX XXX XXX" autocomplete="tel" style="width:100%;padding:0.38rem 0.5rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.8rem;font-family:inherit;box-sizing:border-box;"></div>'
            // Row 3: Password + Confirm
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.6rem;">'
            + '<div><label style="font-size:0.7rem;font-weight:600;display:block;margin-bottom:0.1rem;">Password <span style="color:#e63946;">*</span></label>'
            + '<input type="password" id="caa-pass" placeholder="8+ chars, Aa1" autocomplete="new-password" style="width:100%;padding:0.38rem 0.5rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.8rem;font-family:inherit;box-sizing:border-box;"></div>'
            + '<div><label style="font-size:0.7rem;font-weight:600;display:block;margin-bottom:0.1rem;">Confirm <span style="color:#e63946;">*</span></label>'
            + '<input type="password" id="caa-pass2" placeholder="Re-enter password" autocomplete="new-password" style="width:100%;padding:0.38rem 0.5rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:0.8rem;font-family:inherit;box-sizing:border-box;"></div>'
            + '</div>'
            // Submit
            + '<button id="caa-submit" onclick="submitCreateAdminAccount()" style="width:100%;padding:0.5rem;background:#0d4593;color:#fff;border:none;border-radius:7px;font-weight:700;cursor:pointer;font-family:inherit;font-size:0.85rem;">Create My Account</button>'
            + '<p style="font-size:0.65rem;color:#9ca3af;margin:0.4rem 0 0;text-align:center;">You\'ll log in with your email next time. Default credentials remain available for onboarding new admins.</p>'
            + '</div></div>';
        document.body.appendChild(overlay);

        // Enter key navigation
        setTimeout(function () {
            var nameEl = document.getElementById('caa-name');
            var emailEl = document.getElementById('caa-email');
            var phoneEl = document.getElementById('caa-phone');
            var passEl = document.getElementById('caa-pass');
            var pass2El = document.getElementById('caa-pass2');
            if (nameEl) nameEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && emailEl) emailEl.focus(); });
            if (emailEl) emailEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && phoneEl) phoneEl.focus(); });
            if (phoneEl) phoneEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && passEl) passEl.focus(); });
            if (passEl) passEl.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && pass2El) pass2El.focus(); });
            if (pass2El) pass2El.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') submitCreateAdminAccount(); });
            if (nameEl) nameEl.focus();
        }, 100);
    };

    window.closeCreateAdminOverlay = function () {
        var ov = document.getElementById('create-admin-overlay');
        if (ov) ov.remove();
        // Sign out since they didn't create an account — no admin access without personal credentials
        doLogout();
    };

    window.submitCreateAdminAccount = function () {
        var name = document.getElementById('caa-name').value.trim();
        var email = document.getElementById('caa-email').value.trim();
        var phone = document.getElementById('caa-phone').value.trim();
        var pass = document.getElementById('caa-pass').value;
        var pass2 = document.getElementById('caa-pass2').value;
        var err = document.getElementById('caa-err');
        var suc = document.getElementById('caa-success');
        var btn = document.getElementById('caa-submit');
        err.style.display = 'none';
        suc.style.display = 'none';

        if (!name) { err.textContent = 'Please enter your full name.'; err.style.display = 'block'; return; }
        if (!email) { err.textContent = 'Please enter your email address.'; err.style.display = 'block'; return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { err.textContent = 'Please enter a valid email address.'; err.style.display = 'block'; return; }
        if (!pass) { err.textContent = 'Please create a password.'; err.style.display = 'block'; return; }
        if (pass !== pass2) { err.textContent = 'Passwords do not match.'; err.style.display = 'block'; return; }
        if (pass.length < 8 || !/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass)) {
            err.textContent = 'Password must be 8+ characters with at least one uppercase, lowercase, and number.';
            err.style.display = 'block'; return;
        }

        btn.disabled = true; btn.textContent = 'Creating Account\u2026';

        var fd = new FormData();
        fd.append('action', 'create_admin_account');
        fd.append('full_name', name);
        fd.append('email', email);
        fd.append('phone', phone);
        fd.append('new_password', pass);
        if (window._hosuCsrfToken) fd.append('csrf_token', window._hosuCsrfToken);

        fetch('auth.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    window._hosuMustCreateAccount = false;
                    window._hosuMustChangePassword = false;
                    _storeCsrf(d);
                    _loginState = { loggedIn: true, user: d.user };
                    _syncGlobalState();

                    // Update login trigger text
                    var trigger = document.querySelector('.login-trigger');
                    if (trigger) trigger.textContent = d.user.username;

                    suc.innerHTML = '\u2713 Account created! Welcome, <strong>' + escHtml(name.split(' ')[0]) + '</strong>. You can now log in with your email: <strong>' + escHtml(email) + '</strong>';
                    suc.style.display = 'block';
                    err.style.display = 'none';
                    btn.textContent = '\u2713 Account Created';
                    btn.style.background = '#166534';

                    // Remove overlay after brief delay
                    setTimeout(function () {
                        var ov = document.getElementById('create-admin-overlay');
                        if (ov) ov.remove();
                        // Render updated login popup
                        var popup = document.getElementById('loginPopup');
                        if (popup) renderLoginPopup(popup);
                    }, 3000);
                } else {
                    btn.disabled = false; btn.textContent = 'Create My Account';
                    err.textContent = d.error || 'Failed to create account.';
                    err.style.display = 'block';
                }
            }).catch(function () {
                btn.disabled = false; btn.textContent = 'Create My Account';
                err.textContent = 'Network error. Please try again.';
                err.style.display = 'block';
            });
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
                    // Prompt account creation or password change on page load
                    if (d.must_create_account) {
                        window._hosuMustCreateAccount = true;
                        setTimeout(function () {
                            if (typeof window.showCreateAdminAccount === 'function') {
                                window.showCreateAdminAccount();
                            }
                        }, 500);
                    } else if (d.must_change_password) {
                        window._hosuMustChangePassword = true;
                        setTimeout(function () {
                            if (typeof window.showForcePasswordChange === 'function') {
                                window.showForcePasswordChange();
                            }
                        }, 500);
                    }
                }
            }).catch(function () {});
    });
})();
