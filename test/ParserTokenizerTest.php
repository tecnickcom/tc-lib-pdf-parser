<?php

/**
 * ParserTokenizerTest.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Test;

use Com\Tecnick\Pdf\Parser\Exception as PPException;
use Com\Tecnick\Pdf\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the tokenization of names, strings, arrays and dictionaries.
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 */
class ParserTokenizerTest extends TestCase
{
    /**
     * The #xx hex escapes of a name object must be decoded (PDF 32000-1 7.3.5).
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testNameHexEscapesAreDecoded(): void
    {
        $document = $this->buildSingleObjectPdf('<< /Ty#70e /Pa#67es /Fl#61teDecode 1 >>');

        [, $objects] = (new Parser())->parse($document);

        $names = \array_map(
            static fn(array $element): string => \is_string($element[1]) ? $element[1] : '',
            \is_array($objects['1_0'][0][1] ?? null) ? $objects['1_0'][0][1] : [],
        );

        $this->assertSame(['Type', 'Pages', 'FlateDecode', '1'], $names);
    }

    /**
     * A name longer than 256 bytes must not truncate nor stall the tokenizer.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testLongNameDoesNotTruncateTheEnclosingObject(): void
    {
        $name = \str_repeat('A', 300);
        $pdf = $this->buildPdf([1 => '<< /' . $name . ' 1 /Type /Catalog >>']);

        $parser = new Parser();
        [, $parsed] = $parser->parse($pdf);

        $dict = $this->containerOf($parsed['1_0'] ?? []);
        $this->assertSame($name, $dict[0][1] ?? null);
        $this->assertSame('Type', $dict[2][1] ?? null);
        $this->assertSame('Catalog', $dict[3][1] ?? null);
    }

    /**
     * A vertical tab is a regular character, not PDF white space, so it stays in the name.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testVerticalTabIsPartOfANameObject(): void
    {
        $pdf = $this->buildPdf([1 => "<< /A\x0bB 1 /Type /Catalog >>"]);

        $parser = new Parser();
        [, $parsed] = $parser->parse($pdf);

        $dict = $this->containerOf($parsed['1_0'] ?? []);
        $this->assertSame("A\x0bB", $dict[0][1] ?? null);
        $this->assertSame('Catalog', $dict[3][1] ?? null);
    }

    /**
     * An unterminated literal string must keep its last byte.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testUnterminatedLiteralStringKeepsItsLastByte(): void
    {
        $document = $this->buildTruncatedStringPdf('(abc');

        $parser = new Parser();
        [, $parsed] = $parser->parse($document);

        $this->assertSame('abc', $parsed['1_0'][0][1] ?? null);
        $this->assertLessThanOrEqual(\strlen($document), $parsed['1_0'][0][2]);
    }

    /**
     * An escape on the last byte must not push the offset past the end of the data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testEscapeAtEndOfDataKeepsTheOffsetInRange(): void
    {
        $document = $this->buildTruncatedStringPdf('(abc\\');

        $parser = new Parser();
        [, $parsed] = $parser->parse($document);

        $this->assertSame('abc\\', $parsed['1_0'][0][1] ?? null);
        $this->assertSame(\strlen($document), $parsed['1_0'][0][2]);
    }

    /**
     * A closed literal string still drops its closing delimiter.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testClosedLiteralStringDropsItsDelimiter(): void
    {
        $pdf = $this->buildPdf([1 => '[ (abc) (de(f)g) ]']);

        $parser = new Parser();
        [, $parsed] = $parser->parse($pdf);

        $array = $this->containerOf($parsed['1_0'] ?? []);
        $this->assertSame('abc', $array[0][1] ?? null);
        $this->assertSame('de(f)g', $array[1][1] ?? null);
    }

    /**
     * White space inside a hexadecimal string is ignored (PDF 32000-1 7.3.4.3).
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testHexStringWhiteSpaceIsStripped(): void
    {
        $document = $this->buildSingleObjectPdf("<48 65\n6C\t6C 6F>");

        [, $objects] = (new Parser())->parse($document);

        $this->assertSame('<', $objects['1_0'][0][0] ?? '');
        $this->assertSame('48656C6C6F', $objects['1_0'][0][1] ?? '');
    }

    /**
     * A hexadecimal string with an odd number of digits gains the trailing zero that
     * PDF 32000-1 7.3.4.3 assumes.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testOddLengthHexStringIsPaddedWithATrailingZero(): void
    {
        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildPdf([1 => '<< /A <414> /B <4142> /C <4 1 4> >>']));

        $dict = $this->containerOf($parsed['1_0'] ?? []);
        $this->assertSame('4140', $dict[1][1] ?? null);
        $this->assertSame('4142', $dict[3][1] ?? null);
        $this->assertSame('4140', $dict[5][1] ?? null);
    }

    /**
     * An unbalanced '>' must not swallow the '>>' that closes its dictionary.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testUnbalancedAngularBracketConsumesASingleByte(): void
    {
        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildPdf([1 => '<< /A > /B 2 >>', 2 => '<< /C 3 >>']));

        $types = \array_map(
            static fn(array $element): string => $element[0],
            \is_array($parsed['1_0'][0][1] ?? null) ? $parsed['1_0'][0][1] : [],
        );

        $this->assertSame(['/', '>', '/', 'numeric'], $types);
        // the dictionary closed on its own '>>', so the next object is still reachable
        $this->assertSame('C', $parsed['2_0'][0][1][0][1] ?? null);
    }

    /**
     * An unterminated array must not swallow the objects that follow it.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testUnterminatedArrayStopsAtEndobj(): void
    {
        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildPdf([
            1 => '<< /Kids [1 2 3',
            2 => '<< /Type /Page >>',
            3 => '(tail)',
        ]));

        $kids = $this->tokensOf($this->containerOf($parsed['1_0'] ?? [])[1] ?? null);
        $this->assertCount(3, $kids);
        $this->assertSame('3', $kids[2][1] ?? null);
        // the following objects are still parsed on their own
        $this->assertSame('Page', $this->containerOf($parsed['2_0'] ?? [])[1][1] ?? null);
        $this->assertSame('tail', $parsed['3_0'][0][1] ?? null);
    }

    /**
     * An unterminated dictionary must not swallow the objects that follow it.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testUnterminatedDictionaryStopsAtEndobj(): void
    {
        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildPdf([
            1 => '<< /Type /Catalog',
            2 => '(tail)',
        ]));

        $dict = $this->containerOf($parsed['1_0'] ?? []);
        $this->assertCount(2, $dict);
        $this->assertSame('tail', $parsed['2_0'][0][1] ?? null);
    }

    /**
     * A byte that cannot be tokenized must end the array without leaving a stray token.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testUnparsableByteDoesNotLeaveAStrayArrayElement(): void
    {
        $document = $this->buildSingleObjectPdf('[1 2 ~ 3]');

        [, $objects] = (new Parser())->parse($document);

        $elements = \is_array($objects['1_0'][0][1] ?? null) ? $objects['1_0'][0][1] : [];
        $types = \array_map(static fn(array $element): string => $element[0], $elements);

        $this->assertCount(2, $elements);
        $this->assertSame(['numeric', 'numeric'], $types);
    }

    /**
     * Array nesting deeper than the allowed limit must raise the library exception.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testExcessiveArrayNestingIsRejected(): void
    {
        $depth = 1000;
        $document = $this->buildSingleObjectPdf(\str_repeat('[', $depth) . \str_repeat(']', $depth));

        $this->expectException(PPException::class);
        $this->expectExceptionMessageMatches('/Maximum object nesting depth exceeded/');
        (new Parser())->parse($document);
    }

    /**
     * Dictionary nesting deeper than the allowed limit must raise the library exception.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testExcessiveDictionaryNestingIsRejected(): void
    {
        $depth = 1000;
        $document = $this->buildSingleObjectPdf(\str_repeat('<<', $depth) . \str_repeat('>>', $depth));

        $this->expectException(PPException::class);
        $this->expectExceptionMessageMatches('/Maximum object nesting depth exceeded/');
        (new Parser())->parse($document);
    }

    /**
     * A long run of comments must be skipped iteratively, not recursively.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testLongCommentRunIsSkipped(): void
    {
        $document = $this->buildSingleObjectPdf(\str_repeat("%comment\n", 50000) . '42');

        [, $objects] = (new Parser())->parse($document);

        $this->assertSame('numeric', $objects['1_0'][0][0] ?? '');
        $this->assertSame('42', $objects['1_0'][0][1] ?? '');
    }

    /**
     * A truncated object body running to EOF must not emit PHP warnings.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testTruncatedObjectBodyDoesNotEmitWarnings(): void
    {
        $header = "%PDF-1.4\n";
        $objBody = "1 0 obj\n(unterminated literal string with no closing parenthesis\n";
        $objOffset = \strlen($header);
        $document = $header . $objBody;
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 2\n0000000000 65535 f \n"
            . $this->xrefInUseEntry($objOffset)
            . "trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        $warnings = [];
        \set_error_handler(static function (int $_errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $parser = new Parser(['ignore_filter_errors' => true]);
            $parser->parse($document);
        } finally {
            \restore_error_handler();
        }

        $this->assertSame([], $warnings);
    }

    /**
     * Build a classic-xref PDF from a map of object number to object body.
     *
     * @param array<int, string> $objects Object bodies keyed by object number.
     */
    private function buildPdf(array $objects): string
    {
        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = \strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $size = \max(\array_keys($objects)) + 1;
        $xrefOffset = \strlen($pdf);
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($num = 1; $num < $size; ++$num) {
            $pdf .= \sprintf("%010d 00000 n \n", $offsets[$num] ?? 0);
        }

        return $pdf . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    }

    /**
     * Build a minimal single-object PDF whose object 1 0 holds the given body.
     */
    private function buildSingleObjectPdf(string $body): string
    {
        $header = "%PDF-1.4\n";
        $document = $header . "1 0 obj\n" . $body . "\nendobj\n";
        $xrefOffset = \strlen($document);

        return (
            $document
            . "xref\n0 2\n0000000000 65535 f \n"
            . $this->xrefInUseEntry(\strlen($header))
            . "trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF"
        );
    }

    /**
     * Build a PDF whose only object body runs unterminated to the end of the data.
     *
     * @param string $body Object body, deliberately left unterminated.
     */
    private function buildTruncatedStringPdf(string $body): string
    {
        $head =
            "%PDF-1.7\nxref\n0 2\n0000000000 65535 f \n%%OFFSET%% 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n9\n%%EOF\n";

        $offset = \sprintf('%010d', \strlen($head));

        return \str_replace('%%OFFSET%%', $offset, $head) . "1 0 obj\n" . $body;
    }

    /**
     * Build a classic in-use xref entry line for the given object offset.
     */
    private function xrefInUseEntry(int $offset): string
    {
        return \sprintf("%010d 00000 n \n", $offset);
    }

    /**
     * Return the token list held by the first element of a parsed object.
     *
     * @param array<int, RawObjectArray> $object Parsed object elements.
     *
     * @return array<int, RawObjectArray> Dictionary or array content.
     */
    private function containerOf(array $object): array
    {
        return \is_array($object[0][1] ?? null) ? $object[0][1] : [];
    }

    /**
     * Return the token list held by a single token.
     *
     * @param RawObjectArray|null $element Element holding a dictionary or an array.
     *
     * @return array<int, RawObjectArray> Dictionary or array content.
     */
    private function tokensOf(?array $element): array
    {
        return \is_array($element[1] ?? null) ? $element[1] : [];
    }
}
