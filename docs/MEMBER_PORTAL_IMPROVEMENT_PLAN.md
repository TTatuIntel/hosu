# HOSU Member Database & Website Portal — Improvement Plan

**Prepared for the HOSU Secretariat · June 2026**  
**Technical implementation tracker for the `hosu` codebase**

---

## Implementation status (codebase)

| Capability | Plan phase | Status | Where in code |
|------------|------------|--------|---------------|
| Public website (about, membership, events) | Public layer | **Done** | `index.html`, `about.html`, `membership.html`, `events.html` |
| 16 membership categories | Phase 1 | **Done** | `membership_categories` table · `migrate_member_portal.php` |
| Pricing tiers (UGX 100K–500K) | Phase 1 | **Done** | `membership.html`, `api.php` → `register_member` |
| Join form → database | Phase 1 | **Done** | `api.php` → `register_member`, `pre_register` |
| Payment (PesaPal / MTN / Airtel / card) | Phase 1 | **Done** | `payment.php`, `pesapal_callback.php`, `pesapal_ipn.php` |
| Receipts & email confirmation | Phase 1 | **Done** | `receipt.php`, `sendMembershipPendingEmail()` |
| Member accounts & login | Phase 1 | **Done** | `auth.php` → `register_member_account`, `shared-login.js` |
| Member portal (profile, docs, payments) | Phase 1 | **Done** | `portal.html`, `portal.php` |
| Calendar-year-end expiry (31 Dec) | Governance | **Done** | `membership_helpers.php` → `hosuMembershipExpiry()` |
| Status engine (active = approved + paid + not expired) | Phase 1 | **Done** | `membership_helpers.php` → `hosuMembershipStatus()` |
| Admin dashboard & CSV export | Phase 1 | **Done** | `admin.html`, `api.php` → `export_csv` |
| Admin approval workflow | Phase 1 | **Done** | `admin.html` → Review member · `update_member_approval` |
| Public member directory | Phase 1 | **Done** | `directory.html` · `list_public_members` |
| About page live stats | Phase 0 | **Done** | `get_about_stats` (DB + optional `site_stats` overrides) |
| Renewal reminder emails | Phase 1 | **Done** | `send_renewal_reminders.php` (CLI + admin button via `run_renewal_reminders`) |
| Membership PDF certificates | Phase 2 | **Done** | `certificate.php` (print-to-PDF) + `verify.php` (QR verification) |
| CPD points tracking | Phase 3 | **Done** | `cpd_entries` table · admin `add_cpd_entry` · portal CPD pane |
| Committees / working groups | Phase 2 | **Done** | `committees` + `committee_members` tables · admin Committees tab · public directory section |
| Bulk email broadcast | Phase 2 | **Done** | `broadcast_email_preview` / `broadcast_email_send` · admin Broadcast tab |
| Granular admin roles | Phase 3 | **Planned** | Single `admin` / `member` role in `users` |

### One-time setup on server (keeps existing data)

```bash
php setup_db.php              # creates missing tables only — does not wipe data
php migrate_member_portal.php # adds portal columns — does not delete rows
```

**Do not run** old dev wipe scripts (`cleanup_db.php`, `clean_pending.php`) — they have been removed from the repo.

`migrate_member_portal.php` is safe on a live database:
- `CREATE TABLE IF NOT EXISTS` and `ADD COLUMN` only
- Category list inserted **only when** `membership_categories` is empty
- Backfill updates **NULL** fields only (membership numbers, expiry dates)
- Active members get `approval_status = approved` if still pending (no deletions)

---

## Executive summary

The current **hosu.or.ug** public site is a strong foundation. The investment is in the **member portal** and **admin dashboard** behind login — the ASCO-style model where members manage their own profiles and admins verify credentials.

**Core recommendation (unchanged):** Build HOSU as a **member portal**, not a form on a website. Members update personal data; admins verify and assign category; the system calculates active/expired status and sends renewals.

This codebase implements **Option C (Hybrid):** keep the existing public site and bolt on portal + admin.

---

## Three layers

| Layer | Audience | Purpose |
|-------|----------|---------|
| **Public website** | Everyone | About, categories, join, events, news, directory |
| **Member portal** | Logged-in members | Profile, status, dues, documents, directory visibility |
| **Admin dashboard** | Secretariat | Approve, verify, payments, export, content |

**Governing principle:** Members edit their own profile. Admins verify membership and credentials. The system calculates status and renewals.

### Public directory rule

Show only **approved** members who **opted in** (`public_profile = 1`). Fields shown: name, category, institution, country, specialty. Never show phone, address, documents, or payment data.

---

## Membership status logic

| Status | Meaning |
|--------|---------|
| `pending` | Submitted; awaiting admin review |
| `needs_correction` | Missing or incorrect information |
| `approved_unpaid` | Approved but dues not yet confirmed |
| `active` | Approved + paid + not expired |
| `expired` | Past expiry date |
| `suspended` | Administrative hold |
| `honorary` / `retired` | Special categories |

**The one rule:**  
`Active = approved by admin + dues paid + membership period not expired`

### Expiry rule (decided)

All memberships expire **31 December**. Late joiners on or after **1 October** roll forward to 31 December of the **following** year (implemented in `hosuMembershipExpiry()`).

---

## Member workflow (plan §8)

1. Click **Join Our Community** → `membership.html`
2. **Create account** → Member Portal login → **Create account** (`auth.php`)
3. **Complete application** → `register_member` (stored in `members` + `payments`)
4. **Upload documents** → `portal.php` → `upload_document`
5. **Admin reviews** → Admin → Members → **Review** → Approve / needs correction / reject
6. **Admin assigns category** → same review dialog
7. **Pay dues** → PesaPal or manual proof
8. **Payment confirmed** → `confirm_payment` / `verify_payment` + `hosuStampMemberPaymentVerified()`
9. **Status → Active** → when approved + paid + not expired
10. **Public directory** → member toggles visibility in portal; listed at `directory.html`
11. **Renewal reminders** → *to build* (cron + `renewal_reminders`)
12. **Auto-expire** → derived at read time; batch job *optional*

---

## Database (minimum fields)

### Core tables

| Table | Purpose |
|-------|---------|
| `users` | Login accounts (`admin` / `member`) |
| `members` | Member profiles, approval, expiry |
| `payments` | Dues, events, donations |
| `membership_categories` | 16 professional categories |
| `member_documents` | License, CV, proof of training |
| `member_audit_notes` | Admin review comments |
| `renewal_reminders` | Sent reminder log |
| `audit_logs` | Admin action trail |

### Key `members` columns (after migration)

`user_id`, `category_id`, `membership_number`, `approval_status`, `expiry_date`, `dues_paid_at`, `public_profile`, `verified_at`, `committee`, `cpd_points`

---

## API reference (portal-related)

| Action | Auth | Description |
|--------|------|-------------|
| `register_member` | Public | Submit membership application |
| `confirm_payment` | Public (token) | Gateway success callback |
| `list_public_members` | Public | Directory listing |
| `list_membership_categories` | Public | 16 categories |
| `get_about_stats` | Public | About page counters |
| `list_members` | Admin | Full roster with derived status |
| `update_member_approval` | Admin | Approve / correct / reject |
| `verify_payment` | Admin | Manual payment verify |
| `export_csv` | Admin | Member export |

Portal backend: `portal.php` (`me`, `update_profile`, `upload_document`, `set_visibility`, …)

Auth: `auth.php` (`login`, `register_member_account`, `logout`, password reset)

---

## Phased roadmap

### Phase 0 — Quick fixes
- [x] Wire About stats to database (`get_about_stats`)
- [x] Hero discipline content via admin carousel (replaces dead `#` links)
- [x] Join form persists to database
- [x] Application confirmation email

### Phase 1 — Minimum viable member system
- [x] Public website (existing)
- [x] Join form → database
- [x] Member login + profile (`portal.html`)
- [x] Admin approval workflow
- [x] Status engine
- [x] Payment tracking (PesaPal + manual)
- [x] CSV export
- [x] Public directory (`directory.html`)
- [x] Renewal reminders (`send_renewal_reminders.php`)

### Phase 2 — Member value-add
- [x] Membership certificate PDF (`certificate.php` print-to-PDF + `verify.php` QR)
- [ ] Event registration CPD linkage (manual CPD entries supported; auto-link to events still TBD)
- [x] Committees / working groups UI (admin tab + public directory section)
- [x] Bulk email broadcast from admin (`broadcast_email_send` + audience selector)

### Phase 3 — Society maturity
- [x] CPD points accrual (`cpd_entries` table + admin award button + portal view)
- [ ] Elections / voting
- [ ] Resources library
- [ ] Member mobile app

---

## Operational guide — new modules

### Renewal reminders

Reminder cadence per member (deduped via `renewal_reminders` table):

| Kind | Trigger |
|------|---------|
| `t_minus_60` | 60 days before expiry |
| `t_minus_30` | 30 days before |
| `t_minus_7`  | 7 days before |
| `t_zero`     | day of expiry |
| `t_plus_7`   | 7 days after expiry |

Wire as a daily cron (one of):

```
# Windows Task Scheduler
php c:\xampp\htdocs\tattuintel\hosu\send_renewal_reminders.php

# Linux cron (run at 07:00)
0 7 * * * /usr/bin/php /var/www/hosu/send_renewal_reminders.php
```

Web-triggered runs require either an admin session **or** `?key=<RENEWAL_CRON_KEY>` (set in `.env`). Admins can also use **Members → Send Renewals** (with **Preview** button) to fire it manually.

### Certificates

`certificate.php` renders a print-optimised HTML A4-landscape certificate.
Members access via their portal "Open certificate" button; admins via the 🏅 icon
in the Members table. The QR code points to `verify.php`, which checks an
HMAC token derived from the membership number + email + `CERT_HMAC_KEY` env
variable — change this key in production.

### Committees & broadcast

- `Admin → Committees` tab manages working groups and rosters.
- `Admin → Broadcast` tab composes HTML email to a chosen audience:
  *active*, *all*, *pending*, *expired*, or one committee.
- Personalisation tokens supported in the body: `{{name}}`, `{{first_name}}`.

### CPD

The 🎓 button on each member row in **Admin → Members** opens a quick CPD
award dialog. The member sees their full history on their portal Overview.

---

## Governance decisions (for HOSU leadership)

These are **policy** choices the software will enforce once configured:

- Who qualifies per category (16 categories seeded)
- Who approves applications (membership officer vs committee)
- Required documents (license, CV, proof of training)
- Final dues per category (students / honorary exceptions)
- Late-joiner fee rule (roll-forward implemented; pro-rate not implemented)
- Public directory opt-in vs admin override
- Grace period before `expired`
- Suspension authority and grounds
- Fields that must never be public

---

## Final model (four sentences)

Members update their own personal information.  
Admins verify membership and credentials.  
The system calculates status and renewals.  
HOSU gets a professional society system without every update depending on the administrator.

---

*Hematology & Oncology Society of Uganda · [hosu.or.ug](https://hosu.or.ug)*
