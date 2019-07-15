<?php

namespace sacy\transforms\external;

use sacy\Exception;
use sacy\internal\ExternalProcessor;

class Coffee extends ExternalProcessor{
    protected function getCommandLine($filename, $opts=array()){
        if (!is_executable(SACY_TRANSFORMER_COFFEE)){
            throw new Exception('SACY_TRANSFORMER_COFFEE defined but not executable');
        }
        return sprintf('%s -c -s', SACY_TRANSFORMER_COFFEE);
    }
}
