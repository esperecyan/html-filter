<?php
namespace esperecyan\html_filter\html5;

use Masterminds\HTML5\Serializer\Traverser;

/**
 * XHTML5としても妥当な出力を行うように拡張。
 * @internal
 */
class HTML5 extends \Masterminds\HTML5
{
    /**
     * @inheritDoc
     */
    public function save($dom, $file, $options = array())
    {
        $close = true;
        if (is_resource($file)) {
            $stream = $file;
            $close = false;
        } else {
            $stream = fopen($file, 'w');
        }
        $options = array_merge($this->getOptions(), $options);
        $rules = new OutputRules($stream, $options);
        $trav = new Traverser($dom, $stream, $rules, $options);

        $trav->walk();

        if ($close) {
            fclose($stream);
        }
    }
}
