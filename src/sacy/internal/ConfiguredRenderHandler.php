<?php

namespace sacy\internal;

use sacy\internal\FileCache;
use sacy\internal\CacheRenderHandler;
use sacy\internal\BlockParams;

abstract class ConfiguredRenderHandler implements CacheRenderHandler{
    private $_cfg;
    private $_source_file;
    private $_cache;

    function __construct(BlockParams $cfg, $fragment_cache, $source_file){
        $this->_cfg = $cfg;
        $this->_source_file = $source_file;
        $this->_cache = $fragment_cache;
    }

    protected function getSourceFile(){
        return $this->_source_file;
    }

    public function getConfig(){
        return $this->_cfg;
    }

    /**
     * @return FileCache
     */
    protected function getCache(){
        return $this->_cache;
    }

    static public function willTransformType($type){
        return false;
    }

    function startWrite(){}

    function endWrite($fh){}

}
