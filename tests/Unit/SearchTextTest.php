<?php

namespace Tests\Unit;

use App\Support\Ui\SearchText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 検索文字列の正規化(ひらがな/カタカナ・全角半角・大文字小文字)。
 */
class SearchTextTest extends TestCase
{
    #[Test]
    public function kana_and_width_and_case_are_normalized(): void
    {
        $this->assertSame('あおい商事', SearchText::normalize('アオイ商事'));
        $this->assertSame('あおい商事', SearchText::normalize('ｱｵｲ商事'));
        $this->assertSame('abc', SearchText::normalize('ＡＢＣ'));
        $this->assertSame('abc', SearchText::normalize('AbC'));
        $this->assertSame('', SearchText::normalize(null));

        // 濁点つきの半角カナも 1 文字に結合される
        $this->assertSame('がっこう', SearchText::normalize('ｶﾞｯｺｳ'));
    }

    #[Test]
    public function hiragana_input_matches_a_katakana_candidate(): void
    {
        $this->assertTrue(SearchText::matches('アオイ商事', 'あおい'));
        $this->assertTrue(SearchText::matches('アオイ商事', 'アオイ'));
        $this->assertTrue(SearchText::matches('アオイ商事', 'ｱｵｲ'));
        $this->assertTrue(SearchText::matches('ケヤキ食品', 'けやき'));
        $this->assertTrue(SearchText::matches('ABC Corp', 'abc'));

        $this->assertFalse(SearchText::matches('アオイ商事', 'いろは'));
    }

    #[Test]
    public function an_empty_query_matches_everything(): void
    {
        $this->assertTrue(SearchText::matches('アオイ商事', ''));
        $this->assertTrue(SearchText::matches('アオイ商事', null));
    }
}
