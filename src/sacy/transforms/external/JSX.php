<?php

namespace sacy\transforms\external;

use sacy\Exception;
use sacy\internal\ExternalProcessor;

class JSX extends ExternalProcessor{
    protected function getCommandLine($filename, $opts=array()){
        if (!is_executable(SACY_TRANSFORMER_JSX)){
            throw new Exception('SACY_TRANSFORMER_JSX defined but not executable');
        }
        return SACY_TRANSFORMER_JSX;
    }
}
