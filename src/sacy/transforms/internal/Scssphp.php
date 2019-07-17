<?php

namespace sacy\transforms\internal;

use sacy\Transformer;
use ScssPhp\ScssPhp\Compiler;

class Scssphp implements Transformer {
    private $compiler;

    function __construct() {
        $this->compiler = new Compiler();
    }

    function transform(string $in_content, ?string $in_file, array $options = []): string {
        if ($in_file) {
            $this->compiler->setImportPaths([dirname($in_file)]);
        }
        return $this->compiler->compile($in_content, $in_file);
    }
}
