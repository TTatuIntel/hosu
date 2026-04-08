<?php
$c = file_get_contents(__DIR__ . '/admin.html');

echo "=== Remaining >??< or >???< patterns ===\n";
preg_match_all('/>.{0,5}\?{2,3}.{0,5}</', $c, $m, PREG_OFFSET_CAPTURE);
foreach ($m[0] as $match) {
    $pos = $match[1];
    $line = substr_count(substr($c, 0, $pos), "\n") + 1;
    echo "Line $line: " . trim($match[0]) . "\n";
}

echo "\n=== U+FFFD locations ===\n";
$offset = 0;
$found = 0;
$fffd = "\xEF\xBF\xBD";
while (($p = strpos($c, $fffd, $offset)) !== false) {
    $line = substr_count(substr($c, 0, $p), "\n") + 1;
    $ctx = substr($c, max(0, $p - 40), 90);
    $ctx = str_replace(["\n", "\r"], ' ', $ctx);
    echo "Line $line: ..." . $ctx . "...\n";
    $offset = $p + 3;
    $found++;
    if ($found >= 50) break;
}
echo "\nTotal U+FFFD remaining: $found\n";
