<?php

namespace sacy;

interface TransformRepository {
    public function getSupportedTypes(): array;
    public function supportsType(string $type): bool;
    public function getTransformerForType(string $type): Transformer;
    public function getCompressorForType($type): Transformer;
}
