<?php
namespace esperecyan\html_filter;

use esperecyan\webidl\TypeError;
use esperecyan\url\URL;
use Psr\Log\LogLevel;

class FilterTest extends \PHPUnit\Framework\TestCase implements \Psr\Log\LoggerInterface
{
    use \Psr\Log\LoggerTrait;
    
    /**
     * @var string[][]
     */
    protected $logs = [];
    
    public function log($level, $message, array $context = [])
    {
        $this->logs[] = [$level, $message, $context];
    }
    
    /**
     * @param (string|(string|callable|string[])[])[]|null $whitelist
     * @param string $input
     * @param string $output
     * @param string[][] $logs
     * @param mixed[] $options
     * @dataProvider dataProvider
     */
    public function testFilter($whitelist, string $input, string $output, array $logs, array $options = [])
    {
        $filter = new Filter($whitelist, $options);
        $filter->setLogger($this);
        $result = $filter->filter($input);
        $this->assertEquals($logs, $this->logs);
        $this->assertSame($output, $result);
    }
    
    public function dataProvider(): array
    {
        return [
            [
                [
                    '*' => [
                        'dir' => ['ltr', 'rtl', 'auto'],
                        'lang' => '/^[a-z]+(-[0-9a-z]+)*$/iu',
                        'title',
                        'translate' => ['', 'yes', 'no'],
                    ],
                    'a' => ['href' => [self::class, 'isURLWithNetworkScheme']],
                    'br',
                    'img' => ['alt', 'src' => [self::class, 'isURLWithNetworkScheme']],
                    'p',
                    'time' => 'datetime',
                ],
                <<<'EOD'
<section>
<script>
    window.alert('foobar');
</script>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト"><span>相対URL</span></a>
<bR data-clear="">
<p dir="ltr" lang='"invalid"'>アリス</p>
<p dir="LtR" LanG='ja'>ボブ</p>
</section>
EOD
                ,
                <<<'EOD'


    window.alert('foobar');

<a href="https://example.com/">例示用ドメイン</a>
<a title="テスト">相対URL</a>
<br />
<p dir="ltr">アリス</p>
<p lang="ja">ボブ</p>

EOD
                ,
                [
                    [LogLevel::NOTICE, '<section> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'section']],
                    [LogLevel::NOTICE, '<script> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'script']],
                    [LogLevel::NOTICE, '<a> タグの href 属性値 "./file.html" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'a', 'attribute' => 'href', 'value' => './file.html']],
                    [LogLevel::NOTICE, '<span> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'span']],
                    [LogLevel::NOTICE, '<br> タグの data-clear 属性の使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'br', 'attribute' => 'data-clear']],
                    [LogLevel::NOTICE, '<p> タグの lang 属性値 "&quot;invalid&quot;" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'lang', 'value' => '"invalid"']],
                    [LogLevel::NOTICE, '<p> タグの dir 属性値 "LtR" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'dir', 'value' => 'LtR']],
                ],
            ],
            [
                ['invalid'],
                '<invalid>不正な要素</invalid>',
                '<invalid>不正な要素</invalid>',
                [],
            ],
            [
                ['table', 'thead', 'tbody', 'tr', 'th', 'td'],
                '<table><tr><td>開始タグの省略</td></tr></tbody></table>',
                '<table><tr><td>開始タグの省略</td></tr></table>',
                [
                    [LogLevel::WARNING, 'Line 0, Col 0: Could not find closing tag for tbody', []],
                ],
            ],
            [
                ['p', 'b', 'i', 's'],
                '<p><b>終了タグの欠如</b></p>',
                '<p><b>終了タグの欠如</b></p>',
                [],
            ],
            [
                ['p'],
                '<?test data?>',
                '',
                [[LogLevel::NOTICE, '処理命令の使用は許可されていません。', ['node' => XML_PI_NODE]]],
            ],
            [
                ['p'],
                '</body><script>window.alert(\'foobar\');</script><body>',
                '',
                [[LogLevel::ERROR, 'HTMLの解析に失敗しました。', []]],
            ],
            [
                null,
                <<<'EOD'
<section>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト"><span>相対URL</span></a>
<svg>
    <test />
</svg>
<bR data-clear="">
<!--コメント-->
<p dir="ltr" lang='"invalid"'>アリス</p>
<p dir="LtR" LanG='ja'>ボブ</p>
</section>
EOD
                ,
                <<<'EOD'
<section>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト"><span>相対URL</span></a>

<br data-clear="" />

<p dir="ltr" lang="&quot;invalid&quot;">アリス</p>
<p dir="LtR" lang="ja">ボブ</p>
</section>
EOD
                ,
                [
                    [LogLevel::NOTICE, '<svg> タグの使用は許可されていません。', ['node' => XML_ELEMENT_NODE, 'element' => 'svg']],
                    [LogLevel::NOTICE, 'コメントの使用は許可されていません。', ['node' => XML_COMMENT_NODE]],
                ],
            ],
            [
                [
                    '*' => [
                        'dir' => ['ltr', 'rtl', 'auto'],
                        'lang' => '/^[a-z]+(-[0-9a-z]+)*$/iu',
                        'title',
                        'translate' => ['', 'yes', 'no'],
                    ],
                    'a' => ['href' => [self::class, 'isURLWithNetworkScheme']],
                    'br',
                    'img' => ['alt', 'src' => [self::class, 'isURLWithNetworkScheme']],
                    'p',
                    'time' => 'datetime',
                ],
                <<<'EOD'
<section>
<script>
    window.alert('foobar');
</script>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト"><span>相対URL</span></a>
<bR data-clear="">
<p dir="ltr" lang='"invalid"'>アリス</p>
<p dir="LtR" LanG='ja'>ボブ</p>
</section>
EOD
                ,
                <<<'EOD'


    window.alert('foobar');

<a href="https://example.com/">例示用ドメイン</a>
<a title="テスト">相対URL</a>
<br />
<p dir="ltr">アリス</p>
<p lang="ja">ボブ</p>

EOD
                ,
                [
                    [LogLevel::NOTICE, '<section> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'section']],
                    [LogLevel::NOTICE, '<script> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'script']],
                    [LogLevel::NOTICE, '<a> タグの href 属性値 "./file.html" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'a', 'attribute' => 'href', 'value' => './file.html']],
                    [LogLevel::NOTICE, '<span> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'span']],
                    [LogLevel::NOTICE, '<br> タグの data-clear 属性の使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'br', 'attribute' => 'data-clear']],
                    [LogLevel::NOTICE, '<p> タグの lang 属性値 "&quot;invalid&quot;" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'lang', 'value' => '"invalid"']],
                    [LogLevel::NOTICE, '<p> タグの dir 属性値 "LtR" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'dir', 'value' => 'LtR']],
                ],
                [
                    'before' => null,
                    'after' => null,
                ],
            ],
            [
                [
                    '*' => [
                        'dir' => ['ltr', 'rtl', 'auto'],
                        'lang' => '/^[a-z]+(-[0-9a-z]+)*$/iu',
                        'title',
                        'translate' => ['', 'yes', 'no'],
                    ],
                    'a' => ['href' => [self::class, 'isURLWithNetworkScheme']],
                    'br',
                    'img' => ['alt', 'src' => [self::class, 'isURLWithNetworkScheme']],
                    'p',
                    'time' => 'datetime',
                ],
                <<<'EOD'
<section>
<script>
    window.alert('foobar');
</script>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト"><span>相対URL</span></a>
<bR data-clear="">
<p dir="ltr" lang='"invalid"'>アリス</p>
<p dir="LtR" LanG='ja'>ボブ</p>
</section>
EOD
                ,
                <<<'EOD'


<a href="https://example.com/">例示用ドメイン</a>
<a title="テスト">相対URL</a>
<br />
<p dir="ltr">アリス</p>
<p lang="ja">ボブ</p>

EOD
                ,
                [
                    [LogLevel::NOTICE, '<section> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'section']],
                    [LogLevel::NOTICE, '<a> タグの href 属性値 "./file.html" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'a', 'attribute' => 'href', 'value' => './file.html']],
                    [LogLevel::NOTICE, '<span> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'span']],
                    [LogLevel::NOTICE, '<br> タグの data-clear 属性の使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'br', 'attribute' => 'data-clear']],
                    [LogLevel::NOTICE, '<p> タグの lang 属性値 "&quot;invalid&quot;" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'lang', 'value' => '"invalid"']],
                    [LogLevel::NOTICE, '<p> タグの dir 属性値 "LtR" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'dir', 'value' => 'LtR']],
                ],
                [
                    'before' => function (\DOMElement $body) {
                        foreach ($body->getElementsByTagName('script') as $script) {
                            $script->parentNode->removeChild($script);
                        }
                        return null;
                    },
                ],
            ],
            [
                [
                    '*' => [
                        'dir' => ['ltr', 'rtl', 'auto'],
                        'lang' => '/^[a-z]+(-[0-9a-z]+)*$/iu',
                        'title',
                        'translate' => ['', 'yes', 'no'],
                    ],
                    'a' => ['href' => [self::class, 'isURLWithNetworkScheme']],
                    'br',
                    'img' => ['alt', 'src' => [self::class, 'isURLWithNetworkScheme']],
                    'p',
                    'time' => 'datetime',
                ],
                <<<'EOD'
<section>
<script>
    window.alert('foobar');
</script>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト"><span>相対URL</span></a>
<bR data-clear="">
<p dir="ltr" lang='"invalid"'>アリス</p>
<p dir="LtR" LanG='ja'>ボブ</p>
</section>
EOD
                ,
                '',
                [],
                [
                    'before' => function (\DOMElement $body) {
                        return false;
                    },
                ],
            ],
            [
                [
                    '*' => [
                        'dir' => ['ltr', 'rtl', 'auto'],
                        'lang' => '/^[a-z]+(-[0-9a-z]+)*$/iu',
                        'title',
                        'translate' => ['', 'yes', 'no'],
                    ],
                    'a' => ['href' => [self::class, 'isURLWithNetworkScheme']],
                    'br',
                    'img' => ['alt', 'src' => [self::class, 'isURLWithNetworkScheme']],
                    'p',
                    'time' => 'datetime',
                ],
                <<<'EOD'
<section>
<script>
    window.alert('foobar');
</script>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト"><span>相対URL</span></a>
<bR data-clear="">
<p dir="ltr" lang='"invalid"'>アリス</p>
<p dir="LtR" LanG='ja'>ボブ</p>
</section>
EOD
                ,
                <<<'EOD'


    window.alert('foobar');

<a href="https://example.com/" target="_blank" rel="noopener noreferrer">例示用ドメイン</a>
相対URL
<br />
<p dir="ltr">アリス</p>
<p lang="ja">ボブ</p>

EOD
                ,
                [
                    [LogLevel::NOTICE, '<section> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'section']],
                    [LogLevel::NOTICE, '<script> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'script']],
                    [LogLevel::NOTICE, '<a> タグの href 属性値 "./file.html" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'a', 'attribute' => 'href', 'value' => './file.html']],
                    [LogLevel::NOTICE, '<span> タグの使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'span']],
                    [LogLevel::NOTICE, '<br> タグの data-clear 属性の使用は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'br', 'attribute' => 'data-clear']],
                    [LogLevel::NOTICE, '<p> タグの lang 属性値 "&quot;invalid&quot;" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'lang', 'value' => '"invalid"']],
                    [LogLevel::NOTICE, '<p> タグの dir 属性値 "LtR" は許可されていません。',
                        ['node' => XML_ELEMENT_NODE, 'element' => 'p', 'attribute' => 'dir', 'value' => 'LtR']],
                ],
                [
                    'after' => function (\DOMElement $body) {
                        foreach ($body->getElementsByTagName('a') as $anchor) {
                            if ($anchor->getAttribute('href')) {
                                $anchor->setAttribute('target', '_blank');
                                $anchor->setAttribute('rel', 'noopener noreferrer');
                            } else {
                                while ($anchor->hasChildNodes()) {
                                    $anchor->parentNode->insertBefore($anchor->firstChild, $anchor);
                                    $anchor->parentNode->removeChild($anchor);
                                }
                            }
                        }
                    },
                ],
            ],
            [
                null,
                '<p><audio src="audio.m4a"></audio><video src="video.mp4"></video></p>',
                '<p><audio src="audio.m4a"></audio><video src="video.mp4"></video></p>',
                [],
            ],
        ];
    }
    
    public static function isURLWithNetworkScheme(string $value): bool
    {
        try {
            $url = new URL($value);
        } catch (TypeError $exception) {
            return false;
        }
        return in_array($url->protocol, ['ftp:', 'http:', 'https:']);
    }


    /**
     * @param array $whitelist
     * @param array $options
     * @expectedException \InvalidArgumentException
     * @dataProvider invalidArgumentsProvider
     */
    public function testInvalidArgumentException(array $whitelist, array $options = [])
    {
        new Filter($whitelist, $options);
    }
    
    public function invalidArgumentsProvider(): array
    {
        return [
            [[['lang', 'title']]],
            [['span' => [['1', '2']]]],
            [['span' => ['class' => [1, 2]]]],
            [['span' => ['class' => 123]]],
            [['span' => 123]],
            [['em', 'strong'], ['before' => false]],
            [['em', 'strong'], ['after' => false]],
        ];
    }
    
    /**
     * @param array $whitelist
     * @expectedException \DomainException
     * @dataProvider invalidDomainsProvider
     */
    public function testDomainException(array $whitelist)
    {
        new Filter($whitelist);
    }
    
    public function invalidDomainsProvider(): array
    {
        return [
            [['a' => ['href' => 'invalid']]],
        ];
    }
}
