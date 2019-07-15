<?php

namespace sacy\transforms\external;

use sacy\Exception;
use sacy\internal\ExternalProcessor;

class Uglify extends ExternalProcessor{
    protected function getCommandLine($filename, $opts=array()){
        if (!is_executable(SACY_COMPRESSOR_UGLIFY)){
            throw new Exception('SACY_COMPRESSOR_UGLIFY defined but not executable');
        }
        return SACY_COMPRESSOR_UGLIFY;
    }
}
