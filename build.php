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
$skipfiles['block.asset_compile.php'] = true; // this will be used as phar stub

$srcdir = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'src'));
$outfile = 'block_asset_compile.php';
$target = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'build', 'temp.phar'));
$arch = new Phar($target, 0, 'block_asset_compile.php');
#$arch->compressFiles(Phar::GZ);
$arch->startBuffering();
$arch->buildFromIterator(new SacySupportFilesFilter(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcdir), RecursiveIteratorIterator::SELF_FIRST),
            $skipfiles
), $srcdir);
$arch->stopBuffering();
$stub ='<?php Phar::interceptFileFuncs();'.
    de_phptag(file_get_contents($_SERVER['argv'][1])).
    de_phptag(file_get_contents($srcdir.DIRECTORY_SEPARATOR.'block.asset_compile.php')).
    "\n__HALT_COMPILER();";
$arch->setStub($stub);

$arch = null;
rename($target, dirname($target).DIRECTORY_SEPARATOR.$outfile);
die(0);

function de_phptag($str){
    return preg_replace('#<\?(php)?|\?>#', '', $str);

}

class SacySupportFilesFilter extends FilterIterator{
    private $skipfiles;

    function __construct(Iterator $it, $skipfiles){
        parent::__construct($it);
        $this->skipfiles = $skipfiles ?: array();
    }

    public function accept(){
        if ($this->current()->isDir())
            return false;
        if (!preg_match('#\.(php)$#', $this->current()->getFilename()))
            return false;;
        if ($this->skipfiles[$this->current()->getFilename()])
            return false;

        return true;
    }
}
