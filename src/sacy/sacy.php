<?php

if (!defined("____SACY_BUNDLED"))
    include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'ext-translators.php')));

if (!class_exists('JSMin') && !ExternalProcessorRegistry::typeIsSupported('text/javascript'))
    include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'jsmin.php')));

if (!class_exists('Minify_CSS'))
    include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'cssmin.php')));

if (!class_exists('lessc') && !ExternalProcessorRegistry::typeIsSupported('text/x-less')){
    $less = implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'lessc.inc.php'));
    if (file_exists($less)){
        include_once($less);
    }
}

if(function_exists('CoffeeScript\compile')){
    include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'coffeescript.php')));
} else if (!ExternalProcessorRegistry::typeIsSupported('text/coffeescript')){
    $coffee = implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), '..', 'coffeescript', 'coffeescript.php'));
    if (file_exists($coffee)){
        include_once($coffee);
        include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'coffeescript.php')));
    }
}


if (!class_exists('SassParser') && !ExternalProcessorRegistry::typeIsSupported('text/x-sass')){
    $sass = implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), '..', 'sass', 'SassParser.php'));
    if (file_exists($sass)){
        include_once($sass);
    }
}

/*
 *   An earlier experiment contained a real framework for tag
 *   and parser registration. In the end, this turned out
 *   to be much too complex if we just need to support two tags
 *   for two types of resources.
 */
class sacy_WorkUnitExtractor{
    private $_cfg;

    function __construct(sacy_Config $config){
        $this->_cfg = $config;
    }

    function getAcceptedWorkUnits($tags){
        $work_units = array();
        foreach($tags as $tag){
            $r = $this->workUnitFromTag($tag['tag'], $tag['attrdata'], $tag['content']);
            if ($r === false) continue; // handler has declined
            $r = array_merge($r, array(
                'page_order' => $tag['page_order'],
                'position' => $tag['index'],
                'length' => strlen($tag['tagdata']),
                'tag' => $tag['tag']
            ));
            $work_units[] = $r;
        }
        return $work_units;
    }

    function workUnitFromTag($tag, $attrdata, $content){
        switch($tag){
            case 'link':
                $fn = 'extract_css_unit';
                break;
            case 'script':
                $fn = 'extract_js_unit';
                break;
            default: throw new sacy_Exception("Cannot handle tag: $tag");
        }
        return $this->$fn($attrdata, $content);
    }

    private function extract_attrs($attstr){
        $attextract = '#([a-z]+)\s*=\s*(["\'])\s*(.*?)\s*\2#';
        if (!preg_match_all($attextract, $attstr, $m)) return false;
        $res = array();
        foreach($m[1] as $idx => $name){
            $res[strtolower($name)] = $m[3][$idx];
        }
        return $res;
    }

    private function urlToFile($ref){
        $u = parse_url($ref);
        if ($u === false) return false;
        if (isset($u['host']) || isset($u['scheme']))
            return false;

        if ($this->_cfg->get('query_strings') == 'ignore')
            if (isset($u['query'])) return false;

        $ref = $u['path'];
        $path = array($_SERVER['DOCUMENT_ROOT']);
        if ($ref[0] != '/')
            $path[] = $_SERVER['PHP_SELF'];
        $path[] = $ref;
        return realpath(implode(DIRECTORY_SEPARATOR, $path));

    }


    private function extract_css_unit($attrdata, $content){
        // if any of these conditions are met, this handler will decline
        // handling the tag:
        //
        //  - the tag contains content (invalid markup)
        //  - the tag uses any rel beside 'stylesheet' (valid, but not supported)
        //  - the tag uses a not-supported type (
        $attrs = $this->extract_attrs($attrdata);
        $attrs['type'] = strtolower($attrs['type']);
        if ($this->_cfg->getDebugMode() == 3 &&
                !sacy_CssRenderHandler::willTransformType($attrs['type']))
            return false;

        if (empty($content) && (strtolower($attrs['rel']) == 'stylesheet') &&
            (!isset($attrs['type']) ||
            (in_array(strtolower($attrs['type']), sacy_CssRenderHandler::supportedTransformations())))){
            if (!isset($attrs['media']))
                $attrs['media'] = "";

            $path = $this->urlToFile($attrs['href']);
            if ($path === false) return false;

            return array(
                'group' => $attrs['media'],
                'file' => $path,
                'type' => $attrs['type']
            );
        }
        return false;
    }

    private function validTag($attrs){
        $types = array_merge(array('text/javascript', 'application/javascript'), sacy_JavascriptRenderHandler::supportedTransformations());
        return  in_array(
            $attrs['type'],
            $types
        ) && !empty($attrs['src']);
    }

    private function extract_js_unit($attrdata, $content){
        // don't handle non-empty tags
        if (preg_match('#\S+#', $content)) return false;

        $attrs = $this->extract_attrs($attrdata);
        $attrs['type'] = strtolower($attrs['type']);
        if ($this->_cfg->getDebugMode() == 3 &&
                !sacy_JavaScriptRenderHandler::willTransformType($attrs['type'])){
            return false;
        }


        if ($this->validTag($attrs)) {
            $path = $this->urlToFile($attrs['src']);
            if ($path === false) return false;
            return array(
                'group' => '',
                'file' => $path,
                'type' => $attrs['type']
            );
        }
        return false;
    }

}

class sacy_Config{
    private $params;

    public function get($key){
        return $this->params[$key];
    }

    public function __construct($params = null){
        $this->params['query_strings'] = defined('SACY_QUERY_STRINGS') ? SACY_QUERY_STRINGS : 'ignore';
        $this->params['write_headers'] = defined('SACY_WRITE_HEADERS') ? SACY_WRITE_HEADERS : true;
        $this->params['debug_toggle']  = defined('SACY_DEBUG_TOGGLE') ? SACY_DEBUG_TOGGLE : '_sacy_debug';

        if (is_array($params))
            $this->setParams($params);
    }

    public function getDebugMode(){
        if ($this->params['debug_toggle'] === false)
            return 0;
        if (isset($_GET[$this->params['debug_toggle']]))
            return intval($_GET[$this->params['debug_toggle']]);
        if (isset($_COOKIE[$this->params['debug_toggle']]))
            return intval($_COOKIE[$this->params['debug_toggle']]);
        return 0;

    }

    public function setParams($params){
        foreach($params as $key => $value){
            if (!in_array($key, array('query_strings', 'write_headers', 'debug_toggle')))
                throw new sacy_Exception("Invalid option: $key");
        }
        if (isset($params['query_strings']) && !in_array($params['query_strings'], array('force-handle', 'ignore')))
            throw new sacy_Exception("Invalid setting for query_strings: ".$params['query_strings']);
        if (isset($params['write_headers']) && !in_array($params['write_headers'], array(true, false), true))
            throw new sacy_Exception("Invalid setting for write_headers: ".$params['write_headers']);


        $this->params = array_merge($this->params, $params);
    }

}

class sacy_CacheRenderer {
    private $_cfg;

    function __construct(sacy_Config $config){
        $this->_cfg = $config;
    }

    function renderWorkUnits($tag, $cat, $files){
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
        $ref = $this->generate_cache($files, new sacy_CssRenderHandler($this->_cfg));
        if (!$ref) return false;
        $cs = $cat ? sprintf(' media="%s"', htmlspecialchars($cat, ENT_QUOTES)) : '';
        return sprintf('<link rel="stylesheet" type="text/css"%s href="%s" />'."\n",
                       $cs, htmlspecialchars($ref, ENT_QUOTES)
                      );

    }

    private function render_js_files($files, $cat){
        $ref = $this->generate_cache($files, new sacy_JavascriptRenderHandler($this->_cfg));
        if (!$ref) return false;
        return sprintf('<script type="text/javascript" src="%s"></script>'."\n", htmlspecialchars($ref, ENT_QUOTES));
    }

    private function generate_cache($work_units, sacy_CacheRenderHandler $rh){
        if (!is_dir(ASSET_COMPILE_OUTPUT_DIR))
            mkdir(ASSET_COMPILE_OUTPUT_DIR);

        $f = function($f) use ($rh){
            return basename($f["file"], "'.$rh->getFileExtension().'");
        };

        $ident = implode('-', array_map($f, $work_units));
        if (strlen($ident) > 120)
            $ident = 'many-files-'.md5($ident);
        $max = 0;
        foreach($work_units as $f){
            $max = max($max, filemtime($f['file']));
        }

        // not using the actual content for quicker access
        $key = md5($max . serialize($work_units) . $rh->getConfig()->getDebugMode());
        $cfile = ASSET_COMPILE_OUTPUT_DIR . DIRECTORY_SEPARATOR ."$ident-$key".$rh->getFileExtension();
        $pub = ASSET_COMPILE_URL_ROOT . "/$ident-$key".$rh->getFileExtension();

        if (file_exists($cfile) && ($rh->getConfig()->getDebugMode() != 2)){
            return $pub;
        }

        if (!$this->write_cache($cfile, $work_units, $rh)){
            /* If something went wrong in here we delete the cache file

               This ensures that on reload, sacy would see that no cached file exists and
               will retry the process.

               This is helpful for example if you define() one of the external utilities
               and screw something up in the process.
            */
            if (file_exists($cfile)){
                @unlink($cfile);
            }
            return false;
        }

        return $pub;
    }

    private function write_cache($cfile, $files, sacy_CacheRenderHandler $rh){
        $lockfile = $cfile.".lock";
        $fhl = @fopen($lockfile, 'w');
        if (!$fhl){
            trigger_error("Cannot create cache-lockfile: $lockfile", E_USER_WARNING);
            return false;
        }
        $wb = false;
        if (!@flock($fhl, LOCK_EX | LOCK_NB, $wb)){
            trigger_error("Canot lock cache-lockfile: $lockfile", E_USER_WARNING);
            return false;
        }
        if ($wb){
            // another process is writing the cache. Let's just return false
            // the caller will leave the CSS unaltered
            return false;
        }
        $fhc = @fopen($cfile, 'w');
        if (!$fhc){
            trigger_error("Cannot open cache file: $cfile", E_USER_WARNING);
            fclose($fhl);
            unlink($lockfile);
            return false;
        }

        if ($rh->getConfig()->get('write_headers'))
            $rh->writeHeader($fhc, $files);

        $res = true;
        foreach($files as $file){
            try{
                $rh->processFile($fhc, $file);
            }catch(Exception $e){
                trigger_error(sprintf(
                    "Exception %s while processing %s:\n\n%s",
                    get_class($e),
                    $file['file'],
                    $e->getMessage()
                ), E_USER_WARNING);
                $res = false;
                break;
            }
        }

        fclose($fhc);
        fclose($fhl);
        unlink($lockfile);
        return $res;
    }

}

interface sacy_CacheRenderHandler{
    function __construct(sacy_Config $cfg);
    function getFileExtension();
    static function willTransformType($type);
    function writeHeader($fh, $files);
    function processFile($fh, $file);
    function getConfig();
}

abstract class sacy_ConfiguredRenderHandler implements sacy_CacheRenderHandler{
    private $_cfg;

    function __construct(sacy_Config $cfg){
        $this->_cfg = $cfg;
    }

    public function getConfig(){
        return $this->_cfg;
    }

    static public function willTransformType($type){
        return false;
    }
}

class sacy_JavaScriptRenderHandler extends sacy_ConfiguredRenderHandler{
    static function supportedTransformations(){
        if (function_exists('CoffeeScript\compile') || ExternalProcessorRegistry::typeIsSupported('text/coffeescript'))
            return array('text/coffeescript');
        return array();
    }

    static function willTransformType($type){
        // transforming everything but plain old CSS
        return in_array($type, self::supportedTransformations());
    }

    function getFileExtension() { return '.js'; }

    function writeHeader($fh, $files){
        fwrite($fh, "/*\nsacy javascript cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($files as $file){
            fprintf($fh, "    - %s\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function processFile($fh, $file){
        $debug = $this->getConfig()->getDebugMode() == 3;

        if ($this->getConfig()->get('write_headers'))
            fprintf($fh, "\n/* %s */\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file['file']));
        $js = @file_get_contents($file['file']);
        if ($js == false){
            fwrite($fh, "/* <Error accessing file> */\n");
            return;
        }
        if ($file['type'] == 'text/coffeescript'){
            $js = ExternalProcessorRegistry::typeIsSupported('text/coffeescript') ?
                ExternalProcessorRegistry::getTransformerForType('text/coffeescript')->transform($js, $file['file']) :
                Coffeescript::build($js);
        }
        if ($debug){
            fwrite($fh, $js);
        }else{
            fwrite($fh, ExternalProcessorRegistry::typeIsSupported('text/javascript') ?
                ExternalProcessorRegistry::getCompressorForType('text/javascript')->transform($js, $file['file']) :
                JSMin::minify($js)
            );
        }
    }

}

class sacy_CssRenderHandler extends sacy_ConfiguredRenderHandler{
    static function supportedTransformations(){
        $res = array('', 'text/css');
        if (class_exists('lessc') || ExternalProcessorRegistry::typeIsSupported('text/x-less'))
            $res[] = 'text/x-less';
        if (class_exists('SassParser') || ExternalProcessorRegistry::typeIsSupported('text/x-sass'))
            $res = array_merge($res, array('text/x-sass', 'text/x-scss'));

        return $res;
    }

    function getFileExtension() { return '.css'; }

    static function willTransformType($type){
        // transforming everything but plain old CSS
        return !in_array($type, array('', 'text/css'));
    }

    function writeHeader($fh, $files){
        fwrite($fh, "/*\nsacy css cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($files as $file){
            fprintf($fh, "    - %s\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function processFile($fh, $file){
        $debug = $this->getConfig()->getDebugMode() == 3;
        if ($this->getConfig()->get('write_headers'))
           fprintf($fh, "\n/* %s */\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file['file']));
        $css = @file_get_contents($file['file']); //maybe stream this later to save memory?
        if ($css == false){
            fwrite($fh, "/* <Error accessing file> */\n");
            return;
        }

        if (ExternalProcessorRegistry::typeIsSupported($file['type'])){
            $css = ExternalProcessorRegistry::getTransformerForType($file['type'])->transform($css, $file['file']);
        }else{
            if ($file['type'] == 'text/x-less'){
                $less = new lessc();
                $less->importDir = dirname($file['file']).'/'; #lessphp concatenates without a /
                $css = $less->parse($css);
            }
            if (in_array($file['type'], array('text/x-scss', 'text/x-sass'))){
                $config = array(
                    'cache' => false, // no need. WE are the cache!
                    'debug_info' => $debug,
                    'line' => $debug,
                    'load_paths' => array(dirname($file['file'])),
                    'filename' => $file['file'],
                    'quiet' => true,
                    'style' => $debug ? 'nested' : 'compressed'
                );
                $sass = new SassParser($config);
                $css = $sass->toCss($css, false); // isFile?
            }
        }

        if ($debug){
            fwrite($fh, Minify_CSS_UriRewriter::rewrite(
                $css,
                dirname($file['file']),
                $_SERVER['DOCUMENT_ROOT'],
                array()
            ));
        }else{
            fwrite($fh, Minify_CSS::minify($css, array(
                'currentDir' => dirname($file['file'])
            )));
        }
    }
}

class sacy_Exception extends Exception {}
