<?php
/**
 * Sample Data Seeder
 * Seeds events, blog posts, comments, members, and publications.
 * Run: php seed_sample_data.php  (CLI)  or visit in browser.
 */

require 'db.php';

echo "═══ HOSU Sample Data Seeder ═══\n\n";

// ── Events ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events");
$stmt->execute();
if ((int) $stmt->fetchColumn() === 0) {
    $events = [
        [
            'evt-2026-annual-conf', 'conference', 'upcoming',
            'img/event-conference.jpg', 'Annual Conference',
            '', '15–17 July 2026', '2026-07-15', '2026-07-17',
            'HOSU 7th Annual Scientific Conference',
            'Join us for three days of cutting-edge presentations on hematology and oncology advances in Uganda and East Africa. Keynote speakers from Makerere University and international partners.',
            'Kampala Serena Hotel, Uganda', 1, 'upcoming', 0, 50000
        ],
        [
            'evt-2026-blood-drive', 'outreach', 'upcoming',
            'img/event-blood.jpg', 'Blood Donation Drive',
            '', '22 May 2026', '2026-05-22', '2026-05-22',
            'National Blood Donation Drive',
            'HOSU partners with Uganda Blood Transfusion Services for a nation-wide blood collection drive. Volunteers and donors welcome.',
            'Uganda Cancer Institute, Mulago', 0, 'upcoming', 1, 0
        ],
        [
            'evt-2026-workshop-lab', 'workshop', 'upcoming',
            'img/event-workshop.jpg', 'Lab Workshop',
            '', '10 June 2026', '2026-06-10', '2026-06-10',
            'Hematology Laboratory Techniques Workshop',
            'Hands-on workshop covering flow cytometry, coagulation testing, and peripheral blood smear interpretation for laboratory professionals.',
            'Makerere University, Kampala', 0, 'upcoming', 0, 30000
        ],
        [
            'evt-2025-cme-dec', 'workshop', 'past',
            'img/event-cme.jpg', 'CME Session',
            '', '12 December 2025', '2025-12-12', '2025-12-12',
            'Continuing Medical Education: Sickle Cell Disease Updates',
            'An interactive CME session covering the latest guidelines for sickle cell disease management, hydroxyurea therapy, and gene therapy trials.',
            'Mulago Hospital, Kampala', 0, 'past', 1, 0
        ],
        [
            'evt-2025-symposium-oct', 'conference', 'past',
            'img/event-symposium.jpg', 'Oncology Symposium',
            '', '18–19 October 2025', '2025-10-18', '2025-10-19',
            'East Africa Oncology Symposium',
            'A two-day symposium bringing together oncologists from Kenya, Tanzania, Rwanda, and Uganda for collaborative discussions on cancer care in the region.',
            'Protea Hotel, Kampala', 1, 'past', 0, 40000
        ],
    ];

    $ins = $pdo->prepare("INSERT INTO events (id, type, status, image, imageAlt, countdown, date, date_start, date_end, title, description, location, featured, category, is_free, event_fee) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($events as $e) {
        $ins->execute($e);
    }
    echo "✓ 5 sample events seeded.\n";
} else {
    echo "– Events already exist, skipping.\n";
}

// ── Blog Posts ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM posts");
$stmt->execute();
if ((int) $stmt->fetchColumn() === 0) {
    $posts = [
        [
            'Advances in Sickle Cell Gene Therapy',
            '<p>Recent clinical trials have shown promising results for gene therapy approaches to sickle cell disease. CRISPR-based treatments are now entering Phase III trials, offering hope for a functional cure.</p><p>In Uganda, where sickle cell disease affects approximately 20,000 newborns annually, these advances could be transformative. HOSU members are actively involved in building the infrastructure needed to bring these therapies to East Africa.</p>',
            'Research', 'Dr. Ddungu Henry', 'uploads/default-blog.jpg', 'uploads/default-avatar.jpg'
        ],
        [
            'HOSU Annual Conference 2026 — Call for Abstracts',
            '<p>We are pleased to announce that abstract submissions are now open for the 7th HOSU Annual Scientific Conference to be held 15–17 July 2026 at the Kampala Serena Hotel.</p><p>Abstracts should be submitted via email to <strong>infor@hosu.or.ug</strong> by 30 May 2026. Categories include: Hematology, Medical Oncology, Surgical Oncology, Radiation Oncology, Pediatric Oncology, Palliative Care, and Laboratory Sciences.</p>',
            'Events', 'Dr. Odhiambo Clara', 'uploads/default-blog.jpg', 'uploads/default-avatar.jpg'
        ],
        [
            'Improving Cancer Screening in Rural Uganda',
            '<p>HOSU, in partnership with the Uganda Cancer Institute, has launched a pilot programme to improve early cancer detection in 10 rural districts. The programme focuses on cervical and breast cancer screening using low-cost, high-impact methods.</p><p>Community health workers have been trained to perform VIA (Visual Inspection with Acetic Acid) and clinical breast examinations, with referral pathways to regional hospitals.</p>',
            'Community', 'Dr. Niyonzima Nixon', 'uploads/default-blog.jpg', 'uploads/default-avatar.jpg'
        ],
        [
            'Understanding Lymphoma: A Patient Guide',
            '<p>Lymphoma is one of the most common cancers in Uganda. This guide explains the two main types — Hodgkin lymphoma and non-Hodgkin lymphoma — their symptoms, diagnosis, and treatment options available locally.</p><p>Early diagnosis significantly improves outcomes. If you experience unexplained weight loss, persistent fevers, night sweats, or swollen lymph nodes, please consult a healthcare provider.</p>',
            'Education', 'Dr. Namazzi Ruth', 'uploads/default-blog.jpg', 'uploads/default-avatar.jpg'
        ],
        [
            'World Cancer Day 2026 — HOSU Activities',
            '<p>HOSU marked World Cancer Day 2026 with a series of activities including free cancer screenings at Mulago Hospital, a public awareness walk through Kampala, and a social media campaign reaching over 50,000 people.</p><p>Thank you to all members and volunteers who participated. Together we can close the care gap.</p>',
            'General', 'Dr. Kakungulu Edward', 'uploads/default-blog.jpg', 'uploads/default-avatar.jpg'
        ],
    ];

    $ins = $pdo->prepare("INSERT INTO posts (title, content, category, author, image, avatar) VALUES (?,?,?,?,?,?)");
    foreach ($posts as $p) {
        $ins->execute($p);
    }
    echo "✓ 5 sample blog posts seeded.\n";

    // ── Comments on posts ────────────────────────────────────────────
    $comments = [
        [1, 'Dr. Ssali Francis', 'Excellent overview of the gene therapy landscape. The CRISPR trials are indeed very promising for our patient population.'],
        [1, 'Jane Akello', 'As a parent of a child with SCD, this gives me so much hope. Thank you for sharing.'],
        [2, 'Dr. Bogere Naghib', 'Looking forward to presenting our latest research on Burkitt lymphoma outcomes. Great initiative!'],
        [3, 'Mr. Moses Echodu', 'Community engagement is key. We have seen amazing uptake of screening in Lira district.'],
        [4, 'Patricia Namuli', 'This is very helpful information. I shared it with my family. Thank you HOSU.'],
        [5, 'Dr. Kibudde Solomon', 'What an incredible turnout this year. The public walk was truly inspiring.'],
    ];

    $ins = $pdo->prepare("INSERT INTO comments (post_id, author, content) VALUES (?,?,?)");
    foreach ($comments as $c) {
        $ins->execute($c);
    }
    echo "✓ 6 sample comments seeded.\n";
} else {
    echo "– Posts already exist, skipping.\n";
}

// ── Members ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM members");
$stmt->execute();
if ((int) $stmt->fetchColumn() === 0) {
    $members = [
        ['Dr. Amara Okello',   'amara.okello@example.com',   '+256701234567', 'Oncologist',       'Mulago Hospital',         'annual',   'active'],
        ['Dr. Sarah Nalubega', 'sarah.nalubega@example.com',  '+256702345678', 'Hematologist',     'Uganda Cancer Institute', 'annual',   'active'],
        ['Dr. James Okiror',   'james.okiror@example.com',    '+256703456789', 'Pathologist',      'Makerere University',     'lifetime', 'active'],
        ['Ms. Grace Atim',     'grace.atim@example.com',      '+256704567890', 'Lab Technologist', 'Lacor Hospital',          'annual',   'active'],
        ['Dr. Peter Muwanga',  'peter.muwanga@example.com',   '+256705678901', 'Surgeon',          'Nsambya Hospital',        'annual',   'pending'],
    ];

    $ins = $pdo->prepare("INSERT INTO members (full_name, email, phone, profession, institution, membership_type, status) VALUES (?,?,?,?,?,?,?)");
    foreach ($members as $m) {
        $ins->execute($m);
    }
    echo "✓ 5 sample members seeded.\n";
} else {
    echo "– Members already exist, skipping.\n";
}

// ── Publications ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM publications");
$stmt->execute();
if ((int) $stmt->fetchColumn() === 0) {
    $pubs = [
        ['Journal',  'Sickle Cell Disease in Uganda: A Comprehensive Review',                   'Ddungu H, Nalubega S, et al.',    '2025',  '', 'Read abstract', 1],
        ['Journal',  'Outcomes of Burkitt Lymphoma Treatment at Uganda Cancer Institute',        'Bogere N, Kibudde S, et al.',      '2025',  '', 'Read abstract', 2],
        ['Journal',  'Cervical Cancer Screening Uptake in Rural Uganda',                         'Niyonzima N, Odhiambo C, et al.',  '2024',  '', 'Read abstract', 3],
        ['Guideline','HOSU Clinical Practice Guidelines: Management of Chronic Myeloid Leukemia','HOSU Guidelines Committee',        '2024',  '', 'Download PDF',  4],
    ];

    $ins = $pdo->prepare("INSERT INTO publications (pub_type, title, authors, pub_date, link, link_label, sort_order) VALUES (?,?,?,?,?,?,?)");
    foreach ($pubs as $p) {
        $ins->execute($p);
    }
    echo "✓ 4 sample publications seeded.\n";
} else {
    echo "– Publications already exist, skipping.\n";
}

// ── Grants ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM grants_opportunities");
$stmt->execute();
if ((int) $stmt->fetchColumn() === 0) {
    $grants = [
        ['Research Grant: Hematology Innovation',  5000000, 'UGX', '30 June 2026', 'open',   'Funding for early-career researchers pursuing innovative hematology research in Uganda.', '', 1],
        ['Travel Fellowship: ASH Annual Meeting',  3000000, 'UGX', '15 August 2026','open',  'Support for HOSU members to attend and present at the American Society of Hematology annual meeting.', '', 2],
    ];

    $ins = $pdo->prepare("INSERT INTO grants_opportunities (title, amount, currency, deadline, status, description, apply_link, sort_order) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($grants as $g) {
        $ins->execute($g);
    }
    echo "✓ 2 sample grants seeded.\n";
} else {
    echo "– Grants already exist, skipping.\n";
}

echo "\n══════════════════════════════════════════\n";
echo "  Sample data seeding complete!\n";
echo "══════════════════════════════════════════\n";
