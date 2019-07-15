<?php

namespace sacy\internal;

use sacy\ExternalProcessorRegistry;
use sacy\internal\ConfiguredRenderHandler;

class JavaScriptRenderHandler extends ConfiguredRenderHandler{
    static function supportedTransformations(){
        $supported = array();

        if (function_exists('CoffeeScript\compile') || ExternalProcessorRegistry::typeIsSupported('text/coffeescript'))
            $supported[] = 'text/coffeescript';

        if (ExternalProcessorRegistry::typeIsSupported('text/x-eco'))
            $supported[] = 'text/x-eco';

        if (ExternalProcessorRegistry::typeIsSupported('text/x-jsx'))
            $supported[] = 'text/x-jsx';

        return $supported;
    }

    static function willTransformType($type){
        // transforming everything but plain old CSS
        return in_array($type, self::supportedTransformations());
    }

    function getFileExtension() { return '.js'; }

    function writeHeader($fh, $work_units){
        fwrite($fh, "/*\nsacy javascript cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($work_units as $file){
            fprintf($fh, "    - %s\n", str_replace($this->getConfig()->get('server_params')['DOCUMENT_ROOT'], '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function getOutput($work_unit){
        $debug = $this->getConfig()->getDebugMode() == 3;
        if ($work_unit['file']){
            $js = @file_get_contents($work_unit['file']);
            if (!$js) return "/* error accessing file */";
            $source_file = $work_unit['file'];
        }else{
            $js = $work_unit['content'];
            $source_file = $this->getSourceFile();
        }

        if ($work_unit['type'] == 'text/coffeescript'){
            $js = ExternalProcessorRegistry::typeIsSupported('text/coffeescript') ?
                ExternalProcessorRegistry::getTransformerForType('text/coffeescript')->transform($js, $source_file, ['document_root' => $this->getConfig()->get('server_params')['DOCUMENT_ROOT']]) :
                \Coffeescript::build($js);
        } else if ($work_unit['type'] == 'text/x-eco'){
            $eco = ExternalProcessorRegistry::getTransformerForType('text/x-eco');
            $js = $eco->transform($js, $source_file, $work_unit['data'], ['document_root' => $this->getConfig()->get('server_params')['DOCUMENT_ROOT']]);
        } else if ($work_unit['type'] == 'text/x-jsx'){
            $jsx = ExternalProcessorRegistry::getTransformerForType('text/x-jsx');
            $js = $jsx->transform($js, $source_file, $work_unit['data'], ['document_root' => $this->getConfig()->get('server_params')['DOCUMENT_ROOT']]);
        }

        if ($debug){
            return $js;
        }else{
            return ExternalProcessorRegistry::typeIsSupported('text/javascript') ?
                ExternalProcessorRegistry::getCompressorForType('text/javascript')->transform($js, $source_file, ['document_root' => $this->getConfig()->get('server_params')['DOCUMENT_ROOT']]) :
                \JSMin::minify($js);
        }

    }

    function processFile($fh, $work_unit){
        if ($this->getConfig()->get('write_headers'))
            fprintf($fh, "\n/* %s */\n", str_replace($this->getConfig()->get('server_params')['DOCUMENT_ROOT'], '<root>', $work_unit['file']));
        fwrite($fh, $this->getOutput($work_unit));
    }

    function getAdditionalFiles($work_unit) {
        return [];
    }
}
