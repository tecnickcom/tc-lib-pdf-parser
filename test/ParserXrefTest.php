<?php

/**
 * ParserXrefTest.php
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
 * Tests for the cross-reference tables, the cross-reference streams and the trailer,
 * driven end to end through parse().
 */
class ParserXrefTest extends TestCase
{
    /**
     * An object marked free by an incremental update must not come back from the
     * section the update chains to via /Prev.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testFreeEntryHidesTheEntryOfAnOlderSection(): void
    {
        $parser = new Parser();
        [$xref, $parsed] = $parser->parse($this->buildDeletedObjectPdf());

        $this->assertArrayNotHasKey('3_0', $xref['xref']);
        $this->assertArrayNotHasKey('3_0', $parsed);
        // the objects the update did not touch are still reachable
        $this->assertArrayHasKey('1_0', $xref['xref']);
        $this->assertArrayHasKey('2_0', $xref['xref']);
    }

    /**
     * A type-0 entry of a cross-reference stream must hide the entry an older section
     * holds for the same object number.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testFreeXrefStreamEntryHidesTheEntryOfAnOlderSection(): void
    {
        $parser = new Parser();
        [$xref, $parsed] = $parser->parse($this->buildDeletedObjectXrefStreamPdf());

        $this->assertArrayNotHasKey('3_0', $xref['xref']);
        $this->assertArrayNotHasKey('3_0', $parsed);
        $this->assertArrayHasKey('1_0', $xref['xref']);
    }

    /**
     * An object re-used at a new generation must not keep the entry of the older one.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testReusedObjectNumberKeepsOnlyTheNewestGeneration(): void
    {
        $parser = new Parser();
        [$xref, $parsed] = $parser->parse($this->buildReusedGenerationPdf());

        $this->assertArrayHasKey('3_1', $xref['xref']);
        $this->assertArrayNotHasKey('3_0', $xref['xref']);
        $this->assertSame('NEW-GEN1', $parsed['3_1'][0][1] ?? null);
    }

    /**
     * An xref offset pointing outside the document must raise the library exception.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testOutOfRangeStartxrefIsRejected(): void
    {
        $document = "%PDF-1.4\n1 0 obj\n<< >>\nendobj\nstartxref\n9999999999\n%%EOF";

        $this->expectException(PPException::class);
        (new Parser())->parse($document);
    }

    /**
     * A /Prev offset pointing outside the document must raise the library exception.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testOutOfRangePrevOffsetIsRejected(): void
    {
        $header = "%PDF-1.4\n";
        $document = $header . "1 0 obj\n<< >>\nendobj\n";
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 2\n0000000000 65535 f \n"
            . $this->xrefInUseEntry(\strlen($header))
            . "trailer\n<< /Size 2 /Root 1 0 R /Prev 9999999999 >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        $this->expectException(PPException::class);
        $this->expectExceptionMessageMatches('/Invalid XRef offset/');
        (new Parser())->parse($document);
    }

    /**
     * A trailer dictionary that contains a nested dictionary must be parsed
     * without being truncated at the first ">>".
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testTrailerWithNestedDictionaryIsParsed(): void
    {
        $header = "%PDF-1.4\n";
        $objOffset = \strlen($header);
        $document = $header . "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 2\n0000000000 65535 f \n"
            . $this->xrefInUseEntry($objOffset)
            . "trailer\n<< /Custom << /Nested 1 >> /Size 2 /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        $parser = new Parser();
        [$xref] = $parser->parse($document);

        $this->assertSame('1_0', $xref['trailer']['root']);
        $this->assertSame(2, $xref['trailer']['size']);
    }

    /**
     * A ">>" inside a trailer literal string must not close the dictionary early.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testTrailerStringContainingDictionaryDelimiterIsParsed(): void
    {
        $header = "%PDF-1.4\n";
        $document = $header . "1 0 obj\n<< >>\nendobj\n";
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 2\n0000000000 65535 f \n"
            . $this->xrefInUseEntry(\strlen($header))
            . "trailer\n<< /Note (see >> below) /Size 7 /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        [$xref] = (new Parser())->parse($document);

        $this->assertSame('1_0', $xref['trailer']['root']);
        $this->assertSame(7, $xref['trailer']['size']);
    }

    /**
     * A name in a value position of the xref stream dictionary must not be read as a key.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testXrefStreamDictionaryValueNameIsNotReadAsKey(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true]);
        [$xref] = $parser->parse($this->buildXrefStreamPdf(' /Marker /Root 99 0 R'));

        $this->assertSame('1_0', $xref['trailer']['root']);
    }

    /**
     * A value name that collides with /Size must not be read as a key.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testXrefStreamDictionaryValueNameDoesNotBreakCoverageCheck(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true]);
        [$xref] = $parser->parse($this->buildXrefStreamPdf(' /Marker /Size 4242'));

        $this->assertSame(4, $xref['trailer']['size']);
        $this->assertArrayHasKey('3_0', $xref['xref']);
    }

    /**
     * An xref stream holding a partial trailing row must be rejected.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testTruncatedXrefStreamRowIsRejected(): void
    {
        $this->expectException(PPException::class);
        $this->expectExceptionMessageMatches('/is not a multiple of the row size/');

        (new Parser())->parse($this->buildTruncatedRowXrefStreamPdf());
    }

    /**
     * A row that cannot hold a whole entry must be rejected rather than zero-filled.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testRowNarrowerThanTheEntryWidthIsRejected(): void
    {
        // /W [1 4 2] needs 7 bytes, /Columns 5 gives 5
        $entries = ["\x00\x00\x00\x00\x00", "\x01\x00\x00\x00\x09"];

        $parser = new Parser();

        $this->expectException(PPException::class);
        $this->expectExceptionMessageMatches('/Invalid xref stream row size/');
        $parser->parse($this->buildPredictedXrefStreamPdf('[1 4 2]', $entries, 5, 1));
    }

    /**
     * A /W field wider than the decoded row must be rejected without driving the byte loop.
     *
     * The declared width is 100 million bytes while each row holds 4, so the case also
     * asserts that parsing returns in a bounded time.
     */
    public function testOversizedWidthFieldDoesNotDriveTheByteLoop(): void
    {
        $entries = [
            "\x00\x00\x00\x00",
            "\x01\x00\x00\x00",
            "\x01\x00\x00\x00",
            "\x01\x00\x00\x00",
        ];

        $start = \microtime(true);
        $parser = new Parser();

        try {
            $parser->parse($this->buildPredictedXrefStreamPdf('[1 100000000 0]', $entries));
            $this->fail('Expected the oversized /W field to be rejected.');
        } catch (PPException $exception) {
            $this->assertStringContainsString('Invalid xref stream row size', $exception->getMessage());
        }

        $this->assertLessThan(5.0, \microtime(true) - $start);
    }

    /**
     * A /W field that fits the row is still decoded byte by byte.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testWidthFieldWithinTheRowIsStillDecoded(): void
    {
        $entries = [
            "\x00\x00\x00\x00",
            "\x01\x01\x02\x00",
            "\x00\x00\x00\x00",
            "\x00\x00\x00\x00",
        ];

        $parser = new Parser();
        [$xref] = $parser->parse($this->buildPredictedXrefStreamPdf('[1 2 1]', $entries));

        $this->assertSame(258, $xref['xref']['1_0'] ?? null);
    }

    /**
     * The predicted row length must follow /Colors and /BitsPerComponent.
     *
     * /Columns counts samples: 4 samples of 1 component of 16 bits are 8 bytes per row.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testPredictedRowLengthFollowsColorsAndBitsPerComponent(): void
    {
        $entries = [
            \str_repeat("\x00", 8),
            "\x01" . \pack('N', 258) . "\x00\x00\x00",
            \str_repeat("\x00", 8),
            \str_repeat("\x00", 8),
        ];

        $parser = new Parser();
        [$xref] = $parser->parse($this->buildPredictedXrefStreamPdf('[1 4 3]', $entries, 4, 1, 16));

        $this->assertSame(258, $xref['xref']['1_0'] ?? null);
    }

    /**
     * A /Colors value the filter layer clamps must be clamped here too, so the rows
     * are measured the way they were produced.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testPredictedRowLengthClampsColorsLikeTheFilter(): void
    {
        // rows of 8 bytes hold a 7-byte entry plus one byte of padding
        $entries = [
            "\x00\x00\x00\x00\x00\x00\x00",
            "\x01\x00\x00\x00\x09\x00\x00",
        ];

        $parser = new Parser();
        [$xref] = $parser->parse($this->buildPredictedXrefStreamPdf('[1 4 2]', $entries, 8, 0));

        $this->assertSame(9, $xref['xref']['1_0'] ?? null);
    }

    /**
     * A cross-reference stream that uses a predictor must be decoded with the
     * predictor reversed exactly once.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testCrossReferenceStreamWithPredictorIsNotDoubleDecoded(): void
    {
        $width = [1, 2, 1];
        $rowlen = \max(0, (int) \array_sum($width));

        $header = "%PDF-1.4\n";
        $obj1Offset = \strlen($header);
        $document = $header . "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefObjOffset = \strlen($document);

        $entries = [
            [0, 0,              0],
            [1, $obj1Offset,    0],
            [1, $xrefObjOffset, 0],
        ];

        $predicted = '';
        $prev = \array_fill(0, $rowlen, 0);
        foreach ($entries as $entry) {
            $row = $this->encodeXrefEntry($entry, $width);
            $predicted .= \chr(2); // PNG Up filter
            for ($i = 0; $i < $rowlen; ++$i) {
                $predicted .= \chr(((int) ($row[$i] ?? 0) - (int) ($prev[$i] ?? 0)) & 0xff);
            }

            $prev = $row;
        }

        $compressed = (string) \gzcompress($predicted);
        $dict =
            '<< /Type /XRef /Size 3 /Root 1 0 R /W [1 2 1] /Filter /FlateDecode'
            . ' /DecodeParms << /Predictor 12 /Columns '
            . $rowlen
            . ' >> /Length '
            . \strlen($compressed)
            . ' >>';
        $document .= "2 0 obj\n" . $dict . "\nstream\n" . $compressed . "\nendstream\nendobj\n";
        $document .= "startxref\n" . $xrefObjOffset . "\n%%EOF";

        $parser = new Parser();
        [$xref, $objects] = $parser->parse($document);

        $this->assertSame($obj1Offset, $xref['xref']['1_0'] ?? null);
        $this->assertSame($xrefObjOffset, $xref['xref']['2_0'] ?? null);
        $this->assertSame('1_0', $xref['trailer']['root']);
        $this->assertSame('<<', $objects['1_0'][0][0] ?? null);
    }

    /**
     * Build a PDF with a classic cross-reference table.
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
     * Build a classic in-use xref entry line for the given object offset.
     */
    private function xrefInUseEntry(int $offset): string
    {
        return \sprintf("%010d 00000 n \n", $offset);
    }

    /**
     * Build a PDF whose cross-reference is a stream object.
     *
     * @param string $extraDict Extra entries appended to the xref stream dictionary.
     */
    private function buildXrefStreamPdf(string $extraDict = ''): string
    {
        $pdf = "%PDF-1.7\n";
        $obj1 = \strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $obj2 = \strlen($pdf);
        $pdf .= "2 0 obj\n<< /Producer (x) >>\nendobj\n";
        $obj3 = \strlen($pdf);

        $rows = \pack('C', 0) . \pack('N', 0) . \pack('n', 65535);
        foreach ([$obj1, $obj2, $obj3] as $offset) {
            $rows .= \pack('C', 1) . \pack('N', $offset) . \pack('n', 0);
        }

        $stream = (string) \gzcompress($rows);
        $dict =
            '<< /Type /XRef /Size 4 /W [1 4 2] /Root 1 0 R /Info 2 0 R /Filter /FlateDecode /Length '
            . \strlen($stream)
            . $extraDict
            . ' >>';
        $pdf .= "3 0 obj\n" . $dict . "\nstream\n" . $stream . "\nendstream\nendobj\n";

        return $pdf . "startxref\n" . $obj3 . "\n%%EOF\n";
    }

    /**
     * Build a PDF whose unfiltered xref stream is two bytes short of a whole row.
     */
    private function buildTruncatedRowXrefStreamPdf(): string
    {
        $pdf = "%PDF-1.7\n";
        $obj1 = \strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $obj2 = \strlen($pdf);
        $pdf .= "2 0 obj\n<< /A 1 >>\nendobj\n";
        $obj3 = \strlen($pdf);

        // /W [0 3 0]: the type field defaults to 1 and each row is 3 bytes wide
        $rows = \pack('C', 0) . \pack('n', 0);
        foreach ([$obj1, $obj2, $obj3] as $offset) {
            $rows .= \substr(\pack('N', $offset), 1);
        }

        $rows = \substr($rows, 0, -2);
        $dict = '<< /Type /XRef /Size 4 /W [0 3 0] /Root 1 0 R /Length ' . \strlen($rows) . ' >>';
        $pdf .= "3 0 obj\n" . $dict . "\nstream\n" . $rows . "\nendstream\nendobj\n";

        return $pdf . "startxref\n" . $obj3 . "\n%%EOF\n";
    }

    /**
     * Build a PDF of three objects followed by an update that deletes object 3.
     */
    private function buildDeletedObjectPdf(): string
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Note (DELETED) >>',
        ]);
        $first = (int) \strpos($pdf, "xref\n0 4");

        $updated = \strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [] >>\nendobj\n";

        $xrefOffset = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 1\n0000000003 65535 f \n2 2\n%010d 00000 n \n0000000000 00001 f \n", $updated);

        return (
            $pdf . "trailer\n<< /Size 4 /Root 1 0 R /Prev " . $first . " >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n"
        );
    }

    /**
     * Build a PDF of two cross-reference streams, the newer one freeing object 3.
     */
    private function buildDeletedObjectXrefStreamPdf(): string
    {
        $pdf = "%PDF-1.7\n";
        $obj1 = \strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $obj3 = \strlen($pdf);
        $pdf .= "3 0 obj\n(DELETED)\nendobj\n";

        $first = \strlen($pdf);
        $rows = $this->xrefStreamRows([[0, 0, 65535], [1, $obj1, 0], [0, 0, 0], [1, $obj3, 0], [1, $first, 0]]);
        $pdf .=
            "4 0 obj\n"
            . $this->xrefStreamDict(5, '/Index [0 5]', $rows)
            . "\nstream\n"
            . $rows
            . "\nendstream\nendobj\nstartxref\n"
            . $first
            . "\n%%EOF\n";

        $second = \strlen($pdf);
        $rows = $this->xrefStreamRows([[0, 0, 65535], [0, 0, 1], [1, $second, 0]]);
        $pdf .=
            "5 0 obj\n"
            . $this->xrefStreamDict(6, '/Index [0 1 3 1 5 1] /Prev ' . $first, $rows)
            . "\nstream\n"
            . $rows
            . "\nendstream\nendobj\nstartxref\n"
            . $second
            . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * Build a PDF whose update re-uses object number 3 at generation 1.
     */
    private function buildReusedGenerationPdf(): string
    {
        $pdf = "%PDF-1.7\n";
        $obj1 = \strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $old = \strlen($pdf);
        $pdf .= "3 0 obj\n(OLD-GEN0)\nendobj\n";

        $first = \strlen($pdf);
        $pdf .= \sprintf(
            "xref\n0 4\n0000000000 65535 f \n%010d 00000 n \n0000000000 00000 f \n%010d 00000 n \n"
            . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $obj1,
            $old,
            $first,
        );

        $new = \strlen($pdf);
        $pdf .= "3 1 obj\n(NEW-GEN1)\nendobj\n";

        $xrefOffset = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 1\n0000000000 65535 f \n3 1\n%010d 00001 n \n", $new);

        return (
            $pdf . "trailer\n<< /Size 4 /Root 1 0 R /Prev " . $first . " >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n"
        );
    }

    /**
     * Build a PDF whose cross-reference is a PNG-predicted xref stream.
     *
     * Every row is emitted with the PNG "None" tag, so the un-predicted payload is the row
     * itself and the entries can be written directly.
     *
     * @param string             $widths  Raw /W array.
     * @param array<int, string> $entries One entry per object, right-padded to the row length.
     * @param int                $columns Declared /Columns.
     * @param int                $colors  Declared /Colors.
     * @param int                $bits    Declared /BitsPerComponent.
     */
    private function buildPredictedXrefStreamPdf(
        string $widths,
        array $entries,
        int $columns = 4,
        int $colors = 1,
        int $bits = 8,
    ): string {
        // /Colors is clamped up to 1, as the filter layer does
        $rowlen = \intdiv((\max(1, $colors) * $bits * $columns) + 7, 8);

        $rows = '';
        foreach ($entries as $entry) {
            $rows .= \chr(0) . \str_pad(\substr($entry, 0, $rowlen), $rowlen, "\x00");
        }

        $stream = (string) \gzcompress($rows);
        $dict =
            '<< /Type /XRef /Size '
            . \count($entries)
            . ' /W '
            . $widths
            . ' /Index [0 '
            . \count($entries)
            . '] /Root 1 0 R /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns '
            . $columns
            . ' /Colors '
            . $colors
            . ' /BitsPerComponent '
            . $bits
            . ' >> /Length '
            . \strlen($stream)
            . ' >>';

        $pdf = "%PDF-1.7\n";
        $offset = \strlen($pdf);
        $pdf .= "3 0 obj\n" . $dict . "\nstream\n" . $stream . "\nendstream\nendobj\n";

        return $pdf . "startxref\n" . $offset . "\n%%EOF\n";
    }

    /**
     * Build the dictionary of a cross-reference stream.
     *
     * @param int    $size    Declared /Size.
     * @param string $extra   Entries appended after /Root, e.g. /Index, /Prev, /Filter.
     * @param string $stream  Stream payload, used for /Length.
     */
    private function xrefStreamDict(int $size, string $extra, string $stream): string
    {
        return (
            '<< /Type /XRef /Size '
            . $size
            . ' /W [1 4 2] /Root 1 0 R '
            . $extra
            . ' /Length '
            . \strlen($stream)
            . ' >>'
        );
    }

    /**
     * Pack cross-reference stream rows as /W [1 4 2].
     *
     * @param array<int, array{0:int, 1:int, 2:int}> $entries Rows to pack.
     */
    private function xrefStreamRows(array $entries): string
    {
        $rows = '';
        foreach ($entries as $entry) {
            $rows .= \pack('C', $entry[0]) . \pack('N', $entry[1]) . \pack('n', $entry[2]);
        }

        return $rows;
    }

    /**
     * Encode a single xref-stream entry into its byte values using the given field widths.
     *
     * @param array{0: int, 1: int, 2: int} $entry Entry values (type, field2, field3).
     * @param array{0: int, 1: int, 2: int} $width Field widths in bytes.
     *
     * @return array<int, int> Byte values for the encoded row.
     */
    private function encodeXrefEntry(array $entry, array $width): array
    {
        $bytes = [];
        foreach ([0, 1, 2] as $field) {
            $value = $entry[$field] ?? 0;
            $fieldWidth = $width[$field] ?? 0;
            for ($byte = $fieldWidth - 1; $byte >= 0; --$byte) {
                $bytes[] = ($value >> ($byte * 8)) & 0xff;
            }
        }

        return $bytes;
    }
}
