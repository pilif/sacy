<?php
namespace sacy;

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

class Exception extends \Exception {}

/*
 *   An earlier experiment contained a real framework for tag
 *   and parser registration. In the end, this turned out
 *   to be much too complex if we just need to support two tags
 *   for two types of resources.
 */
class WorkUnitExtractor{
    private $_cfg;

    function __construct(Config $config){
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
            case 'style':
                $fn = 'extract_style_unit';
                break;
            case 'script':
                $fn = 'extract_script_unit';
                break;
            default: throw new Exception("Cannot handle tag: ($tag)");
        }
        return $this->$fn($tag, $attrdata, $content);
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


    private function extract_style_unit($tag, $attrdata, $content){
        $attrs = $this->extract_attrs($attrdata);
        $attrs['type'] = strtolower($attrs['type']);

        // invalid markup
        if ($tag == 'link' && !empty($content)) return false;
        if ($tag == 'style' && empty($content)) return false;
        if ($tag == 'link' && empty($attrs['href'])) return false;

        // not a stylesheet
        if ($tag == 'link' && strtolower($attrs['rel']) != 'stylesheet') return false;

        // type attribute required
        if (!isset($attrs['type'])) return false;

        // not one of the supported types
        if (!in_array(strtolower($attrs['type']), CssRenderHandler::supportedTransformations()))
            return false;

        // in debug mode 3, only transform
        if ($this->_cfg->getDebugMode() == 3 &&
                !CssRenderHandler::willTransformType($attrs['type']))
            return false;

        if (!isset($attrs['media']))
            $attrs['media'] = "";

        $path = null;
        if (empty($content)){
            $path = $this->urlToFile($attrs['href']);
            if ($path === false) return false;
        }

        return array(
            'group' => $attrs['media'],
            'file' => $path,
            'content' => $content,
            'type' => $attrs['type']
        );
    }

    private function validTag($attrs){
        $types = array_merge(array('text/javascript', 'application/javascript'), JavaScriptRenderHandler::supportedTransformations());
        return  in_array($attrs['type'], $types);
    }

    private function extract_script_unit($tag, $attrdata, $content){
        $attrs = $this->extract_attrs($attrdata);
        if (!$attrs['type'])
            $attrs['type'] = 'text/javascript';
        $attrs['type'] = strtolower($attrs['type']);
        if ($this->_cfg->getDebugMode() == 3 &&
                !JavaScriptRenderHandler::willTransformType($attrs['type'])){
            return false;
        }

        if ($this->validTag($attrs)) {
            $path = null;
            if (!$content){
                $path = $this->urlToFile($attrs['src']);
                if ($path === false){
                    return false;
                }
            }

            return array(
                'group' => '',
                'content' => $content,
                'file' => $path,
                'type' => $attrs['type']
            );
        }
        return false;
    }

}

class Config{
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
                throw new Exception("Invalid option: $key");
        }
        if (isset($params['query_strings']) && !in_array($params['query_strings'], array('force-handle', 'ignore')))
            throw new Exception("Invalid setting for query_strings: ".$params['query_strings']);
        if (isset($params['write_headers']) && !in_array($params['write_headers'], array(true, false), true))
            throw new Exception("Invalid setting for write_headers: ".$params['write_headers']);


        $this->params = array_merge($this->params, $params);
    }

}

class CacheRenderer {
    private $_cfg;
    private $_source_file;

    /** @var FragmentCache */
    private $fragment_cache;

    function __construct(Config $config, $source_file){
        $this->_cfg = $config;
        $this->_source_file = $source_file;

        $class = defined('SACY_FRAGMENT_CACHE_CLASS') ?
            SACY_FRAGMENT_CACHE_CLASS :
            'sacy\FileCache';
        $this->fragment_cache = new $class();

        if (!($this->fragment_cache instanceof FragmentCache)){
            throw new Exception("Invalid fragment cache class specified");
        }
    }

    function renderWorkUnits($tag, $cat, $work_units){
        switch($tag){
            case 'link':
            case 'style':
                $fn = 'render_style_units';
                break;
            case 'script':
                $fn = 'render_script_units';
                break;
            default: throw new Exception("Cannot handle tag: $tag");
        }
        return $this->$fn($work_units, $cat);
    }


    private function render_style_units($work_units, $cat){
        // we can do this because tags are grouped by the presence of a file or not
        $cs = $cat ? sprintf(' media="%s"', htmlspecialchars($cat, ENT_QUOTES)) : '';
        if ($work_units[0]['file']){
            if ($res = $this->generate_file_cache($work_units, new CssRenderHandler($this->_cfg, $this->_source_file))){
                $res = sprintf('<link rel="stylesheet" type="text/css"%s href="%s" />'."\n", $cs, htmlspecialchars($res, ENT_QUOTES));
            }
        }else{
            $res = $this->generate_content_cache($work_units, new CssRenderHandler($this->_cfg, $this->_source_file));
            $res = sprintf('<style type="text/css"%s>%s</style>'."\n", $cs, $res);
        }
        return $res;

    }

    private function render_script_units($work_units, $cat){
        if ($work_units[0]['file']){
            if ($res = $this->generate_file_cache($work_units, new JavaScriptRenderHandler($this->_cfg, $this->_source_file))){
                return sprintf('<script type="text/javascript" src="%s"></script>'."\n", htmlspecialchars($res, ENT_QUOTES));
            }
        }else{
            return sprintf('<script type="text/javascript">%s</script>'."\n",
                $this->generate_content_cache($work_units, new JavaScriptRenderHandler($this->_cfg, $this->_source_file))
            );
        }
    }

    private function generate_content_cache($work_units, CacheRenderHandler $rh){
        $content = implode("\n", array_map(function($u){ return $u['content']; }, $work_units));
        $key = md5($content);
        if ($d = $this->fragment_cache->get($key)){
            return $d;
        }
        $output = array();
        foreach($work_units as $w){
            $output[] = $rh->getOutput($w);
        }
        $output = implode("\n", $output);
        $this->fragment_cache->set($key, $output);
        return $output;
    }

    private function generate_file_cache($work_units, CacheRenderHandler $rh){
        if (!is_dir(ASSET_COMPILE_OUTPUT_DIR)){
            if (!@mkdir(ASSET_COMPILE_OUTPUT_DIR, 0755, true)){
                throw new Exception("Failed to create output directory");
            }
        }

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

    private function write_cache($cfile, $files, CacheRenderHandler $rh){
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
            }catch(\Exception $e){
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

interface CacheRenderHandler{
    function __construct(Config $cfg, $source_file);
    function getFileExtension();
    static function willTransformType($type);
    function writeHeader($fh, $work_units);
    function processFile($fh, $work_unit);
    function getOutput($work_unit);
    function getConfig();
}

abstract class ConfiguredRenderHandler implements CacheRenderHandler{
    private $_cfg;
    private $_source_file;

    function __construct(Config $cfg, $source_file){
        $this->_cfg = $cfg;
        $this->_source_file = $source_file;
    }

    protected function getSourceFile(){
        return $this->_source_file;
    }

    public function getConfig(){
        return $this->_cfg;
    }

    static public function willTransformType($type){
        return false;
    }
}

class JavaScriptRenderHandler extends ConfiguredRenderHandler{
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

    function writeHeader($fh, $work_units){
        fwrite($fh, "/*\nsacy javascript cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($work_units as $file){
            fprintf($fh, "    - %s\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function getOutput($work_unit){
        $debug = $this->getConfig()->getDebugMode() == 3;
        if ($work_unit['file']){
            $js = @file_get_contents($work_unit['file']);
            if (!$js) return "/* error accessing file */";
            $source_file = $work_unit['file'];
        }else{
            $js = $work_unit['content'];
            $source_file = $this->getSourceFile();
        }

        if ($work_unit['type'] == 'text/coffeescript'){
            $js = ExternalProcessorRegistry::typeIsSupported('text/coffeescript') ?
                ExternalProcessorRegistry::getTransformerForType('text/coffeescript')->transform($js, $source_file) :
                \Coffeescript::build($js);
        }
        if ($debug){
            return $js;
        }else{
            return ExternalProcessorRegistry::typeIsSupported('text/javascript') ?
                ExternalProcessorRegistry::getCompressorForType('text/javascript')->transform($js, $source_file) :
                \JSMin::minify($js);
        }

    }

    function processFile($fh, $work_unit){
        if ($this->getConfig()->get('write_headers'))
            fprintf($fh, "\n/* %s */\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $work_unit['file']));
        fwrite($fh, $this->getOutput($work_unit));
    }

}

class CssRenderHandler extends ConfiguredRenderHandler{
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

    function writeHeader($fh, $work_units){
        fwrite($fh, "/*\nsacy css cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($work_units as $file){
            fprintf($fh, "    - %s\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function processFile($fh, $work_unit){
        if ($this->getConfig()->get('write_headers'))
           fprintf($fh, "\n/* %s */\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $work_unit['file']));

        fwrite($fh, $this->getOutput($work_unit));
    }

    function getOutput($work_unit){
        $debug = $this->getConfig()->getDebugMode() == 3;

        if ($work_unit['file']){
            $css = @file_get_contents($work_unit['file']);
            if (!$css) return "/* error accessing file */";
            $source_file = $work_unit['file'];
        }else{
            $css = $work_unit['content'];
            $source_file = $this->getSourceFile();
        }

        if (ExternalProcessorRegistry::typeIsSupported($work_unit['type'])){
            $css = ExternalProcessorRegistry::getTransformerForType($work_unit['type'])
                ->transform($css, $source_file);
        }else{
            if ($work_unit['type'] == 'text/x-less'){
                $less = new \lessc();
                $less->importDir = dirname($source_file).'/'; #lessphp concatenates without a /
                $css = $less->parse($css);
            }
            if (in_array($work_unit['type'], array('text/x-scss', 'text/x-sass'))){
                $config = array(
                    'cache' => false, // no need. WE are the cache!
                    'debug_info' => $debug,
                    'line' => $debug,
                    'load_paths' => array(dirname($source_file)),
                    'filename' => $source_file,
                    'quiet' => true,
                    'style' => $debug ? 'nested' : 'compressed'
                );
                $sass = new \SassParser($config);
                $css = $sass->toCss($css, false); // isFile?
            }
        }

        if ($debug){
            return \Minify_CSS_UriRewriter::rewrite(
                $css,
                dirname($source_file),
                $_SERVER['DOCUMENT_ROOT'],
                array()
            );
        }else{
            return \Minify_CSS::minify($css, array(
                'currentDir' => dirname($source_file)
            ));
        }
    }
}
