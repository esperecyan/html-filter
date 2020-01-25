HTMLフィルター
================================================================================
ホワイトリストによる要素名と属性のチェックを行うHTMLフィルターです。
HTMLバリデーションは行いません。

例
--------------------------------------------------------------------------------
```php
<?php
require_once 'vendor/autoload.php';

use esperecyan\webidl\TypeError;
use esperecyan\url\URL;

$filter = new \esperecyan\html_filter\Filter([
    '*' => [
        'dir' => ['ltr', 'rtl', 'auto'],
        'lang' => '/^[a-z]+(-[0-9a-z]+)*$/iu',
        'title',
        'translate' => ['', 'yes', 'no'],
    ],
    'a' => ['href' => 'isURLWithNetworkScheme'],
    'br',
    'img' => ['alt', 'src' => 'isURLWithNetworkScheme'],
    'p',
    'time' => 'datetime',
]);
$filter->setLogger(new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []) {
        echo "$level: $message\n";
    }
});
var_dump($filter->filter(<<<'EOD'
<script>
    window.alert('foobar');
</script>
<a href="https://example.com/">例示用ドメイン</a>
<a href="./file.html" title="テスト">相対URL</a>
<bR data-clear="">
<p dir="ltr" lang='"invalid"'>アリス</p>
<p dir="LtR" LanG='ja'>ボブ</p>
EOD
));

function isURLWithNetworkScheme(string $value): bool {
    try {
        $url = new URL($value);
    } catch (TypeError $exception) {
        return false;
    }
    return in_array($url->protocol, ['ftp:', 'http:', 'https:']);
}

```

上の例の出力は以下となります。

```plain
notice: <script> タグの使用は許可されていません。
notice: <a> タグの href 属性値 "./file.html" は許可されていません。
notice: <br> タグの data-clear 属性の使用は許可されていません。
notice: <p> タグの lang 属性値 "&quot;invalid&quot;" は許可されていません。
notice: <p> タグの dir 属性値 "LtR" は許可されていません。
example.php:33:
string(179) "
    window.alert('foobar');

<a href="https://example.com/">例示用ドメイン</a>
<a title="テスト">相対URL</a>
<br />
<p dir="ltr">アリス</p>
<p lang="ja">ボブ</p>"
```

インストール
--------------------------------------------------------------------------------
```sh
composer require esperecyan/html-filter
```

Composer のインストール方法については、[Composer のグローバルインストール - Qiita]などをご覧ください。

[Composer のグローバルインストール - Qiita]: http://qiita.com/100/items/a1d73544c70fbfa7a643 "Composer は PEAR、および Pyrus に代わる新しい PHP ライブラリ管理システムです。"

要件
--------------------------------------------------------------------------------
* PHP 7.0 以上

使い方
--------------------------------------------------------------------------------
[esperecyan\\html_filter\\Filterコンストラクタ]の第1引数に、ホワイトリストを与えます。
インスタントの[filterメソッド]の第1引数に入力文字列を与えると、HTMLとしてもXHTMLとしても扱える文字列を返します。

当ライブラリは、body要素の子孫としての断片的なHTMLしか扱えません。
また、xmlns属性を含む文書は正常に取り扱うことができず、
`http://www.w3.org/1999/xhtml` 以外の名前空間を持つ要素 (svg要素、math要素)、およびその子孫は強制的に取り除かれます。
また、コメントなども取り除かれます。

[esperecyan\\html_filter\\Filterコンストラクタ]: ./src/Filter.php#L38-L119
[filterメソッド]: ./src/Filter.php#L292-337

### ホワイトリスト
| $whitelistの例                                         | 説明                                                                 |
|--------------------------------------------------------|----------------------------------------------------------------------|
| `['em', 'strong']`                                     | em要素とstrong要素を許可し、属性は一切許可しません。                 |
| `['a' => 'href', 'em', 'strong']`                      | a要素とem要素とstrong要素を許可し、a要素に限りhref属性を許可します。 |
| `['a' => ['href', 'title']]`                           | a要素を許可し、a要素のhref属性とtitle属性を許可します。              |
| `['a' => ['href' => '#^https://#u']`                   | a要素を許可し、正規表現 ([PCRE]) のパターン「#^https://#u」に合致するhref属性のみを許可します。                        |
| `[['span' => ['class' => ['foo', 'bar', 'foo bar']]]]` | span要素を許可し、属性値が「foo」「bar」「foo bar」のいずれかに完全一致するclass属性のみを許可します。([文字大小区別]) |
| `[['*' => ['id' => function (string $id): bool { return !in_array($id, ['foo', 'bar']); }], 'h1', 'h2', 'h3']` | h1要素とh2要素とh3要素を許可し、[コールバック関数]が真を返すid属性のみを許可します。 |
| `null`                                                 | フィルタリングを行わず、整形のみを行います。(※コメントなどの強制的な除去を除く)                                       |

コメントは常にフィルタリング対象となります。

[PCRE]: http://jp2.php.net/manual/book.pcre "正規表現 (Perl 互換)"
[文字大小区別]: http://www.hcn.zaq.ne.jp/___/WEB/DOM4-ja.html#case-sensitive
[コールバック関数]: http://jp2.php.net/manual/language.types.callable

### オプション
[esperecyan\\html_filter\\Filterコンストラクタ]の第2引数に連想配列で与えます。
各オプションにnullを与えた場合、指定は無視されます。

| キー   | 値 |
|--------|----|
| before | フィルタリング処理前 (HTMLの構文解析後) の文書木に対して処理を行う[コールバック関数]を指定します。第1引数でbody要素の[DOMElement]を受け取ります。この関数が `false` (同じ型) を返した場合、そこで処理を停止し、[filterメソッド]はから文字列を返します。 |
| after  | フィルタリング処理後 (HTMLの直列化前) の文書木に対して処理を行う[コールバック関数]を指定します。他は before と同じです。 |

[DOMElement]: https://secure.php.net/manual/class.domelement.php

### ロギング
[esperecyan\\html_filter\\Filter]は[PSR-3: Logger Interface]の[Psr\Log\LoggerAwareInterface]を実装しています。

| ログレベル                | 説明・例                                                                                        |
|---------------------------|-------------------------------------------------------------------------------------------------|
| Psr\Log\LogLevel::ERROR   | HTMLとして読み込めなかった場合。                                                                |
| Psr\Log\LogLevel::WARNING | [masterminds/html5]が処理したエラー。 `<` に対応する `>` が無いなど、HTMLとして壊れている場合。 |
| Psr\Log\LogLevel::NOTICE  | フィルタリングを行った場合。                                                                    |

[esperecyan\\html_filter\\Filter]: ./src/Filter.php
[PSR-3: Logger Interface]: http://guttally.net/psr/psr-3/ "この文書では，ロギングライブラリのための共通インタフェースについて記述します。"
[Psr\Log\LoggerAwareInterface]: https://github.com/php-fig/log/blob/master/Psr/Log/LoggerAwareInterface.php
[masterminds/html5]: https://github.com/Masterminds/html5-php "The need for an HTML5 parser in PHP is clear. This project initially began with the seemingly abandoned html5lib project original source. But after some initial refactoring work, we began a new parser."

#### Psr\Log\LogLevel::NOTICEの場合のコンテキスト
| キー      | 値                                                                                                       |
|-----------|----------------------------------------------------------------------------------------------------------|
| node      | 対象のノードの型を表す定数。整数。                                                                       |
| element   | 対象の要素名。                                                                                           |
| attribute | フィルタリング対象が属性である場合、その属性名。                                                         |
| value     | フィルタリング対象が属性である、かつ属性値が規則に合致しないためにフィルタリングされた場合、その属性値。 |

### 注意
まったくログが発せられなくとも、入力をXHTMLとしては取り扱えない場合があります。
XHTMLとして埋め込む場合、出力時には[esperecyan\\html_filter\\Filter->filter()]を通してください。


```php
<?php
$filter = new \esperecyan\html_filter\Filter(['a', 'blockquote', 'code', 'h1', 'h2', 'pre']);
$filter->setLogger(new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []) use ($logged) {
        $logged = true;
    }
});
$output = $filter->filter($input);

$storage = $logged ? $output : $input;
```

```php
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <title>XHTML文書</title>
    </head>
    <body>
        <?= (new \esperecyan\html_filter\Filter())->filter($storage) ?>
    </body>
</html>
```

[esperecyan\\html_filter\\Filter->filter()]: ./src/Filter.php#L304-359

Contribution
--------------------------------------------------------------------------------
Pull Request、または Issue よりお願いいたします。

セマンティック バージョニング
--------------------------------------------------------------------------------
当ライブラリは[セマンティック バージョニング]を採用しています。
パブリックAPIは、[esperecyan\\html_filter\\Filterクラス]のpublicメソッドのみです。

[セマンティック バージョニング]: http://semver.org/lang/ja/
[esperecyan\\html_filter\\Filterクラス]: ./src/Filter.php

ライセンス
----------
当ライブラリのライセンスは [Mozilla Public License Version 2.0] \(MPL-2.0) です。

[Mozilla Public License Version 2.0]: https://www.mozilla.org/MPL/2.0/
