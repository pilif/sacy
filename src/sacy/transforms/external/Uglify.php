<?php

namespace sacy\transforms\external;

use sacy\internal\ExternalProcessor;

class Uglify extends ExternalProcessor{

    protected function getCommandLine($filename, $opts=array()){
        return $this->getExecutable();
    }
}
