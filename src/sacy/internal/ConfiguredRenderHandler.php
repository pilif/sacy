<?php

namespace sacy\internal;

use sacy\Configuration;

abstract class ConfiguredRenderHandler implements CacheRenderHandler{
    private $_params;
    private $_source_file;
    private $_cache;
    private $_config;

    function __construct(Configuration $cfg, BlockParams $params, $fragment_cache, $source_file){
        $this->_config = $cfg;
        $this->_params = $params;
        $this->_source_file = $source_file;
        $this->_cache = $fragment_cache;
    }

    protected function getSourceFile(){
        return $this->_source_file;
    }

    public function getParams(): BlockParams{
        return $this->_params;
    }

    public function getConfig(): Configuration{
        return $this->_config;
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
