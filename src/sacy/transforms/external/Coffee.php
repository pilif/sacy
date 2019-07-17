<?php

namespace sacy\transforms\external;

use sacy\Exception;
use sacy\internal\ExternalProcessor;

class Coffee extends ExternalProcessor{
    protected function getCommandLine($filename, $opts=array()){
        return sprintf('%s -c -s', $this->getExecutable());
    }
}
