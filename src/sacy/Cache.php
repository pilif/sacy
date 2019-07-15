<?php

namespace sacy;

interface Cache{
    function get($key);
    function set($key, $value);
}
