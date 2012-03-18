<?php
class CoffeeScript{

    public static function build($file){
        return CoffeeScript\compile($file);
    }
}