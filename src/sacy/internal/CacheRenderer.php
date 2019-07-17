<?php

namespace sacy\internal;

use sacy\Configuration;
use sacy\internal\CacheRenderHandler;
use sacy\internal\CssRenderHandler;
use sacy\Exception;
use sacy\internal\FileCache;
use sacy\internal\JavaScriptRenderHandler;

class CacheRenderer {
    private $_params;
    private $_configuration;
    private $_source_file;

    /** @var FileCache */
    private $fragment_cache;

    private $rendered_bits;

    function __construct(Configuration $config, BlockParams $params, $source_file, $fragment_cache){
        $this->_params = $params;
        $this->_configuration = $config;
        $this->_source_file = $source_file;
        $this->rendered_bits = array();

        $this->fragment_cache = $fragment_cache;

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
            if ($res = $this->generate_file_cache($work_units, new CssRenderHandler($this->_configuration, $this->_params, $this->fragment_cache, $this->_source_file))){
                $res = sprintf('<link rel="stylesheet" type="text/css"%s href="%s" />'."\n", $cs, htmlspecialchars($res, ENT_QUOTES));
            }
        }else{
            $res = $this->generate_content_cache($work_units, new CssRenderHandler($this->_configuration, $this->_params, $this->fragment_cache, $this->_source_file));
            $res = sprintf('<style type="text/css"%s>%s</style>'."\n", $cs, $res);
        }
        return $res;

    }

    private function render_script_units($work_units, $cat){
        if ($work_units[0]['file']){
            if ($res = $this->generate_file_cache($work_units, new JavaScriptRenderHandler($this->_params, $this->fragment_cache, $this->_source_file))){
                $this->rendered_bits[] = array('type' => 'file', 'src' => $res);
                return sprintf('<script type="text/javascript" src="%s"></script>'."\n", htmlspecialchars($res, ENT_QUOTES));
            }
        }else{
            $res = $this->generate_content_cache($work_units, new JavaScriptRenderHandler($this->_params, $this->fragment_cache, $this->_source_file));
            if($res) $this->rendered_bits[] = array('type' => 'string', 'content' => $res);
            return sprintf('<script type="text/javascript">%s</script>'."\n", $res);
        }
        return '';
    }

    private function generate_content_cache($work_units, CacheRenderHandler $rh){
        $content = implode("\n", array_map(function($u){ return $u['content']; }, $work_units));
        $key = md5($content.json_encode($this->_params));
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
        if (!$this->_configuration->useContentBasedCache())
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
            $f['mtime'] = filemtime($f['file']);
            $f['additional_files'] = $rh->getAdditionalFiles($f);
            $max = max($max, $f['mtime']);
            foreach($f['additional_files'] as $af){
                $max = max($max, filemtime($af));
            }
            unset($f);
        }

        // not using the actual content for quicker access
        $key = md5($max . serialize($idents) . json_encode($rh->getParams()));
        $key = $this->content_key_for_mtime_key($key, $work_units);

        $cfile = ASSET_COMPILE_OUTPUT_DIR . DIRECTORY_SEPARATOR ."$ident-$key".$rh->getFileExtension();
        $pub = ASSET_COMPILE_URL_ROOT . "/$ident-$key".$rh->getFileExtension();

        if (file_exists($cfile) && ($rh->getParams()->getDebugMode() != 2)){
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

        if ($rh->getParams()->get('write_headers'))
            $rh->writeHeader($fhc, $files);

        $res = true;
        $merge = !!$rh->getParams()->get('merge_tags');

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
