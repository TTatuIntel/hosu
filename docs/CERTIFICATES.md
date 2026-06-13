# HOSU Certificates & Public Verification

Implementation notes for the certificate and verification subsystem.
Covers (a) membership certificates, (b) event-attendance certificates, and
(c) the public **"verified member"** lookup widget.

## 1. Membership certificate

| Concern | Where |
| --- | --- |
| Render (print-to-PDF) | [certificate.php](../certificate.php) |
| Public verify (QR target) | [verify.php](../verify.php) |
| Auto-email on activation | `sendMembershipCertificateEmail()` in [api.php](../api.php) |
| Trigger points | `update_member_approval` and `verify_payment` actions in [api.php](../api.php) |

A membership certificate is **automatically emailed** the first time a member
becomes `active` (approved AND paid AND not expired). The email contains:

- A signed link to `certificate.php?member=<ID>` (the member can self-print).
- A signed link to `verify.php?m=<MEM_NUM>&t=<HMAC>` for third-party verification.
- A QR code (rendered server-side via `api.qrserver.com`) encoding the verify URL.

The HMAC token is `substr(hash_hmac('sha256', mem_num . '|' . email, CERT_HMAC_KEY), 0, 16)`.
The same key is used by `certificate.php` and `verify.php`, so the QR on a
printed certificate stays valid forever (as long as the member's email
and membership_number are unchanged).

## 2. Event-attendance certificate

| Concern | Where |
| --- | --- |
| Render (print-to-PDF) | [event_certificate.php](../event_certificate.php) |
| Public verify (QR target) | [verify_event.php](../verify_event.php) |
| Email helper | `sendEventCertificateEmail()` in [payment.php](../payment.php) |
| Admin issuance API | `issue_event_certificate` and `issue_event_certificates_bulk` in [api.php](../api.php) |
| Schema | `cert_issued_at` + `cert_token` columns on `event_registrants` |

### Issuance flow

1. Admin checks attendees in via the registrants modal in [admin.html](../admin.html)
   (`mark_registrant_attendance` action). `qr_scanned = 1` and `scanned_at = NOW()`.
2. Admin clicks **🎓 Issue certificate** on the row (or **🎓 Issue to all attended**
   for bulk). The API:
   - Generates a `cert_token` (64 hex bytes) if missing.
   - Stamps `cert_issued_at = NOW()`.
   - Calls `sendEventCertificateEmail($pdo, $regId)`.
3. The email contains:
   - A signed certificate link `event_certificate.php?reg=<ID>&t=<TOKEN>`.
   - A signed verify link `verify_event.php?r=<ID>&t=<TOKEN>`.
   - The certificate is only issued for `qr_scanned = 1` registrants.

### Self-access without an admin

The attendee can open the certificate themselves with the same signed URL —
no login required. Token check is constant-time (`hash_equals`).

## 3. Public verified-member lookup

| Concern | Where |
| --- | --- |
| API | `lookup_member_status` action in [api.php](../api.php) |
| Widget | "Check membership status" card in [directory.html](../directory.html) |

A visitor can type a name (or email) and receive a **green badge** if a paid,
approved, active member matches. The response never exposes:

- email, phone, addresses
- payment records, transaction refs
- internal notes, documents

The widget shows: `✅ Verified · Active member` plus category, institution,
country, and "Valid through 31 Dec YYYY". For non-matches or non-active
records, the badge is neutral or amber (e.g. "Membership lapsed") so visitors
get useful information without leaking private data.

To prevent enumeration, the lookup:
- Requires at least 3 characters.
- Rate-limits via `hosuPublicJsonCache` cache headers.
- Returns only the first match if more than one record matches loosely.

## 4. Security & data exposure

- No private fields ever appear in `certificate.php`, `event_certificate.php`,
  `verify.php`, `verify_event.php`, or `lookup_member_status`.
- All admin issuance endpoints check `$_SESSION['user_role'] === 'admin'`.
- `event_certificate.php` requires `qr_scanned = 1` AND a valid token, or an
  authenticated admin session.
- Tokens are unguessable random bytes (32 bytes hex) or short-lived HMAC
  digests; both are compared with `hash_equals`.

## 5. Operational notes

- Set the `CERT_HMAC_KEY` env var to a long random value in production. If it
  changes, all previously-issued QR codes stop validating — only change it
  if a leak is suspected.
- The certificate templates use `print-to-PDF` from the browser, so no
  server-side PDF library is required.
- The certificate QR is fetched from `api.qrserver.com`. If you prefer to
  self-host, swap the URL in `certificate.php` and `event_certificate.php`.
