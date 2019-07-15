<?php

/*
 *   An earlier experiment contained a real framework for tag
 *   and parser registration. In the end, this turned out
 *   to be much too complex if we just need to support two tags
 *   for two types of resources.
 */

namespace sacy\internal;

use sacy\internal\BlockParams;
use sacy\internal\CssRenderHandler;
use sacy\Exception;
use sacy\internal\JavaScriptRenderHandler;

class WorkUnitExtractor {
    private $_cfg;

    function __construct(BlockParams $config) {
        $this->_cfg = $config;
    }

    function getAcceptedWorkUnits($tags) {
        $work_units = array();
        foreach ($tags as $tag) {
            $r = $this->workUnitFromTag($tag['tag'], $tag['attrdata'], $tag['content']);
            if ($r === false) continue; // handler has declined
            $r = array_merge($r, array(
                'page_order' => $tag['page_order'],
                'position' => $tag['index'],
                'length' => strlen($tag['tagdata']),
                'tag' => $tag['tag']
            ));
            $work_units[] = $r;
        }
        return $work_units;
    }

    function workUnitFromTag($tag, $attrdata, $content) {
        switch ($tag) {
            case 'link':
            case 'style':
                $fn = 'extract_style_unit';
                break;
            case 'script':
                $fn = 'extract_script_unit';
                break;
            default:
                throw new Exception("Cannot handle tag: ($tag)");
        }
        return $this->$fn($tag, $attrdata, $content);
    }

    private function extract_attrs($attstr) {
        // The attribute name regex is too relaxed, but let's
        // compromise and keep it simple.
        $attextract = '#([a-z\-]+)\s*=\s*(["\'])\s*(.*?)\s*\2#';
        if (!preg_match_all($attextract, $attstr, $m)) return false;
        $res = array();
        foreach ($m[1] as $idx => $name) {
            $res[strtolower($name)] = $m[3][$idx];
        }
        return $res;
    }

    private function urlToFile($ref) {
        $u = parse_url($ref);
        if ($u === false) return false;
        if (isset($u['host']) || isset($u['scheme']))
            return false;

        if ($this->_cfg->get('query_strings') == 'ignore')
            if (isset($u['query'])) return false;

        $ref = $u['path'];
        $path = array($this->_cfg->get('server_params')['DOCUMENT_ROOT']);
        if ($ref[0] != '/')
            $path[] = $this->_cfg->get('server_params')['PHP_SELF'];
        $path[] = $ref;
        return realpath(implode(DIRECTORY_SEPARATOR, $path));

    }


    private function extract_style_unit($tag, $attrdata, $content) {
        $attrs = $this->extract_attrs($attrdata);
        $attrs['type'] = strtolower($attrs['type']);

        // invalid markup
        if ($tag == 'link' && !empty($content)) return false;
        if ($tag == 'style' && empty($content)) return false;
        if ($tag == 'link' && empty($attrs['href'])) return false;

        // not a stylesheet
        if ($tag == 'link' && strtolower($attrs['rel']) != 'stylesheet') return false;

        // type attribute required
        if (!isset($attrs['type'])) return false;

        // not one of the supported types
        if (!in_array(strtolower($attrs['type']), CssRenderHandler::supportedTransformations()))
            return false;

        // in debug mode 3, only transform
        if ($this->_cfg->getDebugMode() == 3 &&
            !CssRenderHandler::willTransformType($attrs['type']))
            return false;

        if (!isset($attrs['media']))
            $attrs['media'] = "";

        $path = null;
        if (empty($content)) {
            $path = $this->urlToFile($attrs['href']);
            if ($path === false) return false;
        }

        $group = serialize($this->_cfg->get('merge_tags') ? [$attrs['media'], $attrs['type']] : [$attrs['media']]);

        return array(
            'group' => $group,
            'file' => $path,
            'content' => $content,
            'type' => $attrs['type']
        );
    }

    private function validTag($attrs) {
        $types = array_merge(array('text/javascript', 'application/javascript'), JavaScriptRenderHandler::supportedTransformations());
        return in_array($attrs['type'], $types);
    }

    private function extract_script_unit($tag, $attrdata, $content) {
        $attrs = $this->extract_attrs($attrdata);
        if (!$attrs['type'])
            $attrs['type'] = 'text/javascript';
        $attrs['type'] = strtolower($attrs['type']);
        if ($this->_cfg->getDebugMode() == 3 &&
            !JavaScriptRenderHandler::willTransformType($attrs['type'])) {
            return false;
        }

        if ($this->validTag($attrs)) {
            $path = null;
            if (!$content) {
                $path = $this->urlToFile($attrs['src']);
                if ($path === false) {
                    return false;
                }
            }

            return array(
                'group' => '',
                'content' => $content,
                'file' => $path,
                'data' => $this->parseDataAttrs($attrs),
                'type' => $attrs['type']
            );
        }
        return false;
    }

    private function parseDataAttrs($attrs) {
        $data = array();

        foreach ($attrs as $key => $value) {
            // Compromising again here on the valid
            // format of the attr key, to keep the
            // regex simple.
            if (preg_match('#^data-([a-z\-]+)$#', $key, $match)) {
                $name = $match[1];
                $data[$name] = $value;
            }
        }

        return $data;
    }
}
