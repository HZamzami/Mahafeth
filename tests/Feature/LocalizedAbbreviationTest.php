<?php

namespace Tests\Feature;

use Illuminate\Support\Number;
use Tests\TestCase;

class LocalizedAbbreviationTest extends TestCase
{
    public function test_english_amounts_keep_latin_suffixes(): void
    {
        $this->assertSame('3.0M', Number::localizedAbbreviate(3040000.0));
        $this->assertSame('830.6K', Number::localizedAbbreviate(830600.0));
        $this->assertSame('1.2B', Number::localizedAbbreviate(1200000000.0));
    }

    public function test_arabic_amounts_spell_the_scale_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertSame('3.0 مليون', Number::localizedAbbreviate(3040000.0));
        $this->assertSame('830.6 ألف', Number::localizedAbbreviate(830600.0));
    }

    public function test_small_amounts_pass_through_unscaled(): void
    {
        $this->assertSame('500', Number::localizedAbbreviate(500.0));
    }
}
