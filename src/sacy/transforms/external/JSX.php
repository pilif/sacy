<?php

namespace sacy\transforms\external;

use sacy\Exception;
use sacy\internal\ExternalProcessor;

class JSX extends ExternalProcessor{
    protected function getCommandLine($filename, $opts=array()){
        return $this->getExecutable();
    }
}
