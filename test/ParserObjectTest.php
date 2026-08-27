<?php

/**
 * ParserObjectTest.php
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
 * Tests for the resolution of indirect objects, the object streams and the parser state.
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 */
class ParserObjectTest extends TestCase
{
    /**
     * The same parser instance must be reusable for multiple documents.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testParserInstanceIsReusableAcrossDocuments(): void
    {
        $pdf = (string) \file_get_contents('resources/test/example_005.pdf');
        $parser = new Parser(['ignore_filter_errors' => true]);

        $first = $parser->parse($pdf);
        $second = $parser->parse($pdf);

        $this->assertSame(\md5(\serialize($first)), \md5(\serialize($second)));
    }

    /**
     * The document data must not be retained on the instance when parsing fails.
     */
    public function testDocumentDataIsReleasedWhenParsingFails(): void
    {
        $parser = new Parser();

        try {
            $parser->parse("%PDF-1.4\nno xref here\n");
            $this->fail('Expected a parser exception.');
        } catch (PPException) {
            // expected
        }

        $pdfdata = new \ReflectionProperty(Parser::class, 'pdfdata');

        $this->assertSame('', $pdfdata->getValue($parser));
    }

    /**
     * An xref entry offset past the end of the data must yield the null object.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testXrefEntryPointingPastEndOfDataYieldsNullObject(): void
    {
        $header = "%PDF-1.4\n";
        $document = $header . "1 0 obj\n<< >>\nendobj\n";
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 2\n0000000000 65535 f \n"
            . $this->xrefInUseEntry(999999)
            . "trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        [, $objects] = (new Parser())->parse($document);

        $this->assertSame([['null', 'null', 999999]], $objects['1_0'] ?? []);
    }

    /**
     * An object pulled into the cache while resolving another object's indirect
     * /Length must still get its own stream decoded.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testReferencedStreamObjectIsStillDecoded(): void
    {
        $payload = (string) \gzcompress('HELLO-WORLD');
        $objects = [
            // object 1 drags object 4 through the undecoded resolution path
            1 => "<< /Length 4 0 R >>\nstream\nplain\nendstream",
            2 => '<< /Type /Catalog >>',
            3 => '<< >>',
            4 => '<< /Length ' . \strlen($payload) . " /Filter /FlateDecode >>\nstream\n" . $payload . "\nendstream",
        ];

        $parser = new Parser(['ignore_filter_errors' => true]);
        [, $parsed] = $parser->parse($this->buildPdf($objects));

        $this->assertSame('HELLO-WORLD', $this->decodedStream($parsed['4_0'] ?? []));
    }

    /**
     * With stream decoding disabled the cached object must not be re-parsed.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testReferencedStreamObjectIsNotDecodedWhenDecodingIsDisabled(): void
    {
        $objects = [
            1 => "<< /Length 2 0 R >>\nstream\nplain\nendstream",
            2 => "<< /Length 5 >>\nstream\nabcde\nendstream",
        ];

        $parser = new Parser(['decode_streams' => false]);
        [, $parsed] = $parser->parse($this->buildPdf($objects));

        $this->assertNull($this->decodedStream($parsed['2_0'] ?? []));
    }

    /**
     * Mutually recursive indirect /Length references must not recurse forever.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testMutuallyRecursiveStreamLengthsAreResolved(): void
    {
        $header = "%PDF-1.4\n";
        $obj1 = "1 0 obj\n<< /Length 2 0 R >>\nstream\nAAAA\nendstream\nendobj\n";
        $obj2 = "2 0 obj\n<< /Length 1 0 R >>\nstream\nBBBB\nendstream\nendobj\n";
        $document = $header . $obj1 . $obj2;
        $xrefOffset = \strlen($document);
        $document .=
            "xref\n0 3\n0000000000 65535 f \n"
            . $this->xrefInUseEntry(\strlen($header))
            . $this->xrefInUseEntry(\strlen($header) + \strlen($obj1))
            . "trailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset
            . "\n%%EOF";

        [, $objects] = (new Parser(['ignore_filter_errors' => true]))->parse($document);

        $this->assertArrayHasKey('1_0', $objects);
        $this->assertArrayHasKey('2_0', $objects);
    }

    /**
     * A long chain of distinct indirect /Length references must not exhaust the stack.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testChainedIndirectLengthReferencesDoNotExhaustTheStack(): void
    {
        $chain = 10000;

        $parser = new Parser(['ignore_filter_errors' => true]);
        [, $parsed] = $parser->parse($this->buildLengthChainPdf($chain));

        $this->assertCount($chain + 1, $parsed);
        // the tail of the chain is a plain number, reachable at depth 1
        $this->assertSame('numeric', $parsed[($chain + 1) . '_0'][0][0] ?? null);
    }

    /**
     * Nesting of indirect object resolutions must stop at the configured limit.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testResolutionDepthIsBounded(): void
    {
        $parser = new ParserDepthHarness();
        $parser->parse($this->buildLengthChainPdf(200));

        // the counter is incremented on entry, so the guard is hit one level in
        $this->assertLessThanOrEqual($parser->getDepthLimit() + 1, $parser->getMaxDepth());
        $this->assertGreaterThan(1, $parser->getMaxDepth());
    }

    /**
     * A name in a value position of the /ObjStm dictionary must not be read as a key.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testObjectStreamDictionaryValueNameIsNotReadAsKey(): void
    {
        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildObjectStreamPdf(' /Marker /N 999'));

        $this->assertArrayHasKey('3_0', $parsed);
        $this->assertArrayHasKey('4_0', $parsed);
        $this->assertSame('Catalog', $this->firstDictValue($parsed['3_0'] ?? []));
        $this->assertSame('Pages', $this->firstDictValue($parsed['4_0'] ?? []));
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
     * Build a PDF of chained stream objects, each declaring the next one as its /Length.
     *
     * @param int $chain Number of stream objects in the chain.
     */
    private function buildLengthChainPdf(int $chain): string
    {
        $objects = [];
        for ($num = 1; $num <= $chain; ++$num) {
            $objects[$num] = '<< /Length ' . ($num + 1) . " 0 R >>\nstream\nab\nendstream";
        }

        $objects[$chain + 1] = '2';

        return $this->buildPdf($objects);
    }

    /**
     * Build a PDF holding one object stream with two compressed catalog-like objects.
     *
     * @param string $extraDict Extra entries appended to the /ObjStm dictionary.
     */
    private function buildObjectStreamPdf(string $extraDict = ''): string
    {
        $bodies = [3 => '<< /Type /Catalog >>', 4 => '<< /Type /Pages >>'];
        $header = '';
        $payload = '';
        foreach ($bodies as $num => $body) {
            $header .= $num . ' ' . \strlen($payload) . ' ';
            $payload .= $body . ' ';
        }

        $first = \strlen($header);
        $stream = (string) \gzcompress($header . $payload);
        $dict =
            '<< /Type /ObjStm /N 2 /First '
            . $first
            . ' /Filter /FlateDecode /Length '
            . \strlen($stream)
            . $extraDict
            . ' >>';

        $pdf = "%PDF-1.7\n";
        $objstm = \strlen($pdf);
        $pdf .= "1 0 obj\n" . $dict . "\nstream\n" . $stream . "\nendstream\nendobj\n";
        $xrefObj = \strlen($pdf);

        // /W [1 4 2]: free, the object stream, the xref stream, then the two compressed objects
        $rows = \pack('C', 0) . \pack('N', 0) . \pack('n', 65535);
        $rows .= \pack('C', 1) . \pack('N', $objstm) . \pack('n', 0);
        $rows .= \pack('C', 1) . \pack('N', $xrefObj) . \pack('n', 0);
        $rows .= \pack('C', 2) . \pack('N', 1) . \pack('n', 0);
        $rows .= \pack('C', 2) . \pack('N', 1) . \pack('n', 1);

        $xdict = '<< /Type /XRef /Size 5 /W [1 4 2] /Root 3 0 R /Length ' . \strlen($rows) . ' >>';
        $pdf .= "2 0 obj\n" . $xdict . "\nstream\n" . $rows . "\nendstream\nendobj\n";

        return $pdf . "startxref\n" . $xrefObj . "\n%%EOF\n";
    }

    /**
     * Build a classic in-use xref entry line for the given object offset.
     */
    private function xrefInUseEntry(int $offset): string
    {
        return \sprintf("%010d 00000 n \n", $offset);
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

    /**
     * Return the first value of the dictionary held by the first element of a parsed object.
     *
     * @param array<int, RawObjectArray> $object Parsed object elements.
     */
    private function firstDictValue(array $object): ?string
    {
        $value = $object[0][1][1][1] ?? null;

        return \is_string($value) ? $value : null;
    }
}
