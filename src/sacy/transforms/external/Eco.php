<?php

namespace sacy\transforms\external;

use sacy\Exception;
use sacy\internal\ExternalProcessor;

class Eco extends ExternalProcessor{
    protected function getType(){
        return 'text/x-eco';
    }

    protected function getCommandLine($filename, $opts=array()){
        // Calling eco with the filename here. Using stdin wouldn't
        // cut it, as eco uses the filename to figure out the name of
        // the js function it outputs.
        $eco_root = $opts['eco-root'];
        return sprintf('%s %s -p %s',
            $this->getExecutable(),
            $eco_root ? sprintf('-i %s', escapeshellarg($eco_root)) : '',
            escapeshellarg($filename)
        );
    }
}
