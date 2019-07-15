<?php

namespace sacy\transforms\external;

use sacy\internal\ExternalProcessor;

class Less extends ExternalProcessor{
    protected function getCommandLine($filename, $opts=array()){
        if (!is_executable(SACY_TRANSFORMER_LESS)){
            throw new \Exception('SACY_TRANSFORMER_LESS defined but not executable');
        }
        return sprintf(
            '%s -I%s -',
            SACY_TRANSFORMER_LESS,
            escapeshellarg(dirname($filename))
        );
    }
}
