#!/usr/bin/env php
<?php

$args = getopt("z:o:cjh", array('with-phamlp::', 'with-lessphp::', 'with-coffeescript-php::'));
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
if (!$args || $args['h']){
    fwrite(STDERR, "usage: build.php [-o <output dir name>] [-c] [-j] [-z [g|b]] [--with-PACKAGE=<path>]
    -c: Include CSSMin into bundle
    -j: Include JSMin into bundle
    -o: Write output to <output dir name> instead of build/
    -z: Compress the contents of the bundle with bzip2 (b) or gzip (g)

    Packages are optional additional packages to include support for. If you
    don't provide them, sacy will try using existing classes at runtime or
    leave corresponding tags alone

    --with-coffeescript-php= path to unpacked source of coffeescript-php
    --with-phamlp=           path to unpacked source bundle of phamlp for
                             text/sass and text/scss support
    --with-lessphp=          path to unpacked source bundle of lessphp for
                             text/less support\n");
    die(1);
}

$comp = Phar::NONE;
if (isset($args['z'])){
    switch($args['z']){
        case 'g':
            $comp = Phar::GZ;
            break;
        case 'b':
            $comp = Phar::BZ2;
            break;
        default:
            fwrite(STDERR, "Invalid compression method\n");
    }
}

$skipfiles = array();
if (!isset($args['c'])) $skipfiles['cssmin.php'] = true;
if (!isset($args['j'])) $skipfiles['jsmin.php'] = true;
$skipfiles['block.asset_compile.php'] = true; // this will be used as phar stub


$srcdir = implode(DIRECTORY_SEPARATOR, array(__DIR__, 'src'));
$outfile = 'block.asset_compile.php';
$outdir = isset($args['o']) ? $args['o'] : implode(DIRECTORY_SEPARATOR, array(__DIR__, 'build'));
$target = implode(DIRECTORY_SEPARATOR, array($outdir, 'temp.phar'));

$arch = new Phar($target, 0, 'sacy.phar');
$arch->startBuffering();
$arch->buildFromIterator(new SacySupportFilesFilter(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcdir), RecursiveIteratorIterator::SELF_FIRST),
            $skipfiles
), $srcdir);

if ($args['with-coffeescript-php']){
    $dir = $args['with-coffeescript-php'];
    if (!is_dir($dir)){
        fwrite(STDERR, "--with-coffeescript-php specified, but coffeescript not found there.\n");
        exit(1);
    }


    $d = preg_quote(implode(DIRECTORY_SEPARATOR, array($dir, 'coffeescript')), '#');
    $i = new SacyRegexWhitelistFilter(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::SELF_FIRST
    ), "#^$d#");

    $arch->buildFromIterator($i, $dir);
}
if ($args['with-phamlp']){
    $p = sprintf('patch -N -d %s -p1 -i %s',
        escapeshellarg($args['with-phamlp']),
        escapeshellarg(implode(DIRECTORY_SEPARATOR, array(__DIR__, 'sass-fix-import.patch')))
    );
    exec($p);

    $arch->buildFromIterator(new RecursiveIteratorIterator(
        new SacySkipSubdirsFilter(
            new RecursiveDirectoryIterator($args['with-phamlp']),
            array('haml')
        ),
        RecursiveIteratorIterator::SELF_FIRST
    ), $args['with-phamlp']);
}
if ($args['with-lessphp']){
    $arch['sacy/lessc.inc.php'] = file_get_contents(
        implode(DIRECTORY_SEPARATOR, array($args['with-lessphp'], 'lessc.inc.php'))
    );
}

$arch->stopBuffering();

if ($comp != Phar::NONE)
    $arch->compressFiles($comp);

$stub ='<?php Phar::interceptFileFuncs();
    define("____SACY_BUNDLED", 1);
    Phar::mapPhar("sacy.phar");
    include("phar://sacy.phar/sacy/ext-translators.php");
    include("phar://sacy.phar/sacy/fragment-cache.php");
    include("phar://sacy.phar/sacy/phpsass.php");
    include("phar://sacy.phar/sacy/sacy.php");
    '.
    de_phptag(file_get_contents($srcdir.DIRECTORY_SEPARATOR.'block.asset_compile.php')).
    "\n__HALT_COMPILER();";
$arch->setStub($stub);

$arch = null;
rename($target, implode(DIRECTORY_SEPARATOR, array($outdir, $outfile)));
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

class SacyRegexWhitelistFilter extends FilterIterator{
    private $wl = '#.#';

    function __construct(Iterator $it, $wl = null){
        parent::__construct($it);
        $this->wl = $wl ?: '#.#';
    }

    function accept(){
        return preg_match($this->wl, $this->current()->getPathName());
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
            return false;
        if ($this->skipfiles[$this->current()->getFilename()])
            return false;

        return true;
    }
}
