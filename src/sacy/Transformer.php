<?php

namespace sacy;

interface Transformer {
    function transform(string $in, string $out, array $options=[]): string;
}
