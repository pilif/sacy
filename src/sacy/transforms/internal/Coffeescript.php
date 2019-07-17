<?php

namespace sacy\transforms\internal;

use CoffeeScript\Compiler;
use sacy\Transformer;

class Coffeescript implements Transformer {

    function transform(string $in_content, ?string $in_file, array $options = []): string {
        return Compiler::compile($in_content, array('filename' => $in_file));
    }
}
