<?php

namespace sacy;

use sacy\internal\BlockParams;
use sacy\internal\CacheRenderer;
use sacy\internal\CompatConfiguration;
use sacy\internal\WorkUnitExtractor;

class Sacy {
    /** @var Sacy */
    private static $instance = null;

    private $config;

    static function registerGlobalCompatHandler(Configuration $config=null){
        if (static::$instance !== null) return;
        static::$instance = new static($config ?? new CompatConfiguration());

        // needing eval to put function in global namespace
        eval('function smarty_block_asset_compile($params, $content, &$template, &$repeat){ return (sacy\Sacy::getGlobalHandler())($params, $content, $template, $repeat);}');
    }

    static function getGlobalHandler(): \Closure{
        if (static::$instance === null){
            throw new \LogicException("call registerGlobalCompatHandler first");
        }
        return static::$instance->getHandler();
    }

    function __construct(Configuration $config) {
        $this->config = $config;
    }

    private $handler = null;
    function getHandler(): \Closure{
        if ($this->handler === null){
            $this->handler = function($params, $content, $smarty, &$repeat){
                if ($repeat) return null;
                return $this->performTagReplacement($content, new BlockParams($params));
            };
        }
        return $this->handler;
    }

    /**
     * @param Smarty $smarty
     */
    function registerSmartyPlugin($smarty){
        $smarty->registerPlugin('block', 'asset_compile', $this->getHandler());
    }

    private function getDebugMode(BlockParams $p): int {
        return max($p->getDebugMode(), $this->config->getDebugMode());
    }

    private function performTagReplacement($content, BlockParams $params){
        // don't shoot me, but all tried using the dom-parser and removing elements
        // ended up with problems due to the annoying DOM API and braindead stuff
        // like no getDocumentElement() or getElementByID() not working even though
        // loadHTML clearly knows that the content is in fact HTML.
        //
        // So, let's go back to good old regexps :-)

        if ($this->getDebugMode($params) == 1 ){
            return $content;
        }

        $fragment_cache = $this->config->getFragmentCache();

        if (null !== ($version_id = $params->get('cache_version_id'))){
            $cache_key = sha1(join('', ['full-fragment', $version_id, $content]));
            $fragment = $fragment_cache->get($cache_key);
            if (!!$fragment) return $fragment;
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
        $ex = new WorkUnitExtractor($this->config, $params);
        $work_units = $ex->getAcceptedWorkUnits($tags);

        $renderer = new CacheRenderer($this->config, $params, $params->get('server_params')['SCRIPT_FILENAME'], $fragment_cache);
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
            if ($curr_cat != $cg || ($this->getDebugMode($params) == 3 && $renderer->allowMergedTransformOnly($work_units[$i-1]['tag']) && count($render))){
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

        if (null !== ($version_id = $params->get('cache_version_id'))){
            $cache_key = sha1(join('', ['full-fragment', $version_id, $content]));
            $fragment_cache->set($cache_key, $patched_content);
        }

        return $patched_content;
    }
}
