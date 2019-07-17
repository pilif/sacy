<?php

namespace sacy\internal;

use sacy\ExternalProcessorRegistry;
use sacy\internal\ConfiguredRenderHandler;

class JavaScriptRenderHandler extends ConfiguredRenderHandler{

    static function willTransformType($type){
        // transforming everything but plain old CSS
        return $type != 'text/javascript';
    }

    function getFileExtension() { return '.js'; }

    function writeHeader($fh, $work_units){
        fwrite($fh, "/*\nsacy javascript cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($work_units as $file){
            fprintf($fh, "    - %s\n", str_replace($this->getParams()->get('server_params')['DOCUMENT_ROOT'], '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function getOutput($work_unit){
        $debug = $this->getParams()->getDebugMode() == 3;
        if ($work_unit['file']){
            $js = @file_get_contents($work_unit['file']);
            if (!$js) return "/* error accessing file */";
            $source_file = $work_unit['file'];
        }else{
            $js = $work_unit['content'];
            $source_file = $this->getSourceFile();
        }

        if ($t = ($this->getConfig()->getTransformRepository()->getTransformerForType($work_unit['type']))){
            $js = $t->transform($js, $source_file, ['document_root' => $this->getParams()->get('server_params')['DOCUMENT_ROOT']]);
        }

        if ($debug){
            return $js;
        }else{
            if (($c = $this->getConfig()->getTransformRepository()->getCompressorForType('text/javascript'))){
                $js = $c->transform($js, null);
            }
            return $js;
        }

    }

    function processFile($fh, $work_unit){
        if ($this->getParams()->get('write_headers'))
            fprintf($fh, "\n/* %s */\n", str_replace($this->getParams()->get('server_params')['DOCUMENT_ROOT'], '<root>', $work_unit['file']));
        fwrite($fh, $this->getOutput($work_unit));
    }

    function getAdditionalFiles($work_unit) {
        return [];
    }
}
