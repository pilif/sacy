#!/usr/bin/env php
<?php

$args = getopt("cjh");
$pruneargv = array();
foreach ($args as $o => $v) {
  foreach ($_SERVER['argv'] as $key => $chunk) {
    $regex = '/^'. (isset($o[1]) ? '--' : '-') . $o . '/';
    if ($chunk == $v && $_SERVER['argv'][$key-1][0] == '-' || preg_match($regex, $chunk)) {
      array_push($pruneargv, $key);
    }
  }
}
while ($key = array_pop($pruneargv))
    unset($_SERVER['argv'][$key]);
$_SERVER['argv'] = array_values($_SERVER['argv']);

if ($args['h'] || !$_SERVER['argv'][1] || !is_readable($_SERVER['argv'][1])){
    fwrite(STDERR, "usage: build.php [-c] [-j] <configfile>
    -c: Include CSSMin into bundle
    -j: Include JSMin into bundle\n");
    die(1);
}

$skipfiles = array();
if (!isset($args['c'])) $skipfiles['cssmin.php'] = true;
if (!isset($args['j'])) $skipfiles['jsmin.php'] = true;

$srcdir = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'src'));
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
    if ($skipfiles[$entry->getFilename()])
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
