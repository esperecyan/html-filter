<?php
namespace esperecyan\html_filter\html5;

use Masterminds\HTML5\{Serializer, Elements};

/**
 * XHTML5としても妥当な出力を行うように拡張。
 * @internal
 */
class OutputRules extends Serializer\OutputRules
{
    /**
     * @inheritDoc
     */
    protected function escape($text, $attribute = false)
    {
        if ($attribute) {
            $replace = array(
                '<' => '&lt;',
                '>' => '&gt;',
                '"' => '&quot;',
                '&' => '&amp;',
                "\xc2\xa0" => '&#xA0;'
            );
        } else {
            $replace = array(
                '<' => '&lt;',
                '>' => '&gt;',
                '&' => '&amp;',
                "\xc2\xa0" => '&#xA0;'
            );
        }

        return strtr($text, $replace);
    }
    
    /**
     * @inheritDoc
     */
    protected function openTag($ele)
    {
        $this->wr('<')->wr($this->traverser->isLocalElement($ele) ? $ele->localName : $ele->tagName);


        $this->attrs($ele);
        $this->namespaceAttrs($ele);


        if ($this->outputMode == static::IM_IN_HTML) {
            if (! Elements::isA($ele->localName, Elements::VOID_TAG)) {
                $this->wr('>');
            } else {
                $this->wr(' />');
            }
        }         // If we are not in html mode we are in SVG, MathML, or XML embedded content.
        else {
            if ($ele->hasChildNodes()) {
                $this->wr('>');
            }             // If there are no children this is self closing.
            else {
                $this->wr(' />');
            }
        }
    }
    
    public function cdata($ele)
    {
        $this->text($ele);
    }
}
