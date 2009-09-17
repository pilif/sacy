<?php

define("ASSET_COMPILE_OUTPUT_DIR", APP_ROOT.'/pcache/asset_compile');
define("ASSET_COMPILE_URL_ROOT", '/assetcache');
//define("DEBUG", true);

include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'sacy', 'sacy.php')));

function smarty_block_asset_compile($params, $content, &$smarty, &$repeat){
    if (!$repeat){
        // don't shoot me, but all tried using the dom-parser and removing elements
        // ended up with problems due to the annoying DOM API and braindead stuff
        // like no getDocumentElement() or getElementByID() not working even though
        // loadHTML clearly knows that the content is in fact HTML.
        //
        // So, let's go back to good old regexps :-)

        $tags = array('link', 'script');
        $tag_pattern = '#<\s*T\s+(.*)\s*(?:/>|>(.*)</T>)#Ui';
        $work = array();
        $aindex = 0;

        // first assembling all work. The idea is that, if sorted by descending
        // location offset, we can selectively remove tags.
        //
        // We'll need that to conditionally handle tags (like jTemplate's
        // <script type="text/html" that should remain)
        foreach($tags as $tag){
            $p = str_replace('T', preg_quote($tag), $tag_pattern);
            if(preg_match_all($p, $content, $ms, PREG_OFFSET_CAPTURE)){
                foreach($ms[1] as $i => $m)
                   $work[] = array($tag, $m[0], $ms[0][$i][1], $ms[0][$i][0], $ms[2][$i][0], $aindex++);
            }
        }
        // now sort task list by descending location offset
        // by the way: I want widespread 5.3 adoption for anonymous functions
        usort($work, create_function('$a,$b', 'if ($a[2] == $b[2]) return 0; return ($a[2] < $b[2]) ? 1 : -1;'));
        $ex = new sacy_FileExtractor();
        $files = array();
        $patched_content = $content;
        foreach($work as $unit){
            $r = $ex->extractFile($unit[0], $unit[1], $unit[4]);
            if ($r === false) continue; // handler has declined
            $r[] = $unit[5]; //append appearance order index

            // remove tag
            $patched_content = substr_replace($patched_content, '', $unit[2], strlen($unit[3]));
            $files[$unit[0]][] = $r;
        }

        $renderer = new sacy_CacheRenderer($smarty);
        $rendered_content = "";

        // now put the files back in order of appearance in the original template
        foreach($files as $tag => &$f){
            usort($f, create_function('$a,$b', 'if ($a[2] == $b[2]) return 0; return ($a[2] > $b[2]) ? 1 : -1;'));
            $render = array();
            $curr_cat = $f[0][0];
            foreach($f as $fileentry){
                // the moment the category changes, render all we have so far
                // this makes it IMPERATIVE to keep links of the same category
                // together.
                if ($curr_cat != $fileentry[0]){
                    $res = $renderer->renderFiles($tag, $curr_cat, $render);
                    if ($res === false){
                        // rendering failed.
                        // because we don't know which one, we just enter emergency mode
                        // and return the initial content unharmed:
                        return $content;
                    }
                    // add redered stuff to patched content
                    $rendered_content .= $res;
                    $curr_cat = $fileentry[0];
                    $render = array($fileentry[1]);
                }else{
                    $render[] = $fileentry[1];
                }
            }
            $res = $renderer->renderFiles($tag, $curr_cat, $render);
            if ($res === false){
                // see last comment
                return $content;
            }
            $rendered_content .= $res;
        }

        return $rendered_content.$patched_content;
    }
}

?>