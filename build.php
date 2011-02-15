#!/usr/bin/env php
<?php

$args = getopt("cjh", array('with-phamlp::', 'with-lessphp::'));
if ($args){
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
}
if (!$args || $args['h'] || !$_SERVER['argv'][1] || !is_readable($_SERVER['argv'][1])){
    fwrite(STDERR, "usage: build.php [-c] [-j] [--with-PACKAGE=<path>] <configfile>
    -c: Include CSSMin into bundle
    -j: Include JSMin into bundle

    Packages are optional additional packages to include support for. If you
    don't provide them, sacy will try using existing classes at runtime or
    leave corresponding tags alone

    --with-phamlp=  path to unpacked source bundle of phamlp for
                    text/sass and text/scss support
    --with-lessphp= path to unpacked source bundle of lessphp for text/less
                    support\n");
    die(1);
}

$skipfiles = array();
if (!isset($args['c'])) $skipfiles['cssmin.php'] = true;
if (!isset($args['j'])) $skipfiles['jsmin.php'] = true;
$skipfiles['block.asset_compile.php'] = true; // this will be used as phar stub

$srcdir = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'src'));
$outfile = 'block.asset_compile.php';
$target = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'build', 'temp.phar'));
$arch = new Phar($target, 0, 'sacy.phar');
#$arch->compressFiles(Phar::GZ);
$arch->startBuffering();
$arch->buildFromIterator(new SacySupportFilesFilter(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcdir), RecursiveIteratorIterator::SELF_FIRST),
            $skipfiles
), $srcdir);
if ($args['with-phamlp']){
    $arch->buildFromIterator(new SacySupportFilesFilter(new RecursiveIteratorIterator(
        new SacySkipSubdirsFilter(
            new RecursiveDirectoryIterator($args['with-phamlp']),
            array('haml')
        ),
        RecursiveIteratorIterator::SELF_FIRST
    )), $args['with-phamlp']);
}
if ($args['with-lessphp']){
    $arch['sacy/lessc.inc.php'] = file_get_contents(
        implode(DIRECTORY_SEPARATOR, array($args['with-lessphp'], 'lessc.inc.php'))
    );
}

$arch->stopBuffering();
$stub ='<?php Phar::interceptFileFuncs();
    define("____SACY_BUNDLED", 1);
    Phar::mapPhar("sacy.phar");
    include("phar://sacy.phar/sacy/sacy.php");'.
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

class SacySkipSubdirsFilter extends RecursiveFilterIterator{
    private $skipdirs;

    function __construct(RecursiveIterator $it, $skipdirs=null){
        parent::__construct($it);
        $this->skipdirs = $skipdirs ?: array();
    }

    public function accept() {
        return !in_array($this->current()->getFilename(), $this->skipdirs);
    }
}

class SacySupportFilesFilter extends FilterIterator{
    private $skipfiles;

    function __construct(Iterator $it, $skipfiles=null){
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
