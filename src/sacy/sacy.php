<?php

if (!class_exists('JSMin'))
    include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'jsmin.php')));

/*
 *   An earlier experiment contained a real framework for tag
 *   and parser registration. In the end, this turned out
 *   to be much too complex if we just need to support two tags
 *   for two types of resources.
 */
class sacy_FileExtractor{

    function extractFile($tag, $attrdata, $content){
        switch($tag){
            case 'link':
                $fn = 'extract_css_file';
                break;
            case 'script':
                $fn = 'extract_js_file';
                break;
            default: throw new sacy_Exception("Cannot handle tag: $tag");
        }
        return $this->$fn($attrdata, $content);
    }

    private function urlToFile($ref){
        $u = parse_url($ref);
        if ($u === false) return false;
        if (isset($u['host']) || isset($u['scheme']))
            return false;

        // TODO: ignore if query string is set, depending on configuration
        $ref = $u['path'];
        $path = array($_SERVER['DOCUMENT_ROOT']);
        if ($ref[0] != '/')
            $path[] = $_SERVER['PHP_SELF'];
        $path[] = $ref;
        return realpath(implode(DIRECTORY_SEPARATOR, $path));

    }


    private function extract_css_file($attrdata, $content){
        // if any of these conditions are met, this handler will decline
        // handling the tag:
        //
        //  - the tag contains content (invalid markup)
        //  - the tag uses any rel beside 'stylesheet' (valid, but not supported)
        //  - the tag uses any type besides text/css (would maybe still work, but I can't be sure)
        $attrs = sacy_extract_attrs($attrdata);
        if (empty($content) && (strtolower($attrs['rel']) == 'stylesheet') &&
            (!isset($attrs['type']) || strtolower($attrs['type']) == 'text/css')){
            if (!isset($attrs['media']))
                $attrs['media'] = "";

            $path = $this->urlToFile($attrs['href']);
            if ($path === false) return false;

            return array($attrs['media'], $path);
        }
        return false;
    }

    private function extract_js_file($attrdata, $content){
        // don't handle non-empty tags
        if (preg_match('#\S+#', $content)) return false;

        $attrs = sacy_extract_attrs($attrdata);

        if ( ($attrs['type'] == 'text/javascript' ||
                $attrs['type'] == 'application/javascript') &&
             (isset($attrs['src']) && !empty($attrs['src'])) ){

            $path = $this->urlToFile($attrs['src']);
            if ($path === false) return false;
            return array('', $path);
        }
        return false;
    }

}

class sacy_CacheRenderer {
    private $_smarty;

    function __construct($smarty){
        $this->_smarty = $smarty;
    }

    function renderFiles($tag, $cat, $files){
        switch($tag){
            case 'link':
                $fn = 'render_css_files';
                break;
            case 'script':
                $fn = 'render_js_files';
                break;
            default: throw new sacy_Exception("Cannot handle tag: $tag");
        }
        return $this->$fn($files, $cat);
    }


    private function render_css_files($files, $cat){
        $ref = sacy_generate_cache($this->_smarty, $files, new sacy_CssRenderHandler($this->_smarty));
        if (!$ref) return false;
        $cs = $cat ? sprintf(' media="%s"', htmlspecialchars($cat, ENT_QUOTES)) : '';
        return sprintf('<link rel="stylesheet" type="text/css"%s href="%s" />'."\n",
                       $cs, htmlspecialchars($ref, ENT_QUOTES)
                      );

    }

    private function render_js_files($files, $cat){
        $ref = sacy_generate_cache($this->_smarty, $files, new sacy_JavascriptRenderHandler($this->_smarty));
        if (!$ref) return false;
        return sprintf('<script type="text/javascript" src="%s"></script>'."\n", htmlspecialchars($ref, ENT_QUOTES));
    }
}

interface sacy_CacheRenderHandler{
    function __construct($smarty);
    function getFileExtension();
    function writeHeader($fh, $files);
    function processFile($fh, $filename);
}

class sacy_JavaScriptRenderHandler implements sacy_CacheRenderHandler{
    private $_smarty;

    function __construct($smarty){
        $this->_smarty = $smarty;
    }

    function getFileExtension() { return '.js'; }

    function writeHeader($fh, $files){
        fwrite($fh, "/*\nsacy javascript cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($files as $file){
            fprintf($fh, "    - %s\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file));
        }
        fwrite($fh, "*/\n\n");
    }

    function processFile($fh, $filename){
        fprintf($fh, "\n/* %s */\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $filename));
        $js = @file_get_contents($filename);
        if ($js == false){
            fwrite($fhc, "/* <Error accessing file> */\n");
            $this->_smarty->trigger_error("Error accessing JavaScript-File: $filename");
            return;
        }
        fwrite($fh, JSMin::minify($js));
    }
}

class sacy_CssRenderHandler implements sacy_CacheRenderHandler{
    private $_smarty;

    function __construct($smarty){
        $this->_smarty = $smarty;
    }

    function getFileExtension() { return '.css'; }

    function writeHeader($fh, $files){
        fwrite($fh, "/*\nsacy css cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($files as $file){
            fprintf($fh, "    - %s\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file));
        }
        fwrite($fh, "*/\n\n");
    }

    function processFile($fh, $filename){
        fprintf($fh, "\n/* %s */\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $filename));
        $css = @file_get_contents($filename); //maybe stream this later to save memory?
        if ($css == false){
            fwrite($fh, "/* <Error accessing file> */\n");
            $this->_smarty->trigger_error("Error accessing CSS-File: $filename");
            return;
        }
        $css = $this->rewrite_cssurl($css, $filename);
        $css = str_replace("\r\n", "\n", $css);
        $css = str_replace("\r", "\n", $css);
        $css = str_replace("\n", " ", $css);
        $css = preg_replace('#\s+#', ' ', $css);
        fwrite($fh, $css);
    }

    private function rewrite_cssurl($css, $src){
        // I have to check this pattern by pattern, because quoted urls could
        // contain the other character in them, thus breaking the rewrite if
        // I'd stop at the first matching character.
        //
        // Also storing the quote character to quote the url with after the
        // replacement.
        $patterns = array( array('#url\s*\(\s*((?:\.\./)+)(.*?)\s*\)#i', '"'),
                           array('#url\s*\(\s*\'((?:\.\./)+)(.*?)\'\s*\)#i', "'"),
                           array('#url\s*\(\s*\"((?:\.\./)+)(.*?)\"\s*\)#i', '"')
                         );

        foreach($patterns as $pattern){
            list($urlpattern, $q) = $pattern;

            if (!(preg_match($urlpattern, $css, $m)))
                continue;

            $pc = substr_count($m[1], '..');

            $pubpath = explode('/', dirname(substr($src, strlen($_SERVER['DOCUMENT_ROOT']))));
            array_splice($pubpath, count($pubpath)-$pc);
            $d = implode('/', $pubpath)."/";

            $css = preg_replace($urlpattern, "url($q$d\\2$q)", $css);
        }
        return $css;
    }

}

class sacy_Exception extends Exception {}

function sacy_extract_attrs($attstr){
    $attextract = '#([a-z]+)\s*=\s*(["\'])\s*(.*?)\s*\2#';
    $res = array();
    if (!preg_match_all($attextract, $attstr, $m)) return false;
    $res = array();
    foreach($m[1] as $idx => $name){
        $res[strtolower($name)] = $m[3][$idx];
    }
    return $res;

}

function sacy_generate_cache(&$smarty, $files, sacy_CacheRenderHandler $rh){
    if (!is_dir(ASSET_COMPILE_OUTPUT_DIR))
        mkdir(ASSET_COMPILE_OUTPUT_DIR);

    $f = create_function('$f', 'return basename($f, "'.$rh->getFileExtension().'");');
    $ident = implode('-', array_map($f, $files));
    if (strlen($ident) > 120)
        $ident = 'many-files-'.md5($ident);
    $max = 0;
    foreach($files as $f){
        $max = max($max, filemtime($f));
    }
    // not using the actual content for quicker access
    $key = md5($max . serialize($files));
    $cfile = ASSET_COMPILE_OUTPUT_DIR . DIRECTORY_SEPARATOR ."$ident-$key".$rh->getFileExtension();
    $pub = ASSET_COMPILE_URL_ROOT . "/$ident-$key".$rh->getFileExtension();

    if (file_exists($cfile) && (!defined('DEBUG') || !DEBUG)){
        return $pub;
    }

    if (!sacy_write_cache($smarty, $cfile, $files, $rh)){
        return false;
    }

    return $pub;
}

function sacy_write_cache(&$smarty, $cfile, $files, sacy_CacheRenderHandler $rh){
    $lockfile = $cfile.".lock";
    $fhl = @fopen($lockfile, 'w');
    if (!$fhl){
        $smarty->trigger_error("Cannot create cache-lockfile: $lockfile");
        return false;
    }
    $wb = false;
    if (!@flock($fhl, LOCK_EX | LOCK_NB, $wb)){
        $smarty->trigger_error("Canot lock cache-lockfile: $lockfile");
        return false;
    }
    if ($wb){
        // another process is writing the cache. Let's just return false
        // the caller will leave the CSS unaltered
        return false;
    }
    $fhc = @fopen($cfile, 'w');
    if (!$fhc){
        $smarty->trigger_error("Cannot open cache file: $cfile");
        fclose($fhl);
        unlink($lockfile);
        return false;
    }

    $rh->writeHeader($fhc, $files);

    foreach($files as $file){
        $rh->processFile($fhc, $file);
    }

    fclose($fhc);
    fclose($fhl);
    unlink($lockfile);
    return true;
}





