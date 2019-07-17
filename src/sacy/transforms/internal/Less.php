<?php

namespace sacy\transforms\internal;

use sacy\Transformer;

class Less implements Transformer {
    private $compiler;

    function __construct() {
        $this->compiler = new \lessc();
    }

    function transform(string $in_content, ?string $in_file, array $options = []): string {
        $this->compiler->setImportDir(dirname($in_file)); #lessphp concatenates without a /
        return $this->compiler->compile($in_content);
    }
}
