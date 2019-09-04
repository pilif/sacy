<?php

namespace sacy\internal;

use sacy\Configuration;
use sacy\Exception;

class BlockParams implements \JsonSerializable {
    private $params;

    public function get($key){
        return $this->params[$key];
    }

    public function __construct(Configuration $config, $params = null){
        $this->params['query_strings'] = 'ignore';
        $this->params['write_headers'] = $config->writeHeaders();
        $this->params['debug_toggle']  = $config->getDebugToggle();
        $this->params['sassc_plugins'] = [];
        $this->params['debug_mode'] = $config->getDebugMode();
        $this->params['server_params'] = $config->getServerParams();

        $this->params['merge_tags'] = false;

        if (is_array($params))
            $this->setParams($params);
    }

    public function getDebugMode(){
        if ($this->params['debug_toggle'] === false)
            return $this->params['debug_mode'];
        if (isset($_GET[$this->params['debug_toggle']]))
            return intval($_GET[$this->params['debug_toggle']]);
        if (isset($_COOKIE[$this->params['debug_toggle']]))
            return intval($_COOKIE[$this->params['debug_toggle']]);
        return 0;

    }

    public function setParams($params){
        foreach($params as $key => $value){
            if (!in_array($key, array('debug_mode', 'sassc_plugins', 'merge_tags', 'query_strings', 'write_headers', 'debug_toggle', 'env', 'cache_version_id')))
                throw new Exception("Invalid option: $key");
        }
        if (isset($params['query_strings']) && !in_array($params['query_strings'], array('force-handle', 'ignore')))
            throw new Exception("Invalid setting for query_strings: ".$params['query_strings']);
        if (isset($params['write_headers']) && !in_array($params['write_headers'], array(true, false), true))
            throw new Exception("Invalid setting for write_headers: ".$params['write_headers']);
        $params['merge_tags'] = !empty($params['merge_tags']);

        $this->params = array_merge($this->params, $params);
    }

    function jsonSerialize(){
        return $this->params;
    }
}
