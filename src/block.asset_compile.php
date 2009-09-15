<?php

define("ASSET_COMPILE_OUTPUT_DIR", APP_ROOT.'/pcache/asset_compile');
define("ASSET_COMPILE_URL_ROOT", '/csscache');

function smarty_block_asset_compile($params, $content, &$smarty, &$repeat){
    if (!$repeat){
        // don't shoot me, but all tried using the dom-parser and removing elements
        // ended up with problems due to the annoying DOM API and braindead stuff
        // like no getDocumentElement() or getElementByID() not working even though
        // loadHTML clearly knows that the content is in fact HTML.
        //
        // So, let's go back to good old regexps :-)

        $link_tag_pattern = '#<\s*link\s+(.*)\s*(?:/>|>(?:.*)</link>)#Ui';
        if(!preg_match_all($link_tag_pattern, $content, $links))
            return $content; // nothing to do

        $cssfiles = array();
        foreach($links[1] as $link){
            $href = "";

            // handle href="foo'bar"
            if (preg_match('#href\s*=\s*"\s*(.*)\s*"#U', $link, $m)){
                $href = html_entity_decode($m[1]);
            }
            // handle href='foo"bar' - and no, I can't match ["'] on both ends
            if (preg_match('#href\s*=\s*\'\s*(.*)\s*\'#U', $link, $m)){
                $href = html_entity_decode($m[1]);
            }
            // probably something else
            if (!$href) continue;

            $path = array($_SERVER['DOCUMENT_ROOT']);
            if ($href[0] != '/')
                $path[] = $_SERVER['PHP_SELF'];
            $path[] = $href;
            $path = realpath(implode(DIRECTORY_SEPARATOR, $path));
            if ($path)
                $cssfiles[] = $path;
        }
        if (count($cssfiles) > 0){
            $href = asset_compile_generate_cache($smarty, $cssfiles);
        }
        if ($href !== false){
            $content = trim(preg_replace($link_tag_pattern, '', $content));
            $content .= sprintf('<link rel="stylesheet" type="text/css" href="%s">',
                htmlentities($href, ENT_QUOTES));
        }
        return $content;
    }
}

function asset_compile_generate_cache(&$smarty, $cssfiles){
    if (!is_dir(ASSET_COMPILE_OUTPUT_DIR))
        mkdir(ASSET_COMPILE_OUTPUT_DIR);

    $f = create_function('$f', 'return basename($f, ".css");');
    $ident = implode('-', array_map($f, $cssfiles));
    $max = 0;
    foreach($cssfiles as $f){
        $max = max($max, filemtime($f));
    }
    // not using the actual content for quicker access
    $key = md5($max . serialize($cssfiles));
    $cfile = ASSET_COMPILE_OUTPUT_DIR . DIRECTORY_SEPARATOR ."$ident-$key.css";
    $pub = ASSET_COMPILE_URL_ROOT . "/$ident-$key.css";

    if (file_exists($cfile)){
        return $pub;
    }

    if (!asset_compile_write_cache($smarty, $cfile, $cssfiles)){
        return false;
    }

    return $pub;
}

function asset_compile_write_cache(&$smarty, $cfile, $files){
    $lockfile = $cfile.".lock";
    $fhl = @fopen($lockfile, 'w');
    if (!$fhl){
        $smarty->trigger_error("Cannot create cache-lockfile: $lockfile");
        return false;
    }
    $wb = false;
    if (!@flock($fhl, LOCK_EX | LOCK_NB, $wb)){
        $smarty->trigger_error("Canot lock cache-lockfile: $lockfile");
        return false;
    }
    if ($wb){
        // another process is writing the cache. Let's just return false
        // the caller will leave the CSS unaltered
        return false;
    }
    $fhc = @fopen($cfile, 'w');
    if (!$fhc){
        $smarty->trigger_error("Cannot open cache file: $cfile");
        fclose($fhl);
        unlink($lockfile);
        return false;
    }
    fwrite($fhc, "/* smarty_asset_compile css cache dump \n");
    fwrite($fhc, "This dump has been created from the following files:\n");
    foreach($files as $file){
        fprintf($fhc, "    - %s\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file));
    }
    fwrite($fhc, "*/\n\n");
    foreach($files as $file){
        fprintf($fhc, "\n/* %s */\n", str_replace($_SERVER['DOCUMENT_ROOT'], '<root>', $file));
        $css = @file_get_contents($file);
        if ($css == false){
            fwrite($fhc, "/* <Error accessing file> */\n");
            $smarty->trigger_error("Error accessing CSS-File: $file");
            continue;
        }
        $css = str_replace("\r\n", "\n", $css);
        $css = str_replace("\r", "\n", $css);
        $css = str_replace("\n", " ", $css);
        $css = preg_replace('#\s+#', ' ', $css);
        fwrite($fhc, $css);
    }
    fclose($fhc);
    fclose($fhl);
    unlink($lockfile);
    return true;
}

?>