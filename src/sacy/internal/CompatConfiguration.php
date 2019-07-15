<?php

namespace sacy\internal;

use sacy\Cache;
use sacy\Configuration;
use sacy\Exception;

class CompatConfiguration implements Configuration {
    function __construct() {
        $required_compat_defines = [
            'ASSET_COMPILE_OUTPUT_DIR',
            'ASSET_COMPILE_URL_ROOT',
        ];
        foreach($required_compat_defines as $d){
            if (!defined($d)){
                throw new Exception("Failed to initialize because path configuration is not set (ASSET_COMPILE_OUTPUT_DIR and ASSET_COMPILE_URL_ROOT)");
            }
        }
    }

    function getOutputDir(): string {
        return ASSET_COMPILE_OUTPUT_DIR;
    }

    function getUrlRoot(): string {
        return ASSET_COMPILE_URL_ROOT;
    }

    private $cache_inst = null;
    function getFragmentCache(): Cache {
        if ($this->cache_inst === null){
            $this->cache_inst = (defined('SACY_FRAGMENT_CACHE_CLASS'))
                ? new CompatCacheWrapper(SACY_FRAGMENT_CACHE_CLASS)
                : new FileCache();
        }
        return $this->cache_inst;
    }

    function getServerParams(): array {
        return $_SERVER;
    }

    function getDebugMode(): int {
        return defined('SACY_DEBUG_MODE') ? SACY_DEBUG_MODE : 0;
    }

    function useContentBasedCache(): bool {
        return defined('SACY_USE_CONTENT_BASED_CACHE') && SACY_USE_CONTENT_BASED_CACHE;
    }

    function writeHeaders(): bool {
        return defined('SACY_WRITE_HEADERS') ? SACY_WRITE_HEADERS : true;
    }
}
