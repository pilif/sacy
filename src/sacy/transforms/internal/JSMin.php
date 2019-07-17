<?php

namespace sacy\transforms\internal;

use sacy\Transformer;

class JSMin implements Transformer {
    function transform(string $in_content, ?string $in_file, array $options = []): string {
        return \JSMin\JSMin::minify($in_content);
    }
}
