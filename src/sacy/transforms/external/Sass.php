<?php

namespace sacy\transforms\external;

use sacy\internal\ExternalProcessor;

class Sass extends ExternalProcessor{
    protected function getType(){
        return 'text/x-sass';
    }

    protected function getCommandLine($filename, $opts=array()){
        $libpath = $opts['library_path'] ?: [dirname($filename)];
        $libpath[] = $opts['document_root'] ?: getcwd();

        $plugins = implode(' ', (is_array($opts['plugin_files']))
            ? array_filter(array_map(function($f){
                if (!is_file($f)) return null;
                return sprintf('-r %s', escapeshellarg($f));
            }, $opts['plugin_files'])) : []);

        $path =
            implode(' ', array_map(function($p){ return '-I '.escapeshellarg($p); }, array_unique($libpath)));

        return sprintf('%s --cache-location=%s -s %s %s %s',
            $this->getExecutable(),
            escapeshellarg(sys_get_temp_dir()),
            $this->getType() == 'text/x-scss' ? '--scss' : '',
            $plugins,
            $path
        );
    }
}
