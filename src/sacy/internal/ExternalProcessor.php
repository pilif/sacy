<?php

namespace sacy\internal;

use sacy\Transformer;

abstract class ExternalProcessor implements Transformer {
    private $executable;

    function __construct($executable) {
        if (!is_executable($executable)){
            throw new \InvalidArgumentException("$executable is not executable");
        }
        $this->executable = $executable;
    }

    abstract protected function getCommandLine($filename, $opts=array());

    protected function getExecutable(): string {
        return $this->executable;
    }

    function transform(string $in_content, ?string $filename, array $opts=array()): string{
        $s = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        );
        $cmd = $this->getCommandLine($filename, $opts);
        $env_vars = [];
        if (array_key_exists('env', $opts) && is_array($opts['env'])){
            foreach($opts['env'] as $k => $v)
                $env_vars[] = sprintf("%s=%s", $k, $v);
        }
        $cmd_string = implode(' ', $env_vars). ' ' . $cmd;

        $p = proc_open($cmd_string, $s, $pipes);
        if (!is_resource($p))
            throw new \Exception("Failed to execute $cmd");

        fwrite($pipes[0], $in_content);
        fclose($pipes[0]);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $r = proc_close($p);

        if ($r != 0){
            throw new \Exception("Command returned $r: $err");
        }
        return $out;
    }
}
