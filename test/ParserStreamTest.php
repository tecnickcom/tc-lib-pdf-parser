<?php

/**
 * ParserStreamTest.php
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
 * Tests for the extraction and the decoding of stream payloads.
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 */
class ParserStreamTest extends TestCase
{
    /**
     * The end-of-line separating the payload from "endstream" is not stream data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testRawStreamPayloadIsTrimmedToDeclaredLength(): void
    {
        $pdf = $this->buildPdf([1 => "<< /Length 5 >>\nstream\nplain\nendstream"]);

        $parser = new Parser(['decode_streams' => false]);
        [, $parsed] = $parser->parse($pdf);

        $this->assertSame('plain', $this->rawStream($parsed['1_0'] ?? []));
    }

    /**
     * An indirect /Length must be honoured when decoding a stream.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testIndirectDeclaredLengthIsHonouredWhenDecoding(): void
    {
        $objects = [
            1 => "<< /Length 3 0 R >>\nstream\nplain\nendstream",
            2 => '<< >>',
            3 => '5',
        ];

        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildPdf($objects));

        $this->assertSame('plain', $this->decodedStream($parsed['1_0'] ?? []));
    }

    /**
     * A declared length that does not line up with the real "endstream" is ignored.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testShortDeclaredLengthWithoutEndstreamIsIgnored(): void
    {
        $pdf = $this->buildPdf([1 => "<< /Length 2 >>\nstream\nplain\nendstream"]);

        $parser = new Parser(['decode_streams' => false]);
        [, $parsed] = $parser->parse($pdf);

        $this->assertSame("plain\n", $this->rawStream($parsed['1_0'] ?? []));
    }

    /**
     * With no declared /Length the end-of-line before "endstream" is not payload.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStreamWithoutDeclaredLengthDropsTheTrailingEol(): void
    {
        $parser = new Parser(['decode_streams' => false]);
        [, $parsed] = $parser->parse($this->buildPdf([1 => "<< /X 1 >>\nstream\nhello world\r\nendstream"]));

        $this->assertSame('hello world', $this->rawStream($parsed['1_0'] ?? []));
    }

    /**
     * A declared /Length that lines up with the data is still authoritative.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testDeclaredLengthKeepsAPayloadEndingWithAnEol(): void
    {
        $parser = new Parser(['decode_streams' => false]);
        [, $parsed] = $parser->parse($this->buildPdf([1 => "<< /Length 12 >>\nstream\nhello world\n\nendstream"]));

        $this->assertSame("hello world\n", $this->rawStream($parsed['1_0'] ?? []));
    }

    /**
     * A non-positive declared /Length must be ignored, not applied as a negative offset.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testNegativeDeclaredLengthDoesNotTruncateTheStream(): void
    {
        $parser = new ParserHarness();
        $stream = 'abcdefghij';
        $slength = 10;

        $parser->getDeclaredStreamLengthPublic($stream, $slength, [['/', 'Length', 0], ['numeric', '-5', 0]], 0);

        $this->assertSame('abcdefghij', $stream);
        $this->assertSame(10, $slength);
    }

    /**
     * A value that happens to be the name /Length must not be taken for a key.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testDictionaryValueIsNotMistakenForALengthKey(): void
    {
        $parser = new ParserHarness();
        $dict = [['/', 'Key', 0], ['/', 'Length', 0], ['numeric', '2', 0]];

        $this->assertSame('abcdefghij', $parser->decodeStreamPublic($dict, 'abcdefghij')[0]);
    }

    /**
     * A false "endstream" marker inside the payload must not truncate the stream
     * when a direct /Length declares the real length.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStreamIsNotTruncatedAtFalseEndstreamWithDirectLength(): void
    {
        $payload = "ABC endstream FAKE\nXYZ-real-tail";
        $this->assertSame(
            $payload,
            $this->extractStreamPayload($this->buildFalseEndstreamPdf((string) \strlen($payload), $payload)),
        );
    }

    /**
     * A false "endstream" marker inside the payload must not truncate the stream
     * when an indirect /Length declares the real length.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStreamIsNotTruncatedAtFalseEndstreamWithIndirectLength(): void
    {
        $payload = "ABC endstream FAKE\nXYZ-real-tail";
        $this->assertSame($payload, $this->extractStreamPayload($this->buildFalseEndstreamPdf('5 0 R', $payload)));
    }

    /**
     * A re-sliced stream token must report the offset the parser resumed from.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testReslicedStreamTokenReportsTheResumedOffset(): void
    {
        $payload = 'AB endstream CD';
        $pdf = $this->buildPdf([1 => '<< /Length ' . \strlen($payload) . " >>\nstream\n" . $payload . "\nendstream"]);

        $parser = new Parser();
        [, $parsed] = $parser->parse($pdf);

        $element = $parsed['1_0'][1] ?? null;
        $this->assertIsArray($element);
        $this->assertSame('stream', $element[0]);
        $this->assertSame($payload, $element[1]);
        // the token ends at the real "endstream" keyword, not at the decoy inside the payload
        $this->assertSame(\strrpos($pdf, 'endstream'), $element[2]);
    }

    /**
     * Horizontal white space between "stream" and the end-of-line marker is tolerated.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStreamKeywordToleratesTrailingHorizontalWhiteSpace(): void
    {
        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildPdf([1 => "<< /Length 5 >>\nstream \t\nplain\nendstream"]));

        $this->assertSame('plain', $this->rawStream($parsed['1_0'] ?? []));
    }

    /**
     * A "stream" keyword without an end-of-line marker must not reuse the payload
     * offset of the previous stream.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStreamWithoutEolDoesNotReusePreviousPayloadOffset(): void
    {
        $objects = [
            1 => "<< /Length 10 >>\nstream\nABCDEFGHIJ\nendstream",
            // a bare CR is not a valid end-of-line marker after the keyword
            2 => "<< /Length 10 >>\nstream\rXXXXXXXXXX\nendstream",
        ];

        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildPdf($objects));

        $this->assertSame('ABCDEFGHIJ', $this->rawStream($parsed['1_0'] ?? []));
        $this->assertSame('', $this->rawStream($parsed['2_0'] ?? []));
    }

    /**
     * Reusing an instance must not carry a stream payload offset across documents.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStreamPayloadOffsetIsResetBetweenDocuments(): void
    {
        $parser = new Parser();
        $parser->parse($this->buildPdf([1 => "<< /Length 10 >>\nstream\nABCDEFGHIJ\nendstream"]));
        [, $parsed] = $parser->parse($this->buildPdf([1 => "<< /Length 10 >>\nstream\rXXXXXXXXXX\nendstream"]));

        $this->assertSame('', $this->rawStream($parsed['1_0'] ?? []));
    }

    /**
     * A PNG predictor declared in DecodeParms of a regular FlateDecode stream
     * must be reversed (Colors/BitsPerComponent honoured).
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testPngPredictorIsReversedForRegularFlateStream(): void
    {
        $colors = 3;
        $columns = 2;
        $rawRows = [
            [10,  20,  30, 40, 50, 60],
            [11,  22,  33, 44, 55, 66],
            [200, 100, 50, 7,  8,  9],
        ];

        $rawFlat = '';
        foreach ($rawRows as $row) {
            $rawFlat .= \pack('C*', ...$row);
        }

        $predicted = '';
        foreach ($rawRows as $row) {
            // PNG Sub filter (type 1): encoded = raw - left
            $predicted .= \chr(1);
            $count = \count($row);
            for ($i = 0; $i < $count; ++$i) {
                $left = $i >= $colors ? (int) ($row[$i - $colors] ?? 0) : 0;
                $predicted .= \chr(((int) ($row[$i] ?? 0) - $left) & 0xff);
            }
        }

        $compressed = (string) \gzcompress($predicted);
        $dict =
            '<< /Length '
            . \strlen($compressed)
            . ' /Filter /FlateDecode'
            . ' /DecodeParms << /Predictor 12 /Colors '
            . $colors
            . ' /BitsPerComponent 8 /Columns '
            . $columns
            . ' >> >>';

        $header = "%PDF-1.4\n";
        $obj1 = "1 0 obj\n" . $dict . "\nstream\n" . $compressed . "\nendstream\nendobj\n";
        $obj1Offset = \strlen($header);
        $document = $header . $obj1;
        $obj2Offset = \strlen($document);
        $document .= "2 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 3\n0000000000 65535 f \n"
            . $this->xrefInUseEntry($obj1Offset)
            . $this->xrefInUseEntry($obj2Offset)
            . "trailer\n<< /Size 3 /Root 2 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        $parser = new Parser();
        [, $objects] = $parser->parse($document);

        $decoded = null;
        foreach ($objects['1_0'] ?? [] as $element) {
            if ($element[0] !== 'stream' || !\array_key_exists(3, $element)) {
                continue;
            }

            $decoded = $element[3][0];
        }

        $this->assertSame($rawFlat, $decoded);
    }

    /**
     * A TIFF Predictor 2 declared in DecodeParms of a regular FlateDecode stream
     * must be reversed.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testTiffPredictorIsReversedForRegularFlateStream(): void
    {
        $colors = 1;
        $columns = 4;
        $rawRows = [
            [5, 10, 3, 250],
            [1, 2, 3, 4],
        ];

        $rawFlat = '';
        foreach ($rawRows as $row) {
            $rawFlat .= \pack('C*', ...$row);
        }

        $predicted = '';
        foreach ($rawRows as $row) {
            // TIFF horizontal differencing: encoded = sample - sample-to-the-left
            for ($i = 0; $i < $columns; ++$i) {
                $left = $i >= $colors ? (int) ($row[$i - $colors] ?? 0) : 0;
                $predicted .= \chr(((int) ($row[$i] ?? 0) - $left) & 0xff);
            }
        }

        $compressed = (string) \gzcompress($predicted);
        $dict =
            '<< /Length '
            . \strlen($compressed)
            . ' /Filter /FlateDecode'
            . ' /DecodeParms << /Predictor 2 /Colors '
            . $colors
            . ' /BitsPerComponent 8 /Columns '
            . $columns
            . ' >> >>';

        $header = "%PDF-1.4\n";
        $obj1 = "1 0 obj\n" . $dict . "\nstream\n" . $compressed . "\nendstream\nendobj\n";
        $obj1Offset = \strlen($header);
        $document = $header . $obj1;
        $obj2Offset = \strlen($document);
        $document .= "2 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 3\n0000000000 65535 f \n"
            . $this->xrefInUseEntry($obj1Offset)
            . $this->xrefInUseEntry($obj2Offset)
            . "trailer\n<< /Size 3 /Root 2 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        $parser = new Parser();
        [, $objects] = $parser->parse($document);

        $decoded = null;
        foreach ($objects['1_0'] ?? [] as $element) {
            if ($element[0] !== 'stream' || !\array_key_exists(3, $element)) {
                continue;
            }

            $decoded = $element[3][0];
        }

        $this->assertSame($rawFlat, $decoded);
    }

    /**
     * A DecodeParms array must be consumed positionally, so that each filter of
     * the chain receives its own parameters.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testDecodeParmsArrayIsAppliedToTheMatchingFilter(): void
    {
        $columns = 4;
        $rawRows = [
            [1, 2, 3, 4],
            [9, 8, 7, 6],
        ];

        $rawFlat = '';
        foreach ($rawRows as $row) {
            $rawFlat .= \pack('C*', ...$row);
        }

        $predicted = '';
        $prev = \array_fill(0, $columns, 0);
        foreach ($rawRows as $row) {
            // PNG Up filter (type 2): encoded = raw - above
            $predicted .= \chr(2);
            for ($i = 0; $i < $columns; ++$i) {
                $predicted .= \chr(((int) ($row[$i] ?? 0) - (int) ($prev[$i] ?? 0)) & 0xff);
            }

            $prev = $row;
        }

        // the chain is decoded left to right, so the payload is hex-encoded last
        $payload = \bin2hex((string) \gzcompress($predicted)) . '>';
        $dict =
            '<< /Length '
            . \strlen($payload)
            . ' /Filter [/ASCIIHexDecode /FlateDecode]'
            . ' /DecodeParms [<< /Predictor 2 /Columns '
            . $columns
            . ' >> << /Predictor 12 /Columns '
            . $columns
            . ' >>] >>';

        $header = "%PDF-1.4\n";
        $obj1Offset = \strlen($header);
        $document = $header . "1 0 obj\n" . $dict . "\nstream\n" . $payload . "\nendstream\nendobj\n";
        $obj2Offset = \strlen($document);
        $document .= "2 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 3\n0000000000 65535 f \n"
            . $this->xrefInUseEntry($obj1Offset)
            . $this->xrefInUseEntry($obj2Offset)
            . "trailer\n<< /Size 3 /Root 2 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        $parser = new Parser();
        [, $objects] = $parser->parse($document);

        $decoded = null;
        foreach ($objects['1_0'] ?? [] as $element) {
            if ($element[0] !== 'stream' || !\array_key_exists(3, $element)) {
                continue;
            }

            $decoded = $element[3][0];
        }

        $this->assertSame($rawFlat, $decoded);
    }

    /**
     * Boolean DecodeParms values must survive tokenization.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testBooleanDecodeParmsAreKept(): void
    {
        $parser = new ParserHarness();
        $dict = [
            ['/', 'DecodeParms', 0],
            [
                '<<',
                [
                    ['/',       'EarlyChange', 0],
                    ['boolean', 'true',        0],
                    ['/',       'BlackIs1',    0],
                    ['boolean', 'false',       0],
                ],
                0,
            ],
        ];

        $this->assertSame(
            [
                'EarlyChange' => true,
                'BlackIs1' => false,
            ],
            $parser->getDecodeParmsPublic($dict, 0),
        );
    }

    /**
     * A decompression bomb must raise the library exception instead of exhausting memory.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testDecompressionBombIsCapped(): void
    {
        $document = $this->buildFlateBombPdf();

        $this->expectException(PPException::class);
        $this->expectExceptionMessageMatches('/MaxOutputSize/');
        (new Parser(['max_stream_size' => 65536]))->parse($document);
    }

    /**
     * The configured cap overrides a MaxOutputSize the document declares for itself.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testDocumentSuppliedMaxOutputSizeIsOverridden(): void
    {
        $document = $this->buildFlateBombPdf('/DecodeParms << /MaxOutputSize 999999999 >> ');

        $this->expectException(PPException::class);
        $this->expectExceptionMessageMatches('/MaxOutputSize of 65536 bytes/');
        (new Parser(['max_stream_size' => 65536]))->parse($document);
    }

    /**
     * The decoded-size cap can be disabled explicitly.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStreamSizeCapCanBeDisabled(): void
    {
        $document = $this->buildFlateBombPdf();

        [, $objects] = (new Parser(['max_stream_size' => 0]))->parse($document);

        $this->assertSame(\str_repeat('A', 262144), $objects['1_0'][1][3][0] ?? '');
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
     * Build a PDF whose object 1 stream contains a false "endstream" marker.
     *
     * @param string $lengthEntry The /Length entry value (a number or an "N 0 R" reference).
     * @param string $payload     The real stream payload.
     */
    private function buildFalseEndstreamPdf(string $lengthEntry, string $payload): string
    {
        $length = \strlen($payload);
        $header = "%PDF-1.4\n";

        $obj1 = "1 0 obj\n<< /Length " . $lengthEntry . " >>\nstream\n" . $payload . "\nendstream\nendobj\n";
        $obj1Offset = \strlen($header);
        $document = $header . $obj1;

        $obj2Offset = \strlen($document);
        $document .= "2 0 obj\n<< /Type /Catalog >>\nendobj\n";

        $obj5Offset = \strlen($document);
        $document .= "5 0 obj\n" . $length . "\nendobj\n";

        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 6\n0000000000 65535 f \n"
            . $this->xrefInUseEntry($obj1Offset)
            . $this->xrefInUseEntry($obj2Offset)
            . "0000000000 00000 f \n"
            . "0000000000 00000 f \n"
            . $this->xrefInUseEntry($obj5Offset)
            . "trailer\n<< /Size 6 /Root 2 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        return $document;
    }

    /**
     * Build a PDF whose object 1 0 holds a FlateDecode stream expanding to 256 KB.
     */
    private function buildFlateBombPdf(string $extraDictEntries = ''): string
    {
        $compressed = (string) \gzcompress(\str_repeat('A', 262144), 9);
        $body =
            '<< /Length '
            . \strlen($compressed)
            . ' /Filter /FlateDecode '
            . $extraDictEntries
            . ">>\nstream\n"
            . $compressed
            . "\nendstream";

        return $this->buildSingleObjectPdf($body);
    }

    /**
     * Build a classic in-use xref entry line for the given object offset.
     */
    private function xrefInUseEntry(int $offset): string
    {
        return \sprintf("%010d 00000 n \n", $offset);
    }

    /**
     * Parse a PDF and return the raw payload of the stream in object "1_0".
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    private function extractStreamPayload(string $document): string
    {
        $parser = new Parser(['decode_streams' => false]);
        [, $objects] = $parser->parse($document);

        foreach ($objects['1_0'] ?? [] as $element) {
            if ($element[0] === 'stream' && \is_string($element[1])) {
                return $element[1];
            }
        }

        return '';
    }

    /**
     * Return the raw payload of the first stream element of a parsed object.
     *
     * @param array<int, RawObjectArray> $object Parsed object elements.
     */
    private function rawStream(array $object): ?string
    {
        foreach ($object as $element) {
            if ($element[0] === 'stream' && \is_string($element[1])) {
                return $element[1];
            }
        }

        return null;
    }

    /**
     * Return the decoded payload of the first stream element of a parsed object.
     *
     * @param array<int, RawObjectArray> $object Parsed object elements.
     */
    private function decodedStream(array $object): ?string
    {
        foreach ($object as $element) {
            if ($element[0] !== 'stream' || !\array_key_exists(3, $element)) {
                continue;
            }

            return $element[3][0];
        }

        return null;
    }
}
