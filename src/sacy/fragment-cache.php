<?php
namespace sacy;

class FileCache{
    private $cache_dir;

    function __construct(){
        $this->cache_dir = implode(DIRECTORY_SEPARATOR, array(
            ASSET_COMPILE_OUTPUT_DIR,
            'fragments'
        ));
        if (!is_dir($this->cache_dir)){
            if (!@mkdir($this->cache_dir, 0755, true)){
                throw new Exception("Failed to create fragments cache directory");
            }
        }
    }

    function key2file($key){
        if (!preg_match('#^[0-9a-z]+$#', $key)) throw new Exception('Invalid cache key');
        return implode(DIRECTORY_SEPARATOR, array(
            $this->cache_dir,
            preg_replace('#^([0-9a-f]{2})([0-9a-f]{2})(.*)$#u', '\1/\2/\3', $key)
        ));
    }

    function get($key){
        $p = $this->key2file($key);
        return file_exists($p) ? @file_get_contents($p) : null;
    }

    function set($key, $value){
        $p = $this->key2file($key);
        if (!@mkdir(dirname($p), 0755, true)){
            throw new Exception("Failed to create fragment cache dir: $p");
        }
        return @file_put_contents($p, $value);
    }
}
