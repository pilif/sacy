#!/usr/bin/env php
<?php

$srcdir = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'src'));
if (!$_SERVER['argv'][1] || !is_readable($_SERVER['argv'][1])){
    fwrite(STDERR, "usage: build.php <configfile>\n");
    die(1);
}
$fh = fopen(implode(DIRECTORY_SEPARATOR, array(__DIR__, 'build', 'block_asset_compile.php')), 'w');

if (!$fh){
    fwrite(STDERR, "Unable to write build output file\n");
    die(2);
}
fwrite($fh, '<?php define("____SACY_IS_BUNDLED", 1);');
fwrite($fh, de_phptag(file_get_contents($_SERVER['argv'][1])));

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcdir), RecursiveIteratorIterator::SELF_FIRST);
foreach($it as $entry){
    if ($entry->isDir())
        continue;
    if (!preg_match('#\.(php)$#', $entry->getFilename()))
        continue;
    $r = fopen($entry->getPathname(), 'r');
    if (!$r){
        fwrite(STDERR, "Unable to read source file ".$entry->getPathname());
        continue;
    }
    while(false !== ($line = fgets($r))){
        fwrite($fh, de_phptag($line));
    }
    fclose($r);
}
fclose($fh);
die(0);

function de_phptag($str){
    return  preg_replace('#<\?(php)?|\?>#', '', $str);
}
