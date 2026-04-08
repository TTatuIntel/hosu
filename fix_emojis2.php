<?php
/**
 * Second pass: Fix remaining corrupted emojis and U+FFFD characters
 */
$file = __DIR__ . DIRECTORY_SEPARATOR . 'admin.html';
$c = file_get_contents($file);
if ($c === false) { echo "ERROR\n"; exit(1); }

$fffd = "\xEF\xBF\xBD"; // U+FFFD replacement character
$emdash = "\xE2\x80\x94"; // U+2014 em dash
$ellipsis = "\xE2\x80\xA6"; // U+2026 horizontal ellipsis
$middledot = "\xC2\xB7"; // U+00B7 middle dot
$endash = "\xE2\x80\x93"; // U+2013 en dash

$total = 0;
function fix(&$c, $search, $replace) {
    global $total;
    $n = substr_count($c, $search);
    if ($n > 0) {
        $c = str_replace($search, $replace, $c);
        $total += $n;
        echo "  [$n] " . mb_substr(str_replace(["\n","\r"], " ", $search), 0, 60) . "\n";
    }
    return $n;
}

echo "=== PASS 2: Fixing remaining issues ===\n\n";

// ================================================================
// A: Remaining >??< patterns (without JS string quotes)
// ================================================================
echo "--- JS rendered emojis (no-quote context) ---\n";

// viewRegistrants - Free badge
fix($c, '>?? Free</span>', '>🆓 Free</span>');
// viewRegistrants - Paid badge  
fix($c, '>?? UGX ', '>💰 UGX ');
// renderRegModal - Free amount
fix($c, '>?? Free</div>', '>🆓 Free</div>');
// renderRegModal - Receipt link
fix($c, '>?? Receipt</a>', '>🧾 Receipt</a>');
// renderRegModal - Verify button
fix($c, '>? Verify Payment</button>', '>✅ Verify Payment</button>');
// renderRegModal - Reject button (after verify)
fix($c, '>? Reject</button>', '>❌ Reject</button>');

// openInvoice - HOSU logo
fix($c, '>?? HOSU</div>', '>🏥 HOSU</div>');
// openInvoice - Print
fix($c, '>?? Print / Save PDF</button>', '>🖨️ Print / Save PDF</button>');
// openInvoice - Mark sent
fix($c, '>?? Mark Invoice Sent</button>', '>📧 Mark Invoice Sent</button>');

// openPayEditModal - View file
fix($c, '>?? View file', '>📎 View file');
fix($c, '>?? View uploaded proof</a>', '>📎 View uploaded proof</a>');

// Grant applications - status badges
fix($c, '>? Approved</span>', '>✅ Approved</span>');
fix($c, '>? Rejected</span>', '>❌ Rejected</span>');
fix($c, '>? Pending</span>', '>⏳ Pending</span>');

// Grant applications - Proposal button
fix($c, '>?? Proposal</button>', '>📄 Proposal</button>');

// Grant applications - Approve/Reject icon-only buttons
fix($c, "updateGappStatus('+a.id+',\\'approved\\')\">" . "?</button>",
        "updateGappStatus('+a.id+',\\'approved\\')\">" . "✅</button>");
fix($c, "updateGappStatus('+a.id+',\\'rejected\\')\">?</button>",
        "updateGappStatus('+a.id+',\\'rejected\\')\">" . "❌</button>");

// Proposal overlay header
fix($c, '>?? Proposal ' . $fffd . ' ', '>📄 Proposal ' . $emdash . ' ');

// viewRegistrants - Loading with spinner
fix($c, '>? Loading', '>⏳ Loading');

// Media copy button (the remaining >??< pattern)
// Context: title="Copy path" onclick="copyMediaPath(...)">??</button>
// Use the text after: >??</button> <button class="btn-sm btn-danger"
fix($c, '>??</button> <button class="btn-sm btn-danger" onclick="deleteMedia', '>📋</button> <button class="btn-sm btn-danger" onclick="deleteMedia');

// ================================================================
// B: U+FFFD → Ellipsis (loading text, truncation, placeholders)
// ================================================================
echo "\n--- U+FFFD → ellipsis ---\n";
fix($c, 'Loading events' . $fffd, 'Loading events' . $ellipsis);
fix($c, 'Loading members' . $fffd, 'Loading members' . $ellipsis);
fix($c, 'Loading payments' . $fffd, 'Loading payments' . $ellipsis);
fix($c, 'Loading' . $fffd, 'Loading' . $ellipsis);
fix($c, 'seconds' . $fffd, 'seconds' . $ellipsis);
fix($c, "text+='" . $fffd . "'", "text+='" . $ellipsis . "'");
fix($c, "https://" . $fffd . " (leave blank", "https://" . $ellipsis . " (leave blank");
fix($c, "Change status" . $fffd, "Change status" . $ellipsis);

// ================================================================
// C: U+FFFD → Middle dot (separators)
// ================================================================
echo "\n--- U+FFFD → middle dot ---\n";
fix($c, 'Uganda ' . $fffd . ' info@hosu.or.ug', 'Uganda ' . $middledot . ' info@hosu.or.ug');
fix($c, 'info@hosu.or.ug ' . $fffd . ' www.hosu.or.ug', 'info@hosu.or.ug ' . $middledot . ' www.hosu.or.ug');

// ================================================================
// D: U+FFFD → Em dash (separators, comments, fallbacks)
// ================================================================
echo "\n--- U+FFFD → em dash ---\n";

// Table header
fix($c, 'Expires ' . $fffd . ' Paid', 'Expires / Paid');

// JS title separators (Registrants — event, Applications — grant)
fix($c, "Registrants " . $fffd . " '", "Registrants " . $emdash . " '");
fix($c, "Applications " . $fffd . " '", "Applications " . $emdash . " '");
fix($c, "Proposal " . $fffd . " '", "Proposal " . $emdash . " '");

// Invoice fee separator
fix($c, "Fee</span><span>" . $fffd . "</span>", "Fee</span><span>" . $emdash . "</span>");

// Session expiry text
fix($c, "Session expiring</strong> " . $fffd . " <span", "Session expiring</strong> " . $emdash . " <span");

// Date range separator
fix($c, "getDate()+'" . $fffd . "'+ed", "getDate()+'" . $endash . "'+ed");
fix($c, "getDate()+' " . $fffd . " '+MONTHS", "getDate()+' " . $emdash . " '+MONTHS");

// HTML section comments
fix($c, "<!-- 1 " . $fffd . " Image -->", "<!-- 1 — Image -->");
fix($c, "<!-- 2 " . $fffd . " Core Details -->", "<!-- 2 — Core Details -->");
fix($c, "<!-- 3 " . $fffd . " Date", "<!-- 3 — Date");
fix($c, "<!-- 4 " . $fffd . " Pricing -->", "<!-- 4 — Pricing -->");
fix($c, "<!-- 5 " . $fffd . " Footer", "<!-- 5 — Footer");

// JS/CSS comments with em dash
fix($c, "AUTH " . $fffd . " removed", "AUTH " . $emdash . " removed");
fix($c, "Auth gate removed " . $fffd, "Auth gate removed " . $emdash);
fix($c, "login " . $fffd . " verify", "login " . $emdash . " verify");
fix($c, "hidden " . $fffd . " pause", "hidden " . $emdash . " pause");
fix($c, "visible " . $fffd . " check", "visible " . $emdash . " check");
fix($c, "bfcache " . $fffd . " recheck", "bfcache " . $emdash . " recheck");
fix($c, "members " . $fffd . " keep", "members " . $emdash . " keep");
fix($c, "Image " . $fffd . " show", "Image " . $emdash . " show");
fix($c, "CONTENT " . $fffd . " Stats", "CONTENT " . $emdash . " Stats");

// ================================================================
// E: Remaining U+FFFD → em dash (fallback values in JS)
// ================================================================
echo "\n--- Remaining U+FFFD fallback values ---\n";

// Payment table "valid until" and "paid date" fallbacks
fix($c, "font-size:.7rem;\">" . $fffd . "</div>", "font-size:.7rem;\">" . $emdash . "</div>");
fix($c, "substring(0,10):'" . $fffd . "'", "substring(0,10):'" . $emdash . "'");

// Grant application date fallback
fix($c, "substring(0,10):'" . $fffd . "'", "substring(0,10):'" . $emdash . "'");

// ================================================================
// WRITE BACK
// ================================================================
file_put_contents($file, $c);
echo "\n=== PASS 2 DONE! Replacements: $total ===\n";

// Verification
$remaining_qq = preg_match_all('/>[?]{2,3}</', $c, $m);
echo "Remaining '>??<' patterns: $remaining_qq\n";
$remaining_fffd = substr_count($c, $fffd);
echo "Remaining U+FFFD: $remaining_fffd\n";
