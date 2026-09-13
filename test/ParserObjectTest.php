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
use Com\Tecnick\Pdf\Parser\LimitException as PPLimitException;
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
     * Reaching the resolution depth limit must be reported as a limit warning.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testResolutionDepthLimitIsReported(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'max_resolution_depth' => 4]);
        $parser->parse($this->buildLengthChainPdf(20));

        $warnings = $parser->getLimitWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('indirect object resolution depth limit (4)', $warnings[0] ?? '');
        $this->assertStringContainsString('first at object ', $warnings[0] ?? '');
    }

    /**
     * A resolution chain shorter than the configured limit must not report anything.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testResolutionWithinTheLimitReportsNoWarning(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'max_resolution_depth' => 512]);
        $parser->parse($this->buildLengthChainPdf(20));

        $this->assertSame([], $parser->getLimitWarnings());
    }

    /**
     * A resolution depth limit below one must be clamped to one, not disable the guard.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testResolutionDepthLimitBelowOneIsClampedToOne(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'max_resolution_depth' => 0]);
        $parser->parse($this->buildLengthChainPdf(20));

        $warnings = $parser->getLimitWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('indirect object resolution depth limit (1)', $warnings[0] ?? '');
    }

    /**
     * A negative resolution depth limit must be clamped to one as well.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testNegativeResolutionDepthLimitIsClampedToOne(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'max_resolution_depth' => -100]);
        $parser->parse($this->buildLengthChainPdf(20));

        $warnings = $parser->getLimitWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('indirect object resolution depth limit (1)', $warnings[0] ?? '');
    }

    /**
     * A reference cycle must be reported separately from a depth overflow.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testReferenceCycleIsReported(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true]);
        $parser->parse($this->buildPdf([
            1 => '<< /Length 2 0 R >>' . "\nstream\nAAAA\nendstream",
            2 => '<< /Length 1 0 R >>' . "\nstream\nBBBB\nendstream",
        ]));

        $warnings = $parser->getLimitWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('reference cycle', $warnings[0] ?? '');
    }

    /**
     * Strict mode must turn a depth overflow into a LimitException.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStrictLimitsRaiseOnResolutionDepth(): void
    {
        $parser = new Parser([
            'ignore_filter_errors' => true,
            'max_resolution_depth' => 4,
            'strict_limits' => true,
        ]);

        $this->expectException(PPLimitException::class);
        $this->expectExceptionMessageMatches('/indirect object resolution depth limit \(4\)/');
        $parser->parse($this->buildLengthChainPdf(20));
    }

    /**
     * Strict mode must turn a reference cycle into a LimitException.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testStrictLimitsRaiseOnReferenceCycle(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'strict_limits' => true]);

        $this->expectException(PPLimitException::class);
        $this->expectExceptionMessageMatches('/reference cycle/');
        $parser->parse($this->buildPdf([
            1 => '<< /Length 2 0 R >>' . "\nstream\nAAAA\nendstream",
            2 => '<< /Length 1 0 R >>' . "\nstream\nBBBB\nendstream",
        ]));
    }

    /**
     * A LimitException must remain catchable as the general parser exception.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testLimitExceptionExtendsTheParserException(): void
    {
        $parser = new Parser([
            'ignore_filter_errors' => true,
            'max_resolution_depth' => 4,
            'strict_limits' => true,
        ]);

        $this->expectException(PPException::class);
        $parser->parse($this->buildLengthChainPdf(20));
    }

    /**
     * The recorded limit events must be reset between two parses of the same instance.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testLimitWarningsAreResetBetweenParses(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'max_resolution_depth' => 4]);

        $parser->parse($this->buildLengthChainPdf(20));
        $this->assertCount(1, $parser->getLimitWarnings());

        $parser->parse($this->buildPdf([1 => '<< /Type /Catalog >>']));
        $this->assertSame([], $parser->getLimitWarnings());
    }

    /**
     * One event per kind must be kept, with the occurrence count and the first reference.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testRepeatedLimitEventsAreCountedInASingleWarning(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'max_resolution_depth' => 4]);
        $parser->parse($this->buildLengthChainPdf(40));

        $warnings = $parser->getLimitWarnings();

        $this->assertCount(1, $warnings);
        $this->assertMatchesRegularExpression('/ \d+ times, first at object /', $warnings[0] ?? '');
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
     * A reference held by a compressed object body must not be taken for a cycle.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testCompressedObjectBodyReferenceIsNotReportedAsACycle(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true]);
        // "1 0 R" is the object stream itself: the body must not be parsed as object 1
        [, $parsed] = $parser->parse($this->buildObjectStreamPdf('', [
            3 => "<< /Length 1 0 R >>\nstream\nAAAA\nendstream",
            4 => '<< /Type /Pages >>',
        ]));

        $this->assertSame([], $parser->getLimitWarnings());
        $this->assertArrayHasKey('3_0', $parsed);
        $this->assertSame('Pages', $this->firstDictValue($parsed['4_0'] ?? []));
    }

    /**
     * Strict mode must not raise on a compressed object body that holds a reference.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testCompressedObjectBodyReferenceDoesNotRaiseInStrictMode(): void
    {
        $parser = new Parser(['ignore_filter_errors' => true, 'strict_limits' => true]);
        [, $parsed] = $parser->parse($this->buildObjectStreamPdf('', [
            3 => "<< /Length 1 0 R >>\nstream\nAAAA\nendstream",
            4 => '<< /Type /Pages >>',
        ]));

        $this->assertArrayHasKey('3_0', $parsed);
    }

    /**
     * PDF 32000-1 7.3.10 separates the tokens of an object header with white space,
     * which 7.2.3 defines as any non-empty run of NUL, HT, LF, FF, CR and SP.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testObjectHeaderAcceptsEveryWhiteSpaceSeparator(): void
    {
        foreach ([' ', '  ', "\n", "\r\n", "\t", " \r\n\t", "\x00"] as $separator) {
            $header = '1' . $separator . '0' . $separator . 'obj';
            $parser = new Parser();
            [, $parsed] = $parser->parse($this->buildHeaderPdf($header));

            $this->assertSame(
                'Catalog',
                $this->firstDictValue($parsed['1_0'] ?? []),
                'separator: ' . \bin2hex($separator),
            );
        }
    }

    /**
     * Either number of an object header may carry leading zeros.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testObjectHeaderAcceptsLeadingZeros(): void
    {
        $parser = new Parser();
        [, $parsed] = $parser->parse($this->buildHeaderPdf('0001 000 obj'));

        $this->assertSame('Catalog', $this->firstDictValue($parsed['1_0'] ?? []));
    }

    /**
     * A header that belongs to another object, or that has no white space between its
     * tokens, resolves to the null object.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function testObjectHeaderOfAnotherObjectIsNotAccepted(): void
    {
        foreach (['2 0 obj', '1 1 obj', '1 0obj', '10 obj'] as $header) {
            $parser = new Parser();
            [, $parsed] = $parser->parse($this->buildHeaderPdf($header));

            $this->assertSame('null', $parsed['1_0'][0][0] ?? null, 'header: ' . $header);
        }
    }

    /**
     * Build a single-object PDF whose object header is written verbatim.
     *
     * @param string $header Object header of object 1.
     */
    private function buildHeaderPdf(string $header): string
    {
        $pdf = "%PDF-1.7\n";
        $offset = \strlen($pdf);
        $pdf .= $header . "\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = \strlen($pdf);
        $pdf .= "xref\n0 2\n0000000000 65535 f \n" . $this->xrefInUseEntry($offset);

        return $pdf . "trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
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
     * Build a PDF holding one object stream with two compressed objects numbered 3 and 4.
     *
     * @param string             $extraDict Extra entries appended to the /ObjStm dictionary.
     * @param array<int, string> $bodies    Bodies of the compressed objects, keyed by object number.
     */
    private function buildObjectStreamPdf(
        string $extraDict = '',
        array $bodies = [3 => '<< /Type /Catalog >>', 4 => '<< /Type /Pages >>'],
    ): string {
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
