<?php
namespace esperecyan\html_filter;

use Psr\Log\{LogLevel, LoggerInterface, LoggerAwareInterface, NullLogger};
use Masterminds\HTML5\Elements;

/**
 * @see https://github.com/esperecyan/html-filter#使い方
 */
class Filter implements LoggerAwareInterface
{
    /**
     * 常にフィルタリングする (許可されない) 要素の名前。
     * @var string[]
     */
    const ALWAYS_FILTERING_ELEMENT_NAMES = ['base', 'body', 'head', 'html', 'title'];
    
    /**
     * @var LoggerInterface
     */
    protected $logger = null;
    
    /**
     * @var (null|string|callable|string[])[][]|null
     */
    protected $whitelist;
    
    /**
     * @var (callable|null)[]
     */
    protected $options;
    
    /**
     * Sets a logger instance on the object.
     * @see http://www.php-fig.org/psr/psr-3/#1-4-helper-classes-and-interfaces PSR-3: Logger Interface - PHP-FIG
     * @param LoggerInterface $logger
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * @param (string|(string|callable|string[])[])[] $whitelist
     * @param (callable|null)[] $options
     * @throws \InvalidArgumentException 引数の配列に含まれる値の型が指定に合致しない場合。
     * @throws \DomainException $whitelistにbody要素の子孫となりえない要素名が含まれる場合、または妥当でない正規表現パターンが含まれる場合。
     */
    public function __construct(array $whitelist = null, array $options = [])
    {
        $this->fixElementKinds();
        
        $this->logger = new NullLogger();
        
        if (isset($options['before']) && !is_callable($options['before'])
            || isset($options['after']) && !is_callable($options['after'])) {
            throw new \InvalidArgumentException();
        }
        $this->options = $options;
        
        if (is_null($whitelist)) {
            $this->whitelist = null;
            return;
        }
        
        $this->whitelist = [];
        foreach ($whitelist as $elementName => $attributeValues) {
            if (is_int($elementName)) {
                // ['element name']
                if (is_string($attributeValues)) {
                    $this->whitelist[$attributeValues] = [];
                } else {
                    throw new \InvalidArgumentException();
                }
            } elseif (is_string($attributeValues)) {
                // ['element name' => 'attribute name']
                $this->whitelist[$elementName][$attributeValues] = null;
            } elseif (is_array($attributeValues)) {
                foreach ($attributeValues as $attributeName => $attributeValue) {
                    if (is_int($attributeName)) {
                        // ['element name' => ['attribute name']]
                        if (is_string($attributeValue)) {
                            $this->whitelist[$elementName][$attributeValue] = null;
                        } else {
                            throw new \InvalidArgumentException();
                        }
                    } elseif (is_callable($attributeValue) || is_null($attributeValue)) {
                        // ['element name' => ['attribute name' => function () {}]]
                        $this->whitelist[$elementName][$attributeName] = $attributeValue;
                    } elseif (is_array($attributeValue)) {
                        // ['element name' => ['attribute name' => ['keyword']]]
                        foreach ($attributeValue as $keyword) {
                            if (!is_string($keyword)) {
                                throw new \InvalidArgumentException();
                            }
                        }
                        $this->whitelist[$elementName][$attributeName] = $attributeValue;
                    } elseif (is_string($attributeValue)) {
                        // ['element name' => ['attribute name' => '/regular expressions/u']]
                        set_error_handler(function (int $severity, string $message, string $file, int $line) {
                            if (strpos($message, 'preg_match(): ') === 0) {
                                throw new \DomainException(
                                    '',
                                    0,
                                    new \ErrorException($message, 0, $severity, $file, $line)
                                );
                            } else {
                                return false;
                            }
                        }, E_WARNING);
                        preg_match($attributeValue, '');
                        restore_error_handler();
                        $this->whitelist[$elementName][$attributeName] = $attributeValue;
                    } else {
                        throw new \InvalidArgumentException();
                    }
                }
            } else {
                throw new \InvalidArgumentException();
            }
        }
        
        if (array_intersect(self::ALWAYS_FILTERING_ELEMENT_NAMES, array_keys($this->whitelist))) {
            throw new \DomainException();
        }
        
        if (isset($this->whitelist['*'])) {
            foreach ($this->whitelist as &$attributes) {
                $attributes += $this->whitelist['*'];
            }
            unset($this->whitelist['*']);
        }
    }
    
    /**
     * masterminds/html5 における要素の種類を修正します。
     * @see https://github.com/Masterminds/html5-php/issues/121
     */
    protected function fixElementKinds()
    {
        Elements::$html5['audio'] &= ~ Elements::BLOCK_TAG;
        Elements::$html5['video'] &= ~ Elements::BLOCK_TAG;
    }
    
    /**
     * 断片的なHTMLをbody要素の子として挿入し解析します。
     * @param string $input
     * @return \DOMElement|null body要素。
     */
    protected function parse(string $input)
    {
        $prependedRandom = bin2hex(random_bytes(10));
        $appendedRandom = bin2hex(random_bytes(10));
        
        $html = "<!DOCTYPE html>
            <html>
                <head>
                    <title>Filter</title>
                </head>
                <body><!--$prependedRandom-->$input<!--$appendedRandom--></body>
            </html>
        ";
        
        $html5 = new html5\HTML5();
        $document = $html5->loadHTML($html);
        
        foreach ($html5->getErrors() as $error) {
            $this->logger->warning($error);
        }
        
        $body = $document->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'body')->item(0);
        if ($body && $body->hasChildNodes()
            && $body->firstChild->nodeType === \XML_COMMENT_NODE && $body->firstChild->data === $prependedRandom
            && $body->lastChild->nodeType === \XML_COMMENT_NODE && $body->lastChild->data === $appendedRandom) {
            $body->removeChild($body->firstChild);
            $body->removeChild($body->lastChild);
            return $body;
        }
        
        $this->logger->error(_('HTMLの解析に失敗しました。'));
    }
    
    /**
     * ホワイトリストにもとづいて属性のフィルタリングを行います。
     * @param \DOMAttr $attr
     */
    protected function filterAttribute(\DOMAttr $attr)
    {
        $element = $attr->ownerElement;
        $allowedAttributes = $this->whitelist[$element->tagName];
        
        if (array_key_exists($attr->nodeName, $allowedAttributes)) {
            // ホワイトリストに含まれる属性なら
            $allowedPattern = $allowedAttributes[$attr->nodeName];
            $notAllowed = false;
            if (is_callable($allowedPattern)) {
                $notAllowed = !$allowedPattern($attr->nodeValue);
            } elseif (is_array($allowedPattern)) {
                $notAllowed = !in_array($attr->nodeValue, $allowedPattern);
            } elseif (is_string($allowedPattern)) {
                $notAllowed = !preg_match($allowedPattern, $attr->nodeValue);
            }
            if ($notAllowed) {
                // ホワイトリストの属性値パターンに合致しない属性なら
                $element->removeAttribute($attr->nodeName);
                $this->logger->notice(
                    sprintf(
                        _('<%1s> タグの %2s 属性値 "%3s" は許可されていません。'),
                        $element->tagName,
                        $attr->nodeName,
                        htmlspecialchars($attr->nodeValue)
                    ),
                    [
                        'node' => XML_ELEMENT_NODE,
                        'element' => $element->tagName,
                        'attribute' => $attr->nodeName,
                        'value' => $attr->nodeValue,
                    ]
                );
            }
        } else {
            $element->removeAttribute($attr->nodeName);
            $this->logger->notice(
                sprintf(_('<%1s> タグの %2s 属性の使用は許可されていません。'), $element->tagName, $attr->nodeName),
                ['node' => XML_ELEMENT_NODE, 'element' => $element->tagName, 'attribute' => $attr->nodeName]
            );
        }
    }
    
    /**
     * ホワイトリストにもとづいて要素のフィルタリングを行います。
     * @param \DOMElement $element
     * @return bool ノードが文書から取り除かれたら真を返します。
     */
    protected function filterElement(\DOMElement $element): bool
    {
        if ($element->namespaceURI !== \Masterminds\HTML5\Serializer\OutputRules::NAMESPACE_HTML) {
            $element->parentNode->removeChild($element);
            $this->logger->notice(
                sprintf(_('<%s> タグの使用は許可されていません。'), $element->tagName),
                ['node' => XML_ELEMENT_NODE, 'element' => $element->tagName]
            );
            return true;
        }
        
        if (is_null($this->whitelist)) {
            // フィルタリングを行わない場合
            return false;
        }
        
        if (in_array($element->tagName, array_keys($this->whitelist))) {
            // ホワイトリストに含まれる要素なら
            foreach ($element->attributes as $attr) {
                $this->filterAttribute($attr);
            }
            return false;
        } else {
            // 親要素の取得
            $parentElement = $element->parentNode;
            // 該当要素の子全てを外に出す
            while ($element->hasChildNodes()) {
                $parentElement->insertBefore($element->firstChild, $element);
            }
            // 該当要素を削除
            $parentElement->removeChild($element);
            $this->logger->notice(
                sprintf(_('<%s> タグの使用は許可されていません。'), $element->tagName),
                ['node' => XML_ELEMENT_NODE, 'element' => $element->tagName]
            );
            return true;
        }
    }
    
    /**
     * 処理命令を取り除きます。
     * @param \DOMProcessingInstruction $processingInstruction
     * @return true
     */
    protected function filterProcessingInstruction(\DOMProcessingInstruction $processingInstruction): bool
    {
        $processingInstruction->parentNode->removeChild($processingInstruction);
        $this->logger->notice(_('処理命令の使用は許可されていません。'), ['node' => XML_PI_NODE]);
        return true;
    }
    
    /**
     * コメントを取り除きます。
     * @param \DOMComment $comment
     * @return true
     */
    protected function filterComment(\DOMComment $comment): bool
    {
        $comment->parentNode->removeChild($comment);
        $this->logger->notice(_('コメントの使用は許可されていません。'), ['node' => XML_COMMENT_NODE]);
        return true;
    }
    
    /**
     * ノードのフィルタリングを行います。当該ノードは文書内に存在しなければなりません。
     * @param \DOMNode $node
     * @return bool ノードが文書から取り除かれたら真を返します。
     */
    protected function filterNode(\DOMNode $node): bool
    {
        switch ($node->nodeType) {
            case XML_ELEMENT_NODE:
                return $this->filterElement($node);
            case XML_PI_NODE:
                return $this->filterProcessingInstruction($node);
            case XML_COMMENT_NODE:
                return $this->filterComment($node);
        }
        return false;
    }
    
    /**
     * ホワイトリストにもとづきフィルタリングを行った結果を返します。
     * @param string $input
     * @return string HTMLの解析に失敗した場合は、空文字列を返します。
     */
    public function filter(string $input): string
    {
        // 解析
        $body = $this->parse($input);
        if (!$body) {
            return '';
        }
        
        // beforeコールバック関数の実行
        if (isset($this->options['before']) && call_user_func($this->options['before'], $body) === false) {
            return '';
        }
        
        // 全ノードの走査
        $parent = $body;
        $previous = null;
        $current = $parent->firstChild;

        $html = $body->parentNode;
        while ($parent !== $html) {
            while ($current) {
                if ($this->filterNode($current)) {
                    // ノードが取り除かれたら
                    // 次の同胞に移動
                    $current = $previous ? $previous->nextSibling : $parent->firstChild;
                } else {
                    // 子に移動
                    $parent = $current;
                    $previous = null;
                    $current = $parent->firstChild;
                }
            }

            // 親に移動
            $previous = $parent;
            $current = $parent->nextSibling;
            $parent = $parent->parentNode;
        }
        
        // afterコールバック関数の実行
        if (isset($this->options['after']) && call_user_func($this->options['after'], $body) === false) {
            return '';
        }
        
        // 直列化
        $fragment = $body->ownerDocument->createDocumentFragment();
        while ($body->hasChildNodes()) {
            $fragment->appendChild($body->firstChild);
        }
        return (new html5\HTML5())->saveHTML($fragment);
    }
}
