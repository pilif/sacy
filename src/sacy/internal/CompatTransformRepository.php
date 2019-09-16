<?php

namespace sacy\internal;

use sacy\Transformer;
use sacy\TransformRepository;
use sacy\transforms\external\Coffee;
use sacy\transforms\external\Eco;
use sacy\transforms\external\JSX;
use sacy\transforms\external\Sass;
use sacy\transforms\external\Scss;
use sacy\transforms\external\Uglify;
use sacy\transforms\internal\Coffeescript;
use sacy\transforms\internal\JSMin;
use sacy\transforms\internal\Scssphp;
use sacy\transforms\PhpSassSacy;

class CompatTransformRepository implements TransformRepository {
    private $known_transforms = [];
    private $known_compressors = [];

    function __construct() {
        $this->loadLess();
        $this->loadSass();
        $this->loadJsCompressors();
        $this->loadJSTransforms();
    }

    private function loadJSTransforms(){
        if (class_exists('\CoffeeScript\Compiler')){
            $this->known_transforms['text/coffeescript'] = new Coffeescript();
        }else if (defined('SACY_TRANSFORMER_COFFEE')){
            $this->known_transforms['text/coffeescript'] = new Coffee(SACY_TRANSFORMER_COFFEE);
        }
        if (defined('SACY_TRANSFORMER_ECO')){
            $this->known_transforms['text/x-eco'] = new Eco(SACY_TRANSFORMER_ECO);
        }
        if (defined('SACY_TRANSFORMER_JSX')){
            $this->known_transforms['text/x-jsx'] = new JSX(SACY_TRANSFORMER_JSX);
        }
    }

    private function loadJSCompressors(){
        if (class_exists('\JSMin\JSMin')){
            $this->known_compressors['text/javascript'] = new JSMin();
            return;
        }
        if (defined('SACY_COMPRESSOR_UGLIFY')){
            $this->known_compressors['text/javascript'] = new Uglify(SACY_COMPRESSOR_UGLIFY);
        }
    }

    private function loadSass(){
        if (PhpSassSacy::isAvailable()){
            $this->known_transforms['text/x-scss'] = new PhpSassSacy();
            return;
        }
        if (class_exists('ScssPhp\ScssPhp\Compiler')){
            $this->known_transforms['text/x-scss'] = new Scssphp();
            return;
        }
        if (defined('SACY_TRANSFORMER_SASS')){
            $this->known_transforms['text/x-scss'] = new Scss(SACY_TRANSFORMER_SASS);
            $this->known_transforms['text/x-sass'] = new Sass(SACY_TRANSFORMER_SASS);
        }
    }

    private function loadLess(){
        if (defined('SACY_TRANSFORMER_LESS')){
            $this->known_transforms['text/x-less'] = new \sacy\transforms\external\Less(defined('SACY_TRANSFORMER_LESS'));
            return;
        }

        if (class_exists('lessc')) {
            $this->known_transforms['text/x-less'] = new \sacy\transforms\internal\Less();
            return;
        }
    }

    public function supportsType(string $type): bool {
        return array_key_exists($type, $this->known_transforms);
    }

    public function getTransformerForType(string $type): ?Transformer {
        return $this->known_transforms[$type] ?? null;
    }

    public function getCompressorForType($type): ?Transformer {
        return $this->known_compressors[$type] ?? null;
    }

    public function getSupportedTypes(): array {
        return array_unique(array_merge(
            array_keys($this->known_transforms),
            array_keys($this->known_compressors)
        ));
    }
}
