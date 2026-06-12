<?php
/**
 * DISABLED — this script previously deleted real homepage/footer data by mistake.
 *
 * Use instead:
 *   php restore_site_defaults.php   — fill missing partners/CTA/footer/hero only
 *   php remove_test_events.php      — remove test events only (dry run)
 *   php remove_test_events.php --confirm
 */
fwrite(STDERR, "This script is disabled.\n");
fwrite(STDERR, "Use restore_site_defaults.php or remove_test_events.php instead.\n");
exit(1);
