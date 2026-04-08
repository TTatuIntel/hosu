<?php
$c = file_get_contents(__DIR__ . '/admin.html');
$fffd = "\xEF\xBF\xBD";
$p = 0;
while (($p = strpos($c, $fffd, $p)) !== false) {
    $ln = substr_count($c, "\n", 0, $p) + 1;
    $s = max(0, $p - 40);
    $ctx = str_replace(["\n","\r"], " ", substr($c, $s, 90));
    echo "Line $ln: $ctx\n";
    $p++;
}
