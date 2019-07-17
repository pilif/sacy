<?php

namespace sacy\transforms\external;

use sacy\internal\ExternalProcessor;

class Less extends ExternalProcessor{

    protected function getCommandLine($filename, $opts=array()){
        return sprintf(
            '%s -I%s -',
            $this->getExecutable(),
            escapeshellarg(dirname($filename))
        );
    }
}
