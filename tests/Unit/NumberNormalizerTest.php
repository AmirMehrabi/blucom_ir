<?php

namespace Tests\Unit;

use App\Services\NumberNormalizer;
use Tests\TestCase;

class NumberNormalizerTest extends TestCase
{
    public function test_normalizes_national_mobile_to_e164(): void
    {
        $this->assertSame('+989123456789', app(NumberNormalizer::class)->normalize('09123456789'));
    }

    public function test_keeps_numbers_that_already_include_plus(): void
    {
        $this->assertSame('+982191093464', app(NumberNormalizer::class)->normalize('+982191093464'));
    }

    public function test_normalizes_persian_and_arabic_digits(): void
    {
        $this->assertSame('+982191093464', app(NumberNormalizer::class)->normalize('۰۲۱۹۱۰۹۳۴۶۴'));
        $this->assertSame('+982191093464', app(NumberNormalizer::class)->normalize('٠٢١٩١٠٩٣٤٦٤'));
    }

    public function test_converts_double_zero_prefix(): void
    {
        $this->assertSame('+982191093464', app(NumberNormalizer::class)->normalize('00982191093464'));
    }

    public function test_accepts_already_country_coded_number_without_plus(): void
    {
        $this->assertSame('+982191093464', app(NumberNormalizer::class)->normalize('982191093464'));
    }

    public function test_rejects_non_numeric_input(): void
    {
        $this->assertNull(app(NumberNormalizer::class)->normalize('abc'));
    }

    public function test_destination_expression_matches_presentations(): void
    {
        $expression = app(NumberNormalizer::class)->destinationExpression('+982191093464');

        $this->assertSame('^(982191093464|\+982191093464|00982191093464)$', $expression);
        $this->assertSame(1, preg_match('~'.$expression.'~', '982191093464'));
        $this->assertSame(1, preg_match('~'.$expression.'~', '+982191093464'));
        $this->assertSame(1, preg_match('~'.$expression.'~', '00982191093464'));
        $this->assertSame(0, preg_match('~'.$expression.'~', '982191093465'));
    }
}
