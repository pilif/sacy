<?php

namespace sacy\transforms\external;

class Scss extends Sass{
    protected function getType(){
        return 'text/x-scss';
    }
}
