<?php

if (!defined("____SACY_BUNDLED"))
    include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'sacy', 'sacy.php')));

if (!(defined("ASSET_COMPILE_OUTPUT_DIR") && defined("ASSET_COMPILE_URL_ROOT"))){
    throw new sacy_Exception("Failed to initialize becuase path configuration is not set (ASSET_COMPILE_OUTPUT_DIR and ASSET_COMPILE_URL_ROOT)");
}

function smarty_block_asset_compile($params, $content, &$smarty, &$repeat){
    if (!$repeat){
        // don't shoot me, but all tried using the dom-parser and removing elements
        // ended up with problems due to the annoying DOM API and braindead stuff
        // like no getDocumentElement() or getElementByID() not working even though
        // loadHTML clearly knows that the content is in fact HTML.
        //
        // So, let's go back to good old regexps :-)

        $cfg = new sacy_Config($params);
        if ($cfg->getDebugMode() == 1 ){
            return $content;
        }

        $tags = array('link', 'script');
        $tag_pattern = '#\s*<\s*T\s+(.*)\s*(?:/>|>(.*)</T>)\s*#Uim';
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
                                  // tag, attrdata, index in doc, whole tag, content, order of appearance
            }
        }

        // now sort task list by descending location offset
        // by the way: I want widespread 5.3 adoption for anonymous functions
        usort($work, create_function('$a,$b', 'if ($a[2] == $b[2]) return 0; return ($a[2] < $b[2]) ? 1 : -1;'));
        $ex = new sacy_FileExtractor($cfg);
        $files = array();

        foreach($work as $unit){
            $r = $ex->extractFile($unit[0], $unit[1], $unit[4]);
            if ($r === false) continue; // handler has declined
            $r = array_merge($r, array(
                'page_order' => $unit[5],
                'position' => $unit[2],
                'length' => strlen($unit[3]),
                'tag' => $unit[0]
            ));
            $r[] = $unit[0];
            $files[] = $r;
        }

        $renderer = new sacy_CacheRenderer($cfg, $smarty);
        $patched_content = $content;

        $render = array();
        $curr_cat = $files[0]['group'].$files[0]['tag'];

        foreach($files as $i => $entry){
            $cg = $entry['group'].$entry['tag'];

            // the moment the category changes, render all we have so far
            // this makes it IMPERATIVE to keep links of the same category
            // together.
            if ($curr_cat != $cg || ($cfg->getDebugMode() == 3 && count($render))){
                $render_order = array_reverse($render);
                $res = $renderer->renderFiles($files[$i-1]['tag'], $files[$i-1]['group'], $render_order);
                if ($res === false){
                    // rendering failed.
                    // because we don't know which one, we just enter emergency mode
                    // and return the initial content unharmed:
                    return $content;
                }
                // add rendered stuff to patched content
                $m = null;
                foreach($render as $r){
                    if ($m == null) $m = $r['position'];
                    if ($r['position'] < $m) $m = $r['position'];
                    // remove tag
                    $patched_content = substr_replace($patched_content, '', $r['position'], $r['length']);
                }
                // splice in replacement
                $patched_content = substr_replace($patched_content, $res, $m, 0);
                $curr_cat = $cg;
                $render = array($entry);
            }else{
                $render[] = $entry;
            }
        }
        $render_order = array_reverse($render);
        $res = $renderer->renderFiles($entry['tag'], $entry['group'], $render_order);
        if ($res === false){
            // see last comment
            return $content;
        }
        $m = null;
        foreach($render as $r){
            if ($m == null) $m = $r['position'];
            if ($r['position'] < $m) $m = $r['position'];
            // remove tag
            $patched_content = substr_replace($patched_content, '', $r['position'], $r['length']);
        }
        $patched_content = substr_replace($patched_content, $res, $m, 0);
        return $patched_content;
    }
}
