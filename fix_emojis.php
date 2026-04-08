<?php
/**
 * Fix corrupted emojis in admin.html
 * All emoji characters were converted to ASCII '?' or U+FFFD during git encoding.
 * This script restores the correct Unicode emoji characters.
 */

$file = __DIR__ . DIRECTORY_SEPARATOR . 'admin.html';
$c = file_get_contents($file);
if ($c === false) { echo "ERROR: Cannot read admin.html\n"; exit(1); }

// Backup
file_put_contents($file . '.bak', $c);
echo "Backup saved to admin.html.bak\n\n";

$total = 0;
function fix(&$c, $search, $replace) {
    global $total;
    $n = substr_count($c, $search);
    if ($n > 0) {
        $c = str_replace($search, $replace, $c);
        $total += $n;
    }
    return $n;
}

// ================================================================
// SECTION A: CSS
// ================================================================
fix($c, "content:'??'", "content:'🔍'");

// ================================================================
// SECTION B: TOPBAR
// ================================================================
fix($c, 'View Site ?</a>', 'View Site →</a>');
fix($c, '>?? Password</button>', '>🔐 Password</button>');

// ================================================================
// SECTION C: TABS (unique onclick context)
// ================================================================
fix($c, "showTab('overview')\">?? Overview", "showTab('overview')\">📊 Overview");
fix($c, "showTab('events')\">?? Events", "showTab('events')\">📅 Events");
fix($c, "showTab('members')\">?? Members", "showTab('members')\">👥 Members");
fix($c, "showTab('payments')\">?? Payments", "showTab('payments')\">💳 Payments");
fix($c, "showTab('research')\">?? Research", "showTab('research')\">🔬 Research");
fix($c, "showTab('leadership')\">??? Leadership", "showTab('leadership')\">👔 Leadership");
fix($c, "showTab('blog')\">?? Blog", "showTab('blog')\">📝 Blog");
fix($c, "showTab('sitecontent')\">??? Site Content", "showTab('sitecontent')\">🎨 Site Content");

// Tab badge initial values (replace U+FFFD with 0)
fix($c, 'id="tab-ev-cnt">�', 'id="tab-ev-cnt">0');
fix($c, 'id="tab-mem-cnt">�', 'id="tab-mem-cnt">0');
fix($c, 'id="tab-pay-cnt">�', 'id="tab-pay-cnt">0');
fix($c, 'id="tab-res-cnt">�', 'id="tab-res-cnt">0');
fix($c, 'id="tab-lead-cnt">�', 'id="tab-lead-cnt">0');
fix($c, 'id="tab-blog-cnt">�', 'id="tab-blog-cnt">0');

// ================================================================
// SECTION D: OVERVIEW PANEL
// ================================================================
fix($c, '<h1>?? Dashboard Overview</h1>', '<h1>📊 Dashboard Overview</h1>');

// Stat icons (using unique id context)
fix($c, 's-icon">??</div><div class="s-val" id="s-ev">', 's-icon">📅</div><div class="s-val" id="s-ev">');
fix($c, 's-icon">??</div><div class="s-val" id="s-mem">', 's-icon">👥</div><div class="s-val" id="s-mem">');
fix($c, 's-icon">?</div><div class="s-val" id="s-active">', 's-icon">✅</div><div class="s-val" id="s-active">');
fix($c, 's-icon">?</div><div class="s-val" id="s-pend">', 's-icon">⏳</div><div class="s-val" id="s-pend">');
fix($c, 's-icon">??</div><div class="s-val" id="s-col">', 's-icon">💰</div><div class="s-val" id="s-col">');
fix($c, 's-icon">??</div><div class="s-val" id="s-inv">', 's-icon">📋</div><div class="s-val" id="s-inv">');

// Stat initial values
fix($c, 'id="s-ev">�</div>', 'id="s-ev">0</div>');
fix($c, 'id="s-mem">�</div>', 'id="s-mem">0</div>');
fix($c, 'id="s-active">�</div>', 'id="s-active">0</div>');
fix($c, 'id="s-pend">�</div>', 'id="s-pend">0</div>');
fix($c, 'id="s-col">�</div>', 'id="s-col">0</div>');
fix($c, 'id="s-inv">�</div>', 'id="s-inv">0</div>');

// ================================================================
// SECTION E: EVENTS PANEL
// ================================================================
fix($c, '<h1>?? All Events</h1>', '<h1>📅 All Events</h1>');
fix($c, 'openAddEventModal()">? Add New Event</button>', 'openAddEventModal()">➕ Add New Event</button>');

// ================================================================
// SECTION F: MEMBERS PANEL
// ================================================================
fix($c, '<h1>?? Members</h1>', '<h1>👥 Members</h1>');

// ================================================================
// SECTION G: PAYMENTS PANEL
// ================================================================
fix($c, '<h1>?? Payments</h1>', '<h1>💳 Payments</h1>');

// Payment stat icons (unique by id)
fix($c, 's-icon">??</div><div class="s-val" id="ps-total">', 's-icon">💰</div><div class="s-val" id="ps-total">');
fix($c, 's-icon">??</div><div class="s-val" id="ps-mem-rev">', 's-icon">👤</div><div class="s-val" id="ps-mem-rev">');
fix($c, 's-icon">??</div><div class="s-val" id="ps-ev-rev">', 's-icon">📅</div><div class="s-val" id="ps-ev-rev">');
fix($c, 's-icon">??</div><div class="s-val" id="ps-grant-rev">', 's-icon">🎓</div><div class="s-val" id="ps-grant-rev">');
fix($c, 's-icon"></div><div class="s-val" id="ps-don-rev">', 's-icon">❤️</div><div class="s-val" id="ps-don-rev">');
fix($c, 's-icon">?</div><div class="s-val" id="ps-verified">', 's-icon">✅</div><div class="s-val" id="ps-verified">');
fix($c, 's-icon">?</div><div class="s-val" id="ps-pending">', 's-icon">⏳</div><div class="s-val" id="ps-pending">');

// ================================================================
// SECTION H: RESEARCH PANEL
// ================================================================
fix($c, '<h1 class="ph-title-sm">?? Research</h1>', '<h1 class="ph-title-sm">🔬 Research</h1>');
fix($c, '>?? Export Report</a>', '>📊 Export Report</a>');

// Research stat icons
fix($c, 's-icon">??</div><div class="s-val" id="rs-pubs">', 's-icon">📄</div><div class="s-val" id="rs-pubs">');
fix($c, 's-icon">??</div><div class="s-val" id="rs-grants">', 's-icon">🎓</div><div class="s-val" id="rs-grants">');
fix($c, 's-icon">?</div><div class="s-val" id="rs-open">', 's-icon">✅</div><div class="s-val" id="rs-open">');
fix($c, 's-icon">??</div><div class="s-val" id="rs-apps">', 's-icon">📨</div><div class="s-val" id="rs-apps">');
fix($c, 's-icon">?</div><div class="s-val" id="rs-pending">', 's-icon">⏳</div><div class="s-val" id="rs-pending">');
fix($c, 's-icon">??</div><div class="s-val" id="rs-revenue">', 's-icon">💰</div><div class="s-val" id="rs-revenue">');

// Sub-section headers
fix($c, '<h2>?? Publications', '<h2>📄 Publications');
fix($c, '<h2>?? Grants', '<h2>🎓 Grants');

// ================================================================
// SECTION I: LEADERSHIP PANEL
// ================================================================
fix($c, '<h1>??? Leadership Biographies</h1>', '<h1>👔 Leadership Biographies</h1>');
fix($c, 's-icon">??</div><div class="s-val" id="ld-total">', 's-icon">👥</div><div class="s-val" id="ld-total">');
fix($c, 's-icon">?</div><div class="s-val" id="ld-active">', 's-icon">✅</div><div class="s-val" id="ld-active">');
fix($c, 's-icon">??</div><div class="s-val" id="ld-with-bio">', 's-icon">📝</div><div class="s-val" id="ld-with-bio">');

// ================================================================
// SECTION J: BLOG PANEL
// ================================================================
fix($c, '<h1>?? Blog Posts</h1>', '<h1>📝 Blog Posts</h1>');
fix($c, 's-icon">??</div><div class="s-val" id="bp-total">', 's-icon">📝</div><div class="s-val" id="bp-total">');
fix($c, 's-icon">??</div><div class="s-val" id="bp-cats">', 's-icon">📂</div><div class="s-val" id="bp-cats">');
fix($c, 's-icon">??</div><div class="s-val" id="bp-comments">', 's-icon">💬</div><div class="s-val" id="bp-comments">');
fix($c, '<h2>?? Comments', '<h2>💬 Comments');

// ================================================================
// SECTION K: SITE CONTENT PANEL
// ================================================================
fix($c, '<h1>??? Site Content</h1>', '<h1>🎨 Site Content</h1>');
fix($c, '<h2>?? Site Stats', '<h2>📊 Site Stats');
fix($c, '<h2>??? Media Gallery', '<h2>🖼️ Media Gallery');

// ================================================================
// SECTION L: MODAL TITLES (HTML)
// ================================================================
// Blog modal
fix($c, 'id="bp-form-title">? New Blog Post</h3>', 'id="bp-form-title">📝 New Blog Post</h3>');
// Leader modal
fix($c, 'id="ld-form-title">? Add Leader</h3>', 'id="ld-form-title">👤 Add Leader</h3>');
// Stat modal
fix($c, 'id="sc-stat-form-title">? Add Stat</h3>', 'id="sc-stat-form-title">📊 Add Stat</h3>');
// Media upload modal
fix($c, '<h3>?? Upload Media</h3>', '<h3>📤 Upload Media</h3>');
// Event modal
fix($c, 'id="ev-form-title">? Add New Event</h3>', 'id="ev-form-title">📅 Add New Event</h3>');
// Payment edit modal
fix($c, '<h3>?? Edit Payment</h3>', '<h3>💳 Edit Payment</h3>');
// Invoice modal
fix($c, '<h3>?? Invoice Preview</h3>', '<h3>📋 Invoice Preview</h3>');
// Registrants modal
fix($c, 'id="reg-modal-title">?? Registrants</h3>', 'id="reg-modal-title">👥 Registrants</h3>');
// Publication modal
fix($c, 'id="pub-form-title">? Add Publication</h3>', 'id="pub-form-title">📄 Add Publication</h3>');
// Grant modal
fix($c, 'id="grant-form-title">? Add Grant</h3>', 'id="grant-form-title">🎓 Add Grant</h3>');
// Grant applications modal
fix($c, 'id="gapp-modal-title">?? Grant Applications</h3>', 'id="gapp-modal-title">📨 Grant Applications</h3>');
// Change password modal
fix($c, '<h3>?? Change Password</h3>', '<h3>🔐 Change Password</h3>');

// ================================================================
// SECTION M: MODAL CLOSE BUTTONS (replace � with ✕)
// ================================================================
fix($c, "closeModal('blog-modal')\">�</button>", "closeModal('blog-modal')\">✕</button>");
fix($c, "closeModal('stat-modal')\">�</button>", "closeModal('stat-modal')\">✕</button>");
fix($c, "closeModal('media-upload-modal')\">�</button>", "closeModal('media-upload-modal')\">✕</button>");
fix($c, "closeModal('event-modal')\">�</button>", "closeModal('event-modal')\">✕</button>");
fix($c, "closeModal('pay-edit-modal')\">�</button>", "closeModal('pay-edit-modal')\">✕</button>");
fix($c, "closeModal('inv-modal')\">�</button>", "closeModal('inv-modal')\">✕</button>");
fix($c, "closeModal('reg-modal')\">�</button>", "closeModal('reg-modal')\">✕</button>");
fix($c, "closeModal('pub-modal')\">�</button>", "closeModal('pub-modal')\">✕</button>");
fix($c, "closeModal('grant-modal')\">�</button>", "closeModal('grant-modal')\">✕</button>");
fix($c, "closeModal('gapp-modal')\">�</button>", "closeModal('gapp-modal')\">✕</button>");
fix($c, 'closeChangePassword()">?</button>', 'closeChangePassword()">✕</button>');
fix($c, 'closeLeaderModal()">?</button>', 'closeLeaderModal()">✕</button>');

// ================================================================
// SECTION N: UPLOAD ZONES & IMAGE ACTIONS (HTML)
// ================================================================
// Blog post image upload
fix($c, 'id="bp-upload">', 'id="bp-upload">');  // no change needed - just context
fix($c, '<span class="upload-icon">??</span><p class="upload-text"><strong>Post image</strong>', '<span class="upload-icon">📷</span><p class="upload-text"><strong>Post image</strong>');
fix($c, '<span class="upload-icon">??</span><p class="upload-text"><strong>Avatar</strong>', '<span class="upload-icon">📷</span><p class="upload-text"><strong>Avatar</strong>');
// Blog remove buttons
fix($c, 'onclick="clearBlogImg()">? Remove</span>', 'onclick="clearBlogImg()">❌ Remove</span>');
fix($c, 'onclick="clearBlogAvatar()">? Remove</span>', 'onclick="clearBlogAvatar()">❌ Remove</span>');
// Blog publish button
fix($c, 'onclick="saveBlogPost()">?? Publish Post</button>', 'onclick="saveBlogPost()">💾 Publish Post</button>');

// Leader photo placeholder
fix($c, '<span>??</span>No photo', '<span>👤</span>No photo');
// Leader photo remove
fix($c, 'onclick="removeLeaderPhoto()">?</button>', 'onclick="removeLeaderPhoto()">✕</button>');
// Leader upload icon
fix($c, '<div class="ld-upload-icon">??</div>', '<div class="ld-upload-icon">📷</div>');
// Leader or divider
fix($c, '>� or paste URL �</div>', '>— or paste URL —</div>');
// Leader status
fix($c, 'onclick="setLeaderStatus(1)">? Active</div>', 'onclick="setLeaderStatus(1)">✅ Active</div>');
fix($c, 'onclick="setLeaderStatus(0)">? Inactive</div>', 'onclick="setLeaderStatus(0)">❌ Inactive</div>');
// Leader save button
fix($c, 'id="ld-save-text">?? Save Leader</span>', 'id="ld-save-text">💾 Save Leader</span>');

// Stat modal options
fix($c, '>Yes � Visible on site</option>', '>Yes ✅ Visible on site</option>');
fix($c, '>No � Hidden</option>', '>No ❌ Hidden</option>');
// Stat save button
fix($c, 'id="sc-stat-save-text">?? Save Stat</span>', 'id="sc-stat-save-text">💾 Save Stat</span>');

// Media upload card icon
fix($c, '<div class="upload-card-icon">??</div>', '<div class="upload-card-icon">📷</div>');
// Media upload note
fix($c, 'JPG, PNG, WebP, GIF, PDF � max 10 MB each', 'JPG, PNG, WebP, GIF, PDF · max 10 MB each');
// Media upload button
fix($c, 'id="sc-media-upload-text">?? Upload</span>', 'id="sc-media-upload-text">📤 Upload</span>');

// Event modal sections
fix($c, '>?? Event Image</div>', '>📷 Event Image</div>');
// Event upload icon
fix($c, '<span class="upload-icon">??</span>', '<span class="upload-icon">📷</span>');
// Event image URL placeholder
fix($c, "https://�/image.jpg", "https://…/image.jpg");
// Event remove image
fix($c, 'onclick="clearEvImg()">? Remove</span>', 'onclick="clearEvImg()">❌ Remove</span>');
// Event details section
fix($c, '>?? Event Details</div>', '>📋 Event Details</div>');
// Event select placeholder
fix($c, '>Select�</option>', '>Select…</option>');
// Event date & location section
fix($c, '>?? Date &amp; Location</div>', '>📍 Date &amp; Location</div>');
// Event location placeholder
fix($c, '?? e.g. Serena Hotel', '📍 e.g. Serena Hotel');
// Event description placeholder
fix($c, 'Describe the event � agenda, speakers, objectives�', 'Describe the event — agenda, speakers, objectives…');
// Event pricing section
fix($c, '>?? Pricing</div>', '>💰 Pricing</div>');
// Event free toggle
fix($c, '>?? Free Event</span>', '>🆓 Free Event</span>');
// Event show on home
fix($c, '>?? Show on Home</span></div>', '>🏠 Show on Home</span></div>');
// Event save button
fix($c, 'id="ev-save-btn" onclick="saveEvent()">?? Save Event</button>', 'id="ev-save-btn" onclick="saveEvent()">💾 Save Event</button>');
// Event cancel button
fix($c, "onclick=\"closeModal('event-modal')\">? Cancel</button>", "onclick=\"closeModal('event-modal')\">✕ Cancel</button>");

// Payment edit save
fix($c, 'onclick="savePaymentEdit()">?? Save Changes</button>', 'onclick="savePaymentEdit()">💾 Save Changes</button>');

// Registrants search
fix($c, 'placeholder="Search registrants�"', 'placeholder="Search registrants…"');
// Registrants empty state
fix($c, '<div class="empty-icon">??</div>', '<div class="empty-icon">📋</div>');

// Publication modal sections
fix($c, '>?? Source</div>', '>📂 Source</div>');
fix($c, '>� Choose an event �</option>', '>— Choose an event —</option>');
fix($c, '>?? Publication Details</div>', '>📋 Publication Details</div>');

// Grant modal section
fix($c, '>?? Grant Details</div>', '>📋 Grant Details</div>');

// Publication show on home (in pub modal)
fix($c, 'id="pub-show-home"><span class="tog-slider"></span></label><span class="tog-lbl tog-lbl-xs">?? Show on Home</span>', 'id="pub-show-home"><span class="tog-slider"></span></label><span class="tog-lbl tog-lbl-xs">🏠 Show on Home</span>');
// Publication save button
fix($c, 'id="pub-save-btn" onclick="savePub()">?? Save</button>', 'id="pub-save-btn" onclick="savePub()">💾 Save</button>');
// Publication cancel
fix($c, "onclick=\"closeModal('pub-modal')\">? Cancel</button>", "onclick=\"closeModal('pub-modal')\">✕ Cancel</button>");

// Grant show on home
fix($c, 'id="grant-show-home"><span class="tog-slider"></span></label><span class="tog-lbl tog-lbl-xs">?? Show on Home</span>', 'id="grant-show-home"><span class="tog-slider"></span></label><span class="tog-lbl tog-lbl-xs">🏠 Show on Home</span>');
// Grant save button
fix($c, 'id="grant-save-btn" onclick="saveGrant()">?? Save</button>', 'id="grant-save-btn" onclick="saveGrant()">💾 Save</button>');
// Grant cancel
fix($c, "onclick=\"closeModal('grant-modal')\">? Cancel</button>", "onclick=\"closeModal('grant-modal')\">✕ Cancel</button>");

// Grant applications search
fix($c, 'placeholder="Search applicants�"', 'placeholder="Search applicants…"');
// Grant applications filter options
fix($c, '>? Pending</option><option value="approved">? Approved</option><option value="rejected">? Rejected</option>', '>⏳ Pending</option><option value="approved">✅ Approved</option><option value="rejected">❌ Rejected</option>');
// Grant export
fix($c, '>? Export CSV</a>', '>📥 Export CSV</a>');
// Grant applications empty
fix($c, '<div class="gapp-empty-icon">??</div>', '<div class="gapp-empty-icon">📋</div>');

// Change password view login history
fix($c, '>?? View Login History</button>', '>📋 View Login History</button>');

// ================================================================
// SECTION O: MEMBER/PAYMENT FILTER OPTIONS (HTML)
// ================================================================
// Member status filter options (need careful context)
fix($c, 'id="mem-status-filter" class="btn-sm select-sm-pad" onchange="filterMembers()">
                        <option value="">All Statuses</option>
                        <option value="active">? Active</option>
                        <option value="pending">? Pending</option>
                        <option value="expired">?? Expired</option>
                        <option value="rejected">? Rejected</option>',
     'id="mem-status-filter" class="btn-sm select-sm-pad" onchange="filterMembers()">
                        <option value="">All Statuses</option>
                        <option value="active">✅ Active</option>
                        <option value="pending">⏳ Pending</option>
                        <option value="expired">⌛ Expired</option>
                        <option value="rejected">❌ Rejected</option>');

// Payment status filter
fix($c, 'id="pay-status-filter" class="btn-sm select-sm-pad" onchange="filterPayments()">
                        <option value="">All Statuses</option>
                        <option value="verified">? Verified</option>
                        <option value="pending">? Pending</option>
                        <option value="rejected">? Rejected</option>',
     'id="pay-status-filter" class="btn-sm select-sm-pad" onchange="filterPayments()">
                        <option value="">All Statuses</option>
                        <option value="verified">✅ Verified</option>
                        <option value="pending">⏳ Pending</option>
                        <option value="rejected">❌ Rejected</option>');

// Export CSV buttons
fix($c, 'id="csv-btn">? Export CSV</a>', 'id="csv-btn">📥 Export CSV</a>');
fix($c, '>? Export CSV</a>', '>📥 Export CSV</a>');

// ================================================================
// SECTION P: HTML EMPTY STATES (initial loading)
// ================================================================
fix($c, '<div class="big">??</div>Loading events', '<div class="big">📅</div>Loading events');
fix($c, '<div class="big">??</div>Loading members', '<div class="big">👥</div>Loading members');
fix($c, '<div class="big">??</div>Loading payments', '<div class="big">💳</div>Loading payments');
fix($c, '<div class="big">??</div>No publications yet', '<div class="big">📄</div>No publications yet');
fix($c, '<div class="big">??</div>No grants yet', '<div class="big">🎓</div>No grants yet');
fix($c, '<div class="big">??</div>No posts yet.', '<div class="big">📝</div>No posts yet.');
fix($c, '<div class="big">??</div>No comments yet.', '<div class="big">💬</div>No comments yet.');

// ================================================================
// SECTION Q: GENERAL PATTERN - Loading/Search ellipsis
// ================================================================
// Replace all Loading… patterns (U+FFFD -> ellipsis)
fix($c, 'Loading�', 'Loading…');
// Search placeholders
fix($c, 'Search events�', 'Search events…');
fix($c, 'Search members�', 'Search members…');
fix($c, 'Search�', 'Search…');
// Generic link placeholder
fix($c, "placeholder=\"https://�\"", "placeholder=\"https://…\"");
// Grant description placeholder
fix($c, 'description of the grant�', 'description of the grant…');
// Publication link placeholders are already done via generic https://…

// ================================================================
// SECTION R: JS - RENDER EVENTS
// ================================================================
fix($c, '>?? Edit</button>', '>✏️ Edit</button>');
fix($c, '>?? Del</button>', '>🗑️ Del</button>');

// Events empty state in JS
fix($c, "class=\"big\">??</div>No events found.", "class=\"big\">📋</div>No events found.");

// Home badge in events
fix($c, "title=\"Shown on Home Hero\">??</span>", "title=\"Shown on Home Hero\">🏠</span>");

// ================================================================
// SECTION S: JS - RENDER MEMBERS
// ================================================================
fix($c, "class=\"big\">??</div>No members found.", "class=\"big\">👥</div>No members found.");

// Member status options in JS render
fix($c, "value=\"active\">? Active</option><option value=\"pending\">? Pending</option><option value=\"expired\">?? Expired</option><option value=\"rejected\">? Rejected</option>",
     "value=\"active\">✅ Active</option><option value=\"pending\">⏳ Pending</option><option value=\"expired\">⌛ Expired</option><option value=\"rejected\">❌ Rejected</option>");

// Member payment button
fix($c, '>?? Payment</button>', '>💳 Payment</button>');

// ================================================================
// SECTION T: JS - PAY TYPE LABELS
// ================================================================
fix($c, "'membership':'?? Membership'", "'membership':'💳 Membership'");
fix($c, "'donation':'?? Donation'", "'donation':'❤️ Donation'");
fix($c, "'event_registration':'?? Event'", "'event_registration':'🎫 Event'");
fix($c, "'grant_application':'?? Grant'", "'grant_application':'🎓 Grant'");

// ================================================================
// SECTION U: JS - RENDER PAYMENTS
// ================================================================
fix($c, "class=\"big\">??</div>No payments found.", "class=\"big\">💳</div>No payments found.");

// Payment buttons with title context
fix($c, "title=\"View Invoice\">??</button>", "title=\"View Invoice\">📋</button>");
fix($c, "title=\"View Receipt\">??</a>", "title=\"View Receipt\">🧾</a>");
fix($c, "title=\"Verify\">?</button>", "title=\"Verify\">✅</button>");
fix($c, "title=\"Reject\">?</button>", "title=\"Reject\">❌</button>");
fix($c, "title=\"Edit Transaction ID / Upload Proof\">??</button>", "title=\"Edit Transaction ID / Upload Proof\">✏️</button>");

// Sync buttons
fix($c, '>?? Sync</button>', '>🔄 Sync</button>');

// ================================================================
// SECTION V: JS - SYNC PESAPAL FUNCTIONS
// ================================================================
fix($c, "btn.textContent='?? Sync'", "btn.textContent='🔄 Sync'");
fix($c, "toast('??? Verified!", "toast('✅ Verified!");
fix($c, "toast('?? '", "toast('❌ '");
fix($c, "toast('? Still pending", "toast('⏳ Still pending");

// ================================================================
// SECTION W: JS - SAVE EVENT
// ================================================================
fix($c, "btn.textContent='? Saving�'", "btn.textContent='⏳ Saving…'");
fix($c, "toast('? Event updated!'", "toast('✅ Event updated!'");
fix($c, "btn.textContent='?? Save Changes'", "btn.textContent='💾 Save Changes'");
fix($c, "toast('? Event saved!'", "toast('✅ Event saved!'");
fix($c, "btn.textContent='?? Save Event'", "btn.textContent='💾 Save Event'");

// ================================================================
// SECTION X: JS - OPEN EVENT MODALS
// ================================================================
fix($c, ".textContent='? Add New Event'", ".textContent='📅 Add New Event'");
fix($c, ".textContent='?? Save Event'", ".textContent='💾 Save Event'");
fix($c, ".textContent='?? Edit Event'", ".textContent='✏️ Edit Event'");
// .textContent='?? Save Changes' already handled above

// ================================================================
// SECTION Y: JS - VIEWREGISTRANTS
// ================================================================
fix($c, "'?? Free</span>'", "'🆓 Free</span>'");
fix($c, "'?? UGX '", "'💰 UGX '");
fix($c, ".innerHTML='?? Registrants", ".innerHTML='👥 Registrants");
fix($c, "'>? Loading�</td>'", "'>⏳ Loading…</td>'");

// Registrants rendering
fix($c, "'?? Free</div>'", "'🆓 Free</div>'");
fix($c, "'?? Receipt</a>'", "'🧾 Receipt</a>'");
fix($c, "'>? Verify Payment</button>'", "'>✅ Verify Payment</button>'");
fix($c, "'>? Reject</button>'", "'>❌ Reject</button>'");
// Delete registrant button
fix($c, "title=\"Remove registrant\">??</button>", "title=\"Remove registrant\">🗑️</button>");

// ================================================================
// SECTION Z: JS - PUBLICATIONS & GRANTS RENDER
// ================================================================
fix($c, "class=\"big\">??</div>No publications yet", "class=\"big\">📄</div>No publications yet");
fix($c, "class=\"big\">??</div>No grants found.", "class=\"big\">🎓</div>No grants found.");

// Publication/grant buttons with title context
fix($c, "title=\"View link\">??</a>", "title=\"View link\">🔗</a>");
fix($c, "title=\"Edit\">??</button>", "title=\"Edit\">✏️</button>");
fix($c, "title=\"Delete\">??</button>", "title=\"Delete\">🗑️</button>");

// ================================================================
// SECTION AA: JS - PUB/GRANT MODALS
// ================================================================
fix($c, ".textContent='? Add Publication'", ".textContent='📄 Add Publication'");
fix($c, ".textContent='?? Save'", ".textContent='💾 Save'");
fix($c, ".textContent='?? Edit Publication'", ".textContent='✏️ Edit Publication'");
fix($c, ".textContent='? Add Grant'", ".textContent='🎓 Add Grant'");
fix($c, ".textContent='?? Edit Grant'", ".textContent='✏️ Edit Grant'");

// ================================================================
// SECTION BB: JS - GRANT APPLICATIONS
// ================================================================
fix($c, ".innerHTML='?? Applications", ".innerHTML='📨 Applications");
// Grant app status badges
fix($c, "'>? Approved</span>'", "'>✅ Approved</span>'");
fix($c, "'>? Rejected</span>'", "'>❌ Rejected</span>'");
fix($c, "'>? Pending</span>'", "'>⏳ Pending</span>'");
fix($c, "'>?? Proposal</button>'", "'>📄 Proposal</button>'");
// Approve/reject buttons in grant apps
fix($c, "onclick=\"updateGappStatus('+a.id+',\\'approved\\')\">?</button>'", "onclick=\"updateGappStatus('+a.id+',\\'approved\\')\">✅</button>'");
fix($c, "onclick=\"updateGappStatus('+a.id+',\\'rejected\\')\">?</button>'", "onclick=\"updateGappStatus('+a.id+',\\'rejected\\')\">❌</button>'");
// Grant app delete
fix($c, "title=\"Remove application\">??</button>", "title=\"Remove application\">🗑️</button>");
// Proposal overlay title
fix($c, "'?? Proposal", "'📄 Proposal");

// ================================================================
// SECTION CC: JS - LEADERS
// ================================================================
// Leader no-photo placeholder in JS
fix($c, "font-size:.9rem;\">??</span>'", "font-size:.9rem;\">👤</span>'");
// Leader edit/delete in table
fix($c, "editLeader('+l.id+')\">??</button>", "editLeader('+l.id+')\">✏️</button>");
// Leader delete (3 question marks)
fix($c, "deleteLeader('+l.id+')\">???</button>", "deleteLeader('+l.id+')\">🗑️</button>");
// Leader modal JS titles
fix($c, ".textContent=ld?'?? Edit Leader':'? Add Leader'", ".textContent=ld?'✏️ Edit Leader':'👤 Add Leader'");
fix($c, ".textContent=ld?'?? Update Leader':'?? Save Leader'", ".textContent=ld?'💾 Update Leader':'💾 Save Leader'");
// Leader save spinner
fix($c, ".textContent='? Saving...'", ".textContent='⏳ Saving...'");
// Leader save success/error text resets
fix($c, ".textContent=id?'?? Update Leader':'?? Save Leader'", ".textContent=id?'💾 Update Leader':'💾 Save Leader'");

// ================================================================
// SECTION DD: JS - BLOG
// ================================================================
fix($c, "class=\"big\">??</div>No posts found.", "class=\"big\">📝</div>No posts found.");
// Blog edit/delete buttons in render
fix($c, "editBlogPost('+p.id+')\">?? Edit</button>", "editBlogPost('+p.id+')\">✏️ Edit</button>");
fix($c, "deleteBlogPost('+p.id+')\">???</button>", "deleteBlogPost('+p.id+')\">🗑️</button>");
// Blog modal JS titles
fix($c, ".textContent=post?'?? Edit Post':'? New Blog Post'", ".textContent=post?'✏️ Edit Post':'📝 New Blog Post'");

// ================================================================
// SECTION EE: JS - COMMENTS
// ================================================================
fix($c, "class=\"big\">??</div>No comments found.", "class=\"big\">💬</div>No comments found.");
fix($c, "deleteAdminComment('+c.id+')\">??? Delete</button>", "deleteAdminComment('+c.id+')\">🗑️ Delete</button>");

// ================================================================
// SECTION FF: JS - SITE STATS
// ================================================================
fix($c, "class=\"big\">??</div>No stats found.", "class=\"big\">📊</div>No stats found.");
// Stats active/inactive indicators
fix($c, "?':'?'", "✅':'❌'");
// Stats edit/delete buttons
fix($c, "editStat('+s.id+')\">??</button>", "editStat('+s.id+')\">✏️</button>");
fix($c, "deleteStat('+s.id+')\">???</button>", "deleteStat('+s.id+')\">🗑️</button>");
// Stat modal JS titles
fix($c, ".textContent=st?'?? Edit Stat':'? Add Stat'", ".textContent=st?'✏️ Edit Stat':'📊 Add Stat'");
fix($c, ".textContent=st?'?? Update Stat':'?? Save Stat'", ".textContent=st?'💾 Update Stat':'💾 Save Stat'");

// ================================================================
// SECTION GG: JS - MEDIA
// ================================================================
fix($c, "class=\"big\">???</div>No media uploaded yet.", "class=\"big\">🖼️</div>No media uploaded yet.");
// Non-image file icon
fix($c, "font-size:2rem;\">??</div>", "font-size:2rem;\">📄</div>");
// Media copy button
fix($c, "title=\"Copy path\" onclick=\"copyMediaPath", "title=\"Copy path\" onclick=\"copyMediaPath");  // context only
fix($c, "copyMediaPath(\\''+esc(m.file_path)+'\\')\"", "copyMediaPath(\\''+esc(m.file_path)+'\\')\"");  // no change
// Actually use the class+onclick as context for copy:
fix($c, "title=\"Copy path\"", "title=\"Copy path\"");  // already unique - remove this dummy
// Let me handle media copy icon differently - use preceding title
// The copy button pattern: ...title="Copy path" onclick="copyMediaPath(...)">??</button>
// The media delete: ...onclick="deleteMedia('+m.id+')">???</button>
fix($c, "deleteMedia('+m.id+')\">???</button>", "deleteMedia('+m.id+')\">🗑️</button>");

// Media upload JS text resets
fix($c, ".textContent='?? Upload'", ".textContent='📤 Upload'");

// Media file preview remove buttons
fix($c, "cursor:pointer;\">?</button>", "cursor:pointer;\">✕</button>");
// Non-image file icon in preview
fix($c, "font-size:1.5rem;position:relative;\">??<button", "font-size:1.5rem;position:relative;\">📄<button");

// ================================================================
// SECTION HH: JS - INVOICE
// ================================================================
fix($c, "'?? HOSU'", "'🏥 HOSU'");
fix($c, "'?? Print / Save PDF</button>'", "'🖨️ Print / Save PDF</button>'");
fix($c, "'?? Mark Invoice Sent</button>'", "'📧 Mark Invoice Sent</button>'");

// ================================================================
// SECTION II: JS - PAYMENT EDIT MODAL
// ================================================================
fix($c, "'?? View file'", "'📎 View file'");
fix($c, "'?? View uploaded proof</a>'", "'📎 View uploaded proof</a>'");
fix($c, "'>? Verify Payment</button>'", "'>✅ Verify Payment</button>'");
fix($c, "'>? Reject</button>'", "'>❌ Reject</button>'");

// ================================================================
// SECTION JJ: JS - SYNC ALL PENDING
// ================================================================
fix($c, "btn.textContent='🔄 Sync Pending'", "btn.textContent='🔄 Sync Pending'");  // already OK (&#x1F504; entity was used)

// ================================================================
// SECTION KK: JS - RESEARCH STATS FALLBACK
// ================================================================
fix($c, ".textContent='�'", ".textContent='—'");

// ================================================================
// SECTION LL: REMAINING FALLBACK VALUES (em dash for missing data)
// ================================================================
// Profession/date fallback values in JS: ||'—')
fix($c, "||'�')", "||'—')");
// Separator dots in payment edit modal
fix($c, "' &nbsp;�&nbsp; '", "' &nbsp;·&nbsp; '");
fix($c, "' &nbsp;�&nbsp; <strong>'", "' &nbsp;·&nbsp; <strong>'");

// ================================================================
// SECTION MM: REMAINING GENERIC PATTERNS
// ================================================================
// JPG hint with middle dot
fix($c, 'JPG, PNG, WebP � max 5 MB', 'JPG, PNG, WebP · max 5 MB');
// Remaining "Choose" with em dashes
fix($c, '>� Choose', '>— Choose');

// ================================================================
// SECTION NN: REMAINING MISC CLOSE BUTTONS IN JS
// ================================================================
// Proposal overlay close button
fix($c, "cursor:pointer;color:var(--text-light);\">�</button>", "cursor:pointer;color:var(--text-light);\">✕</button>");

// Copy media path icon - use unique context
// Pattern: ...title="Copy path" onclick="copyMediaPath(\''+esc(m.file_path)+'\')">??</button>
// The context before >?? is: +'\')">'
// Since deleteMedia has >??? and this has >??, they're distinct.
// But >??</button> also appears for editStat and editLeader (already handled above)
// For media copy, the unique context is "copyMediaPath"
// Let me check if there are remaining >??</button> patterns...
// At this point most >??</button> should be handled individually via context.
// Any remaining >??</button> near "Copy path" title:

// ================================================================
// WRITE BACK
// ================================================================
$written = file_put_contents($file, $c);
if ($written === false) {
    echo "ERROR: Failed to write admin.html\n";
    exit(1);
}

echo "\n=== DONE! Total replacements: $total ===\n";
echo "File written: " . number_format($written) . " bytes\n\n";

// Verification
$remaining = preg_match_all('/>[?]{2,3}</', $c, $matches);
echo "Remaining '>??<' or '>???<' patterns: $remaining\n";

// Check for remaining ? in common emoji contexts
$qInIcon = substr_count($c, 's-icon">?</div>') + substr_count($c, 's-icon">??</div>');
echo "Remaining stat icon '?': $qInIcon\n";

$qInBig = substr_count($c, '"big">??</div>') + substr_count($c, '"big">???</div>');
echo "Remaining empty state '??' in big div: $qInBig\n";

$fffd = substr_count($c, "\xEF\xBF\xBD");
echo "Remaining U+FFFD characters: $fffd\n";

echo "\nDone. Review admin.html to verify.\n";
