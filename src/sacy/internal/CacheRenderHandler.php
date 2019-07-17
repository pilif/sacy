<?php
namespace sacy\internal;

use sacy\Configuration;
use sacy\internal\BlockParams;

interface CacheRenderHandler{
    function __construct(Configuration $cfg, BlockParams $params, $fragment_cache, $source_file);
    function getFileExtension();
    static function willTransformType($type);
    function writeHeader($fh, $work_units);
    function getAdditionalFiles($work_unit);
    function processFile($fh, $work_unit);
    function startWrite();
    function endWrite($fh);
    function getOutput($work_unit);
    function getParams(): BlockParams;
    function getConfig(): Configuration;
}
