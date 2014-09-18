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
        // The attribute name regex is too relaxed, but let's
        // compromise and keep it simple.
        $attextract = '#([a-z\-]+)\s*=\s*(["\'])\s*(.*?)\s*\2#';
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
        $path = array($this->_cfg->getDocumentRoot());
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

        $group = serialize($this->_cfg->get('merge_tags') ? [$attrs['media'], $attrs['type']] : [$attrs['media']]);

        return array(
            'group' => $group,
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
                'data' => $this->parseDataAttrs($attrs),
                'type' => $attrs['type']
            );
        }
        return false;
    }

    private function parseDataAttrs($attrs){
        $data = array();

        foreach($attrs as $key => $value){
            // Compromising again here on the valid
            // format of the attr key, to keep the
            // regex simple.
            if(preg_match('#^data-([a-z\-]+)$#', $key, $match)){
                $name = $match[1];
                $data[$name] = $value;
            }
        }

        return $data;
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
        $this->params['merge_tags'] = false;

        if (is_array($params))
            $this->setParams($params);
    }

    public function getDocumentRoot()
    {
        return realpath(str_replace($_SERVER['SCRIPT_NAME'], '', $_SERVER['SCRIPT_FILENAME']));
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
            if (!in_array($key, array('merge_tags', 'query_strings', 'write_headers', 'debug_toggle', 'block_ref')))
                throw new Exception("Invalid option: $key");
        }
        if (isset($params['query_strings']) && !in_array($params['query_strings'], array('force-handle', 'ignore')))
            throw new Exception("Invalid setting for query_strings: ".$params['query_strings']);
        if (isset($params['write_headers']) && !in_array($params['write_headers'], array(true, false), true))
            throw new Exception("Invalid setting for write_headers: ".$params['write_headers']);
        $params['merge_tags'] = !!$params['merge_tags'];

        $this->params = array_merge($this->params, $params);
    }

}

class CacheRenderer {
    private $_cfg;
    private $_source_file;

    /** @var FileCache */
    private $fragment_cache;

    private $rendered_bits;

    function __construct(Config $config, $source_file){
        $this->_cfg = $config;
        $this->_source_file = $source_file;
        $this->rendered_bits = array();

        $class = defined('SACY_FRAGMENT_CACHE_CLASS') ?
            SACY_FRAGMENT_CACHE_CLASS :
            'sacy\FileCache';
        $this->fragment_cache = new $class();

        foreach(array('get', 'set') as $m){
            if (!method_exists($this->fragment_cache, $m))
                throw new Exception("Invalid fragment cache class specified");
        }
    }

    function allowMergedTransformOnly($tag){
        return $tag == 'script';
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

    function getRenderedAssets(){
        return array_reverse($this->rendered_bits);
    }


    private function render_style_units($work_units, $cat){
        // we can do this because tags are grouped by the presence of a file or not
        $cs = '';
        if ($cat){
            $c = unserialize($cat);
            $cs = $cat ? sprintf(' media="%s"', htmlspecialchars($c[0], ENT_QUOTES)) : '';
        }
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
                $this->rendered_bits[] = array('type' => 'file', 'src' => $res);
                return sprintf('<script type="text/javascript" src="%s"></script>'."\n", htmlspecialchars($res, ENT_QUOTES));
            }
        }else{
            $res = $this->generate_content_cache($work_units, new JavaScriptRenderHandler($this->_cfg, $this->_source_file));
            if($res) $this->rendered_bits[] = array('type' => 'string', 'content' => $res);
            return sprintf('<script type="text/javascript">%s</script>'."\n", $res);
        }
        return '';
    }

    private function generate_content_cache($work_units, CacheRenderHandler $rh){
        $content = implode("\n", array_map(function($u){ return $u['content']; }, $work_units));
        $key = md5($content.$this->_cfg->getDebugMode());
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

    private function content_key_for_mtime_key($key, $work_units){
        if (!(defined('SACY_USE_CONTENT_BASED_CACHE') && SACY_USE_CONTENT_BASED_CACHE))
            return $key;

        $cache_key = 'ck-for-mkey-'.$key;

        $ck = $this->fragment_cache->get($cache_key);
        if (!$ck){
            $ck = "";
            foreach($work_units as $f){
                $ck = md5($ck.md5_file($f['file']));
                foreach($f['additional_files'] as $af){
                    $ck = md5($ck.md5_file($af));
                }
            }
            $ck = "$ck-content";
            $this->fragment_cache->set($cache_key, $ck);
        }
        return $ck;
    }

    private function generate_file_cache($work_units, CacheRenderHandler $rh){
        if (!is_dir(ASSET_COMPILE_OUTPUT_DIR)){
            if (!@mkdir(ASSET_COMPILE_OUTPUT_DIR, 0755, true)){
                throw new Exception("Failed to create output directory");
            }
        }

        $f = function($f){
            return pathinfo($f['file'], PATHINFO_FILENAME);
        };

        $ident = implode('-', array_map($f, $work_units));
        if (strlen($ident) > 120)
            $ident = 'many-files-'.md5($ident);
        $max = 0;
        $idents = array();
        foreach($work_units as &$f){
            $idents[] = array(
                $f['group'], $f['file'], $f['type'], $f['tag']
            );
            $f['additional_files'] = $rh->getAdditionalFiles($f);
            $max = max($max, filemtime($f['file']));
            foreach($f['additional_files'] as $af){
                $max = max($max, filemtime($af));
            }
            unset($f);
        }

        // not using the actual content for quicker access
        $key = md5($max . serialize($idents) . $rh->getConfig()->getDebugMode());
        $key = $this->content_key_for_mtime_key($key, $work_units);

        $cfile = ASSET_COMPILE_OUTPUT_DIR . DIRECTORY_SEPARATOR ."$ident-$key".$rh->getFileExtension();
        $pub = ASSET_COMPILE_URL_ROOT . "/$ident-$key".$rh->getFileExtension();

        if (file_exists($cfile) && ($rh->getConfig()->getDebugMode() != 2)){
            return $pub;
        }

        $this->write_cache($cfile, $work_units, $rh);

        return $pub;
    }

    private function write_cache($cfile, $files, CacheRenderHandler $rh){
        $tmpfile = $this->write_cache_tmpfile($cfile, $files, $rh);

        if ($tmpfile) {
            $ts = time();

            $this->write_compressed_cache($tmpfile, $cfile, $ts);

            if (rename($tmpfile, $cfile)) {
                chmod($cfile, 0644);
                touch($cfile, $ts);
            } else {
                trigger_error("Cannot write file: $cfile", E_USER_WARNING);
            }
        }

        return !!$tmpfile;
    }

    private function write_compressed_cache($tmpfile, $cfile, $ts){
        if (!function_exists('gzencode')) return;

        $tmp_compressed = "$tmpfile.gz";
        file_put_contents($tmp_compressed, gzencode(file_get_contents($tmpfile), 9));

        $compressed = "$cfile.gz";
        if (rename($tmp_compressed, $compressed)) {
            touch($compressed, $ts);
        }else{
            trigger_error("Cannot write compressed file: $compressed", E_USER_WARNING);
        }
    }

    private function write_cache_tmpfile($cfile, $files, CacheRenderHandler $rh){
        $tmpfile = tempnam(dirname($cfile), $cfile);

        $fhc = @fopen($tmpfile, 'w+');
        if (!$fhc){
            trigger_error("Cannot write to temporary file: $tmpfile", E_USER_WARNING);
            return null;
        }

        if ($rh->getConfig()->get('write_headers'))
            $rh->writeHeader($fhc, $files);

        $res = true;
        $merge = !!$rh->getConfig()->get('merge_tags');

        if ($merge)
            $rh->startWrite();

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

        if ($merge)
            $rh->endWrite($fhc);

        fclose($fhc);

        return $res ? $tmpfile : null;
    }

}

interface CacheRenderHandler{
    function __construct(Config $cfg, $source_file);
    function getFileExtension();
    static function willTransformType($type);
    function writeHeader($fh, $work_units);
    function getAdditionalFiles($work_unit);
    function processFile($fh, $work_unit);
    function startWrite();
    function endWrite($fh);
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

    function startWrite(){}

    function endWrite($fh){}

}

class JavaScriptRenderHandler extends ConfiguredRenderHandler{
    static function supportedTransformations(){
        $supported = array();

        if (function_exists('CoffeeScript\compile') || ExternalProcessorRegistry::typeIsSupported('text/coffeescript'))
            $supported[] = 'text/coffeescript';

        if (ExternalProcessorRegistry::typeIsSupported('text/x-eco'))
            $supported[] = 'text/x-eco';

        if (ExternalProcessorRegistry::typeIsSupported('text/x-jsx'))
            $supported[] = 'text/x-jsx';

        return $supported;
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
            fprintf($fh, "    - %s\n", str_replace($this->getConfig()->getDocumentRoot(), '<root>', $file['file']));
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
        } else if ($work_unit['type'] == 'text/x-eco'){
            $eco = ExternalProcessorRegistry::getTransformerForType('text/x-eco');
            $js = $eco->transform($js, $source_file, $work_unit['data']);
        } else if ($work_unit['type'] == 'text/x-jsx'){
            $jsx = ExternalProcessorRegistry::getTransformerForType('text/x-jsx');
            $js = $jsx->transform($js, $source_file, $work_unit['data']);
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
            fprintf($fh, "\n/* %s */\n", str_replace($this->getConfig()->getDocumentRoot(), '<root>', $work_unit['file']));
        fwrite($fh, $this->getOutput($work_unit));
    }

    function getAdditionalFiles($work_unit) {
        return [];
    }
}

class CssRenderHandler extends ConfiguredRenderHandler{
    private $to_process = [];
    private $collecting = false;

    static function supportedTransformations(){
        $res = array('', 'text/css');
        if (class_exists('lessc') || ExternalProcessorRegistry::typeIsSupported('text/x-less'))
            $res[] = 'text/x-less';
        if (class_exists('SassParser') || ExternalProcessorRegistry::typeIsSupported('text/x-sass'))
            $res = array_merge($res, array('text/x-sass', 'text/x-scss'));
        if (PhpSassSacy::isAvailable())
            $res[] = 'text/x-scss';

        return array_unique($res);
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
            fprintf($fh, "    - %s\n", str_replace($this->getConfig()->getDocumentRoot(), '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function processFile($fh, $work_unit){
        // for now: only support collecting for scss and sass
        if (!in_array($work_unit['type'], array('text/x-scss', 'text/x-sass'))){
            $this->collecting = false;
        }
        if ($this->collecting){
            $content = @file_get_contents($work_unit['file']);
            if (!$content) $content = "/* error accessing file {$work_unit['file']} */";

            $content = \Minify_CSS_UriRewriter::rewrite(
                $content,
                dirname($work_unit['file']),
                $this->getConfig()->getDocumentRoot(),
                array(),
                true
            );

            $this->to_process[] = array(
                'file' => $work_unit['file'],
                'content' => $content,
                'type' => $work_unit['type'],
            );
        }else{
            if ($this->getConfig()->get('write_headers'))
               fprintf($fh, "\n/* %s */\n", str_replace($this->getConfig()->getDocumentRoot(), '<root>', $work_unit['file']));

            fwrite($fh, $this->getOutput($work_unit));
        }
    }

    function endWrite($fh){
        if (!$this->collecting) return;
        $content = '';
        $incpath = [];
        foreach($this->to_process as $job){
            $content .= $job['content'];
            $incpath[] = dirname($job['file']);
        }

        fwrite($fh, $this->getOutput(array(
            'content' => $content,
            'type' => $this->to_process[0]['type'],
            'paths' => $incpath
        )));

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
            $opts = array();
            if ($work_unit['paths'])
                $opts['library_path'] = $work_unit['paths'];
            $css = ExternalProcessorRegistry::getTransformerForType($work_unit['type'])
                ->transform($css, $source_file, $opts);
        }else{
            if ($work_unit['type'] == 'text/x-less'){
                $less = new \lessc();
                $less->importDir = dirname($source_file).'/'; #lessphp concatenates without a /
                $css = $less->parse($css);
            }
            if (PhpSassSacy::isAvailable() && $work_unit['type'] == 'text/x-scss'){
                $css = PhpSassSacy::compile($source_file, $work_unit['paths'] ?: array(dirname($source_file)));
            }elseif (in_array($work_unit['type'], array('text/x-scss', 'text/x-sass'))){
                $config = array(
                    'cache' => false, // no need. WE are the cache!
                    'debug_info' => $debug,
                    'line' => $debug,
                    'load_paths' => $work_unit['paths'] ?: array(dirname($source_file)),
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
                $this->getConfig()->getDocumentRoot(),
                array()
            );
        }else{
            return \Minify_CSS::minify($css, array(
                'currentDir' => dirname($source_file),
                'docRoot' => $this->getConfig()->getDocumentRoot()
            ));
        }
    }

    private function extract_import_file($parent_type, $parent_file, $cssdata){
        $f = null;
        if (preg_match('#^\s*url\((["\'])([^\1]+)\1#', $cssdata, $matches)){
            $f = $matches[2];
        }elseif(preg_match('#^\s*(["\'])([^\1]+)\1#', $cssdata, $matches)){
            $f = $matches[2];
        }
        $path_info = pathinfo($parent_file);
        if (in_array($parent_type, ['text/x-scss', 'text/x-sass'])){
            $ext = preg_quote($path_info['extension'], '#');
            if (!preg_match("#.$ext\$#", $f))
                $f .= ".".$path_info['extension'];

            $mixin = $path_info['dirname'].DIRECTORY_SEPARATOR."_$f";
            if (file_exists($mixin)) return $mixin;
        }elseif($parent_type == 'text/x-less'){
            // less only inlines @import's of .less files (see: http://lesscss.org/#-importing)
            if (!preg_match('#\.less$', $f)) return null;
        }else{
            return null;
        }

        $f = $path_info['dirname'] . DIRECTORY_SEPARATOR . $f;
        return file_exists($f) ? $f : null;
    }

    private function find_imports($type, $file, $level){
        $level++;
        if (!in_array($type, ['text/x-scss', 'text/x-sass', 'text/x-less']))
            return [];

        if ($level > 10) throw new Exception("CSS Include nesting level of $level too deep");
        $fh = fopen($file, 'r');
        $res = [];
        while(false !== ($line = fgets($fh))){
            if (preg_match('#^\s*$#', $line)) continue;
            if (preg_match('#^\s*@import(.*)$#', $line, $matches)){
                $f = $this->extract_import_file($type, $file, $matches[1]);
                if ($f){
                    $res[] = $f;
                    $res = array_merge($res, $this->find_imports($type, $f, $level));
                }
            }
        }
        fclose($fh);
        return $res;
    }

    function getAdditionalFiles($work_unit) {
        $level = 0;
        return $this->find_imports($work_unit['type'], $work_unit['file'], $level);
    }

    function startWrite(){
        $this->to_process = [];
        $this->collecting = true;
    }
}
