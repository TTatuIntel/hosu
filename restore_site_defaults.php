<?php

/**

 * Re-insert homepage partners, CTA, footer (site_chrome), and hero slides

 * ONLY when those database rows are completely missing.

 * Safe on production — never overwrites content the admin has saved.

 *

 * Usage: php restore_site_defaults.php

 */

require __DIR__ . '/db.php';

require_once __DIR__ . '/event_helpers.php';



echo "Restore missing site defaults (partners, CTA, footer, hero carousel)\n\n";



$restored = restoreMissingSiteDefaults($pdo);

if (empty($restored)) {

    echo "Nothing to restore — all default keys already exist in homepage_settings / hero slides.\n";

    echo "If content still looks wrong, re-save it in Admin or restore from a MySQL backup.\n";

} else {

    echo "Restored: " . implode(', ', $restored) . "\n";

}



echo "Done.\n";


