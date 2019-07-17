<?php
namespace sacy\transforms;

use sacy\Transformer;

class PhpSassSacy implements Transformer {
    static function isAvailable(){
        return extension_loaded('sass') && defined('SASS_FLAVOR') && SASS_FLAVOR == 'sensational';
    }

    static function compile($file, $load_path){
        $sass = new \Sass();
        $sass->setIncludePath(implode(':', $load_path));
        return $sass->compile_file($file);
    }

    function transform(string $in_content, ?string $in_file, array $options = []): string {
        $compiler = $this->getCompiler();
        $compiler->setIncludePath(implode(':', array_merge([dirname($in_file)], $options['load_path'] ?? [])));
        return $compiler->compile($in_content, $in_file);
    }

    private $compiler = null;
    private function getCompiler(): \Sass {
        if ($this->compiler === null){
            $this->compiler = new \Sass();
        }
        return $this->compiler;
    }
}
