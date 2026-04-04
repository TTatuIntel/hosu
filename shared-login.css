/* ============================================================
   SHARED LOGIN POPUP — shared-login.css
   Include on ALL pages alongside shared-login.js
   ============================================================ */

/* Login Floating Popup */
.login-trigger-wrap {
    position: relative;
    display: inline-block;
}
.login-float-popup {
    display: none;
    position: absolute;
    top: calc(100% + 14px);
    right: 0;
    z-index: 1001;
    width: 300px;
    background: rgba(255, 255, 255, 0.78);
    -webkit-backdrop-filter: blur(24px) saturate(1.4);
    backdrop-filter: blur(24px) saturate(1.4);
    border: 1px solid rgba(255, 255, 255, 0.55);
    border-radius: 14px;
    padding: 1.2rem 1.3rem 1.1rem;
    box-shadow:
        0 12px 40px rgba(0, 0, 0, 0.14),
        0 4px 12px rgba(0, 0, 0, 0.06),
        inset 0 1px 0 rgba(255, 255, 255, 0.6);
    animation: loginPopUp 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    text-align: left;
}
.login-float-popup.active {
    display: block;
}
/* Arrow pointing up */
.login-float-popup::after {
    content: '';
    position: absolute;
    top: -7px;
    right: 24px;
    width: 14px;
    height: 14px;
    background: rgba(255, 255, 255, 0.78);
    border-top: 1px solid rgba(255, 255, 255, 0.55);
    border-left: 1px solid rgba(255, 255, 255, 0.55);
    transform: rotate(45deg);
    -webkit-backdrop-filter: blur(24px);
    backdrop-filter: blur(24px);
}
.login-float-popup .lfp-close {
    position: absolute;
    top: 0.55rem;
    right: 0.6rem;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.06);
    border: none;
    border-radius: 50%;
    font-size: 0.9rem;
    color: var(--text-light);
    cursor: pointer;
    line-height: 1;
    transition: all 0.2s ease;
}
.login-float-popup .lfp-close:hover {
    background: var(--primary-color);
    color: var(--white);
}
.login-float-popup h3 {
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--secondary-color);
    margin-bottom: 0.15rem;
    padding-right: 1.8rem;
}
.login-float-popup .lfp-subtitle {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 0.7rem;
}
.login-float-popup .lfp-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 0.25rem;
    display: block;
}
.login-float-popup .lfp-input {
    width: 100%;
    padding: 0.45rem 0.6rem;
    border: 1.5px solid var(--gray-300);
    border-radius: 8px;
    font-size: 0.8rem;
    font-family: inherit;
    outline: none;
    transition: border-color 0.2s;
    background: rgba(255,255,255,0.6);
    box-sizing: border-box;
}
.login-float-popup .lfp-input:focus {
    border-color: var(--secondary-color);
}
.login-float-popup .lfp-field {
    margin-bottom: 0.5rem;
}
.login-float-popup .lfp-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.6rem;
    font-size: 0.72rem;
}
.login-float-popup .lfp-options label {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    color: var(--text-light);
    cursor: pointer;
}
.login-float-popup .lfp-options label input {
    accent-color: var(--secondary-color);
}
.login-float-popup .lfp-options a {
    color: var(--secondary-color);
    text-decoration: none;
    font-weight: 500;
}
.login-float-popup .lfp-options a:hover {
    text-decoration: underline;
}
.login-float-popup .lfp-submit {
    width: 100%;
    padding: 0.5rem;
    background: var(--secondary-color);
    color: var(--white);
    border: none;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: inherit;
}
.login-float-popup .lfp-submit:hover {
    background: var(--secondary-dark);
    transform: translateY(-1px);
}
.login-float-popup .lfp-footer {
    text-align: center;
    margin-top: 0.6rem;
    font-size: 0.72rem;
    color: var(--text-light);
}
.login-float-popup .lfp-footer a {
    color: var(--primary-color);
    font-weight: 600;
    text-decoration: none;
}
.login-float-popup .lfp-footer a:hover {
    text-decoration: underline;
}
@keyframes loginPopUp {
    from { opacity: 0; transform: translateY(8px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
