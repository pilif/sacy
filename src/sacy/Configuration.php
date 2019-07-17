<?php

namespace sacy;

interface Configuration {
    function getOutputDir(): string;
    function getUrlRoot(): string;
    function getFragmentCache(): Cache;
    function getServerParams(): array;
    function getDebugMode():  int;
    function useContentBasedCache(): bool;
    function writeHeaders(): bool;
    function getTransformRepository(): TransformRepository;
    function getDependencyCacheFile(): string;
    function getDebugToggle();
}
