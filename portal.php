<?php
/**
 * HOSU Member Portal — backend API
 *
 * All endpoints are session-authenticated and tied to the logged-in user.
 * The member can only see/edit their own row.
 *
 * Actions:
 *   me                — full profile + status + payments + documents
 *   update_profile    — member-editable fields only
 *   set_visibility    — toggle public_profile
 *   upload_document   — license / CV / proof of training
 *   delete_document   — remove an unverified document
 *   list_payments     — own payment history
 *   request_link      — link an existing members row to the logged-in user (by email match)
 */

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/upload_helper.php';
require_once __DIR__ . '/membership_helpers.php';

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (empty($_SESSION['user_id'])) out(['error' => 'Not signed in', 'auth_required' => true], 401);

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'member';
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

/** Find this user's member row (link via user_id, fall back to email match). */
function findMyMember(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($m) return $m;

    // Auto-link by email if the user signed up with the same address used on the application
    $u = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $u->execute([$userId]);
    $email = $u->fetchColumn();
    if (!$email) return null;

    $stmt = $pdo->prepare("SELECT * FROM members WHERE email = ? AND (user_id IS NULL OR user_id = 0) LIMIT 1");
    $stmt->execute([$email]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($m) {
        $pdo->prepare("UPDATE members SET user_id = ? WHERE id = ?")->execute([$userId, $m['id']]);
        $m['user_id'] = $userId;
    }
    return $m ?: null;
}

function publicMemberShape(PDO $pdo, array $m): array {
    $catName = '';
    if (!empty($m['category_id'])) {
        $s = $pdo->prepare("SELECT name FROM membership_categories WHERE id = ?");
        $s->execute([$m['category_id']]);
        $catName = (string)$s->fetchColumn();
    }
    $derivedStatus = hosuMembershipStatus($m);
    return [
        'id'                 => (int)$m['id'],
        'membership_number'  => $m['membership_number'] ?? '',
        'full_name'          => $m['full_name'] ?? '',
        'email'              => $m['email'] ?? '',
        'phone'              => $m['phone'] ?? '',
        'country'            => $m['country'] ?? 'Uganda',
        'institution'        => $m['institution'] ?? '',
        'profession'         => $m['profession'] ?? '',
        'specialty'          => $m['specialty'] ?? '',
        'category_id'        => $m['category_id'] ? (int)$m['category_id'] : null,
        'category_name'      => $catName,
        'committee'          => $m['committee'] ?? '',
        'cpd_points'         => (int)($m['cpd_points'] ?? 0),
        'membership_type'    => $m['membership_type'] ?? '',
        'status'             => $derivedStatus,
        'raw_status'         => $m['status'] ?? 'pending',
        'approval_status'    => $m['approval_status'] ?? 'pending',
        'expiry_date'        => $m['expiry_date'],
        'dues_paid_at'       => $m['dues_paid_at'],
        'verified'           => !empty($m['verified_at']),
        'public_profile'     => !empty($m['public_profile']),
        'date_joined'        => $m['created_at'] ?? null,
    ];
}

try {
    switch ($action) {

        case 'me': {
            $m = findMyMember($pdo, $userId);
            $catList = $pdo->query("SELECT id, slug, name, discipline FROM membership_categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

            if (!$m) {
                out([
                    'logged_in'   => true,
                    'has_member'  => false,
                    'user'        => ['id' => $userId, 'email' => null],
                    'categories'  => $catList,
                ]);
            }

            // Payments
            $pStmt = $pdo->prepare("SELECT id, amount, currency, payment_method, status, payment_type,
                                           membership_period, membership_expires_at, receipt_number, receipt_token, paid_at
                                    FROM payments WHERE member_id = ? ORDER BY paid_at DESC LIMIT 50");
            $pStmt->execute([$m['id']]);

            // Documents
            $dStmt = $pdo->prepare("SELECT id, doc_type, original_name, file_size, verified, verified_at, uploaded_at
                                    FROM member_documents WHERE member_id = ? ORDER BY uploaded_at DESC");
            $dStmt->execute([$m['id']]);

            // Admin review notes visible to the member (only review-type notes)
            $nStmt = $pdo->prepare("SELECT action, note, created_at FROM member_audit_notes
                                    WHERE member_id = ? AND action IN ('needs_correction','approved','rejected','note_to_member')
                                    ORDER BY created_at DESC LIMIT 20");
            $nStmt->execute([$m['id']]);

            out([
                'logged_in'  => true,
                'has_member' => true,
                'member'     => publicMemberShape($pdo, $m),
                'payments'   => $pStmt->fetchAll(PDO::FETCH_ASSOC),
                'documents'  => $dStmt->fetchAll(PDO::FETCH_ASSOC),
                'notes'      => $nStmt->fetchAll(PDO::FETCH_ASSOC),
                'categories' => $catList,
            ]);
        }

        case 'update_profile': {
            $m = findMyMember($pdo, $userId);
            if (!$m) out(['error' => 'No membership record on file'], 404);

            // Members can edit personal fields only (Improvement Plan §5)
            $fullName    = trim($_POST['full_name']    ?? $m['full_name']);
            $phone       = trim($_POST['phone']        ?? $m['phone']);
            $country     = trim($_POST['country']      ?? $m['country']);
            $institution = trim($_POST['institution']  ?? $m['institution']);
            $specialty   = trim($_POST['specialty']    ?? $m['specialty']);

            if ($fullName === '') out(['error' => 'Name is required'], 400);
            if (strlen($fullName) > 150) $fullName = substr($fullName, 0, 150);

            $pdo->prepare("UPDATE members SET full_name=?, phone=?, country=?, institution=?, specialty=? WHERE id=?")
                ->execute([$fullName, $phone, $country, $institution, $specialty, $m['id']]);

            out(['success' => true]);
        }

        case 'set_visibility': {
            $m = findMyMember($pdo, $userId);
            if (!$m) out(['error' => 'No membership record on file'], 404);
            $visible = !empty($_POST['public_profile']) && $_POST['public_profile'] !== '0';
            $pdo->prepare("UPDATE members SET public_profile = ? WHERE id = ?")
                ->execute([$visible ? 1 : 0, $m['id']]);
            out(['success' => true, 'public_profile' => $visible]);
        }

        case 'upload_document': {
            $m = findMyMember($pdo, $userId);
            if (!$m) out(['error' => 'No membership record on file'], 404);

            $docType = trim($_POST['doc_type'] ?? 'other');
            $allowedTypes = ['license','cv','proof_of_training','id_document','other'];
            if (!in_array($docType, $allowedTypes, true)) $docType = 'other';

            if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                out(['error' => 'No file uploaded'], 400);
            }
            $saved = secureUpload($_FILES['document'], 'uploads/member_docs/', true);
            if (!$saved) out(['error' => 'Upload rejected (size/type)'], 400);

            $stmt = $pdo->prepare("INSERT INTO member_documents
                (member_id, doc_type, file_path, original_name, file_size, mime_type)
                VALUES (?,?,?,?,?,?)");
            $stmt->execute([
                $m['id'], $docType, $saved,
                substr($_FILES['document']['name'] ?? '', 0, 255),
                (int)($_FILES['document']['size'] ?? 0),
                substr($_FILES['document']['type'] ?? '', 0, 120),
            ]);
            out(['success' => true, 'document_id' => (int)$pdo->lastInsertId()]);
        }

        case 'delete_document': {
            $m = findMyMember($pdo, $userId);
            if (!$m) out(['error' => 'No membership record on file'], 404);
            $docId = (int)($_POST['document_id'] ?? 0);
            // Only allow deleting your own, and only if not yet verified
            $row = $pdo->prepare("SELECT id, file_path, verified FROM member_documents WHERE id=? AND member_id=?");
            $row->execute([$docId, $m['id']]);
            $doc = $row->fetch(PDO::FETCH_ASSOC);
            if (!$doc) out(['error' => 'Not found'], 404);
            if (!empty($doc['verified'])) out(['error' => 'Cannot remove a verified document'], 403);

            if (!empty($doc['file_path']) && is_file(__DIR__ . '/' . $doc['file_path'])) {
                @unlink(__DIR__ . '/' . $doc['file_path']);
            }
            $pdo->prepare("DELETE FROM member_documents WHERE id = ?")->execute([$docId]);
            out(['success' => true]);
        }

        case 'list_payments': {
            $m = findMyMember($pdo, $userId);
            if (!$m) out(['payments' => []]);
            $pStmt = $pdo->prepare("SELECT id, amount, currency, payment_method, status, payment_type,
                                           membership_period, membership_expires_at, receipt_number, receipt_token, paid_at
                                    FROM payments WHERE member_id = ? ORDER BY paid_at DESC");
            $pStmt->execute([$m['id']]);
            out(['payments' => $pStmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'request_link': {
            // Manual linking by email — useful if auto-link missed (case differences, etc.)
            $u = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $u->execute([$userId]);
            $email = $u->fetchColumn();
            if (!$email) out(['error' => 'No email on user account'], 400);

            $m = $pdo->prepare("SELECT id FROM members WHERE LOWER(email) = LOWER(?) AND (user_id IS NULL OR user_id = 0) LIMIT 1");
            $m->execute([$email]);
            $mid = (int)$m->fetchColumn();
            if (!$mid) out(['error' => 'No matching unlinked member found for this email'], 404);

            $pdo->prepare("UPDATE members SET user_id = ? WHERE id = ?")->execute([$userId, $mid]);
            out(['success' => true, 'member_id' => $mid]);
        }

        default:
            out(['error' => 'Unknown action'], 400);
    }
} catch (Exception $e) {
    error_log('portal.php: ' . $e->getMessage());
    out(['error' => 'Server error'], 500);
}
