<?php
namespace sacy;

class PhpSassSacy {
    static function isAvailable(){
        return extension_loaded('sass') && defined('SASS_FLAVOR') && SASS_FLAVOR == 'sensational';
    }

    static function compile($file, $load_path){
        $sass = new \Sass();
        $sass->setIncludePath(implode(':', $load_path));
        return $sass->compile_file($file);
    }

}
