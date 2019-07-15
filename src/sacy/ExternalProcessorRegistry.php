<?php

namespace sacy;

use sacy\internal\ExternalProcessor;

class ExternalProcessorRegistry{
    private static $transformers;
    private static $compressors;

    public static function registerTransformer($type, $cls){
        self::$transformers[$type] = $cls;
    }

    public static function registerCompressor($type, $cls){
        self::$compressors[$type] = $cls;
    }

    private static function lookup($type, $in){
        return (isset($in[$type])) ? new $in[$type]() : null;
    }

    public static function typeIsSupported($type){
        return isset(self::$transformers[$type]) ||
            isset(self::$compressors[$type]);
    }

    /**
     * @static
     * @param $type string mime type of input
     * @return ExternalProcessor
     */
    public static function getTransformerForType($type){
        return self::lookup($type, self::$transformers);
    }

    /**
     * @static
     * @param $type string mime type of input
     * @return ExternalProcessor
     */
    public static function getCompressorForType($type){
        return self::lookup($type, self::$compressors);
    }

}
