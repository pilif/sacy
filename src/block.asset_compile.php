<?php

if (!defined("____SACY_BUNDLED"))
    include_once(implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), 'sacy', 'sacy.php')));

if (!(defined("ASSET_COMPILE_OUTPUT_DIR") && defined("ASSET_COMPILE_URL_ROOT"))){
    throw new sacy\Exception("Failed to initialize because path configuration is not set (ASSET_COMPILE_OUTPUT_DIR and ASSET_COMPILE_URL_ROOT)");
}

function smarty_block_asset_compile($params, $content, &$smarty, &$repeat){
    if (!$repeat){
        // don't shoot me, but all tried using the dom-parser and removing elements
        // ended up with problems due to the annoying DOM API and braindead stuff
        // like no getDocumentElement() or getElementByID() not working even though
        // loadHTML clearly knows that the content is in fact HTML.
        //
        // So, let's go back to good old regexps :-)

        $cfg = new sacy\Config($params);
        if ($cfg->getDebugMode() == 1 ){
            return $content;
        }

        $tag_pattern = '#\s*<\s*T(?:\s+(.*))?\s*(?:/>|>(.*)</T>)\s*#Uims';
        $tags = array();

        $aindex = 0;

        // first assembling all work. The idea is that, if sorted by descending
        // location offset, we can selectively remove tags.
        //
        // We'll need that to conditionally handle tags (like jTemplate's
        // <script type="text/html" that should remain)
        foreach(array('link', 'style', 'script') as $tag){
            $p = str_replace('T', preg_quote($tag), $tag_pattern);
            if(preg_match_all($p, $content, $ms, PREG_OFFSET_CAPTURE)){
                foreach($ms[1] as $i => $m){
                    $tags[] = array(
                        'tag' => $tag,
                        'attrdata' => $m[0],
                        'index' => $ms[0][$i][1],
                        'tagdata' => $ms[0][$i][0],
                        'content' => $ms[2][$i][0],
                        'page_order' => $aindex++
                    );
                }
            }
        }

        // now sort task list by descending location offset
        usort($tags, function($a, $b){
            if ($a['index'] == $b['index']) return 0;
            return ($a['index'] < $b['index']) ? 1 : -1;
        });
        $ex = new sacy\WorkUnitExtractor($cfg);
        $work_units = $ex->getAcceptedWorkUnits($tags);

        $renderer = new sacy\CacheRenderer($cfg, $_SERVER['SCRIPT_FILENAME']);
        $patched_content = $content;

        $render = array();
        $category = function($work_unit){
            return implode('', array($work_unit['group'], $work_unit['tag'], !!$work_unit['file']));
        };
        $curr_cat = $category($work_units[0]);

        $entry = null;
        foreach($work_units as $i => $entry){
            $cg = $category($entry);

            // the moment the category changes, render all we have so far
            // this makes it IMPERATIVE to keep links of the same category
            // together.
            if ($curr_cat != $cg || ($cfg->getDebugMode() == 3 && count($render))){
                $render_order = array_reverse($render);
                $res = $renderer->renderWorkUnits($work_units[$i-1]['tag'], $work_units[$i-1]['group'], $render_order);
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
        if ($work_units){
            $res = $renderer->renderWorkUnits($entry['tag'], $entry['group'], $render_order);
            if ($res === false){
                // see last comment
                return $content;
            }
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
