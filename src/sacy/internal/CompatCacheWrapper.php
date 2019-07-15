<?php

namespace sacy\internal;

use sacy\Cache;

class CompatCacheWrapper implements Cache {
    private $wrapped;

    function __construct($cls) {
        $this->wrapped = new $cls();
    }

    function get($key) {
        return $this->wrapped->get($key);
    }

    function set($key, $value) {
        return $this->wrapped->set($key, $value);
    }
}
