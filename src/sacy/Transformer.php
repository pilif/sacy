<?php

namespace sacy;

interface Transformer {
    function transform(string $in_content, ?string $in_file, array $options=[]): string;
}
