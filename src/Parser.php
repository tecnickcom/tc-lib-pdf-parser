<?php

declare(strict_types=1);

/**
 * Parser.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Com\Tecnick\Pdf\Parser;

use Com\Tecnick\Pdf\Filter\Filter;
use Com\Tecnick\Pdf\Parser\Exception as PPException;

/**
 * Com\Tecnick\Pdf\Parser\Parser
 *
 * PHP class for parsing PDF documents.
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
 * @phpstan-import-type XrefData from \Com\Tecnick\Pdf\Parser\Process\XrefStream
 */
class Parser extends \Com\Tecnick\Pdf\Parser\Process\Xref
{
    /**
     * Cache of decoded object streams keyed by object stream reference.
     *
     * @var array<string, array<string, array<int, RawObjectArray>>>
     */
    private array $objstmCache = [];

    /**
     * Array of configuration parameters.
     *
     * @var array<string, bool>
     */
    private array $cfg = [
        'ignore_filter_errors' => false,
        'decode_streams' => true,
    ];

    /**
     * Initialize the PDF parser
     *
     * @param array<string, bool> $cfg Array of configuration parameters:
     *                                 'ignore_filter_decoding_errors'  :
     *                                 if true ignore filter decoding
     *                                 errors;
     *                                 'ignore_missing_filter_decoders' :
     *                                 if true ignore missing filter
     *                                 decoding errors;
     *                                 'decode_streams':
     *                                 if true, decode stream payloads while parsing
     *                                 regular indirect objects.
     */
    public function __construct(array $cfg = [])
    {
        if (\array_key_exists('ignore_filter_errors', $cfg)) {
            $this->cfg['ignore_filter_errors'] = $cfg['ignore_filter_errors'];
        }

        if (\array_key_exists('decode_streams', $cfg)) {
            $this->cfg['decode_streams'] = $cfg['decode_streams'];
        }
    }

    /**
     * Parse a PDF document into an array of objects
     *
     * @param string $data PDF data to parse.
     *
     * @return array{
     *             0: XrefData,
     *             1: array<string, array<int, RawObjectArray>>,
     *         }
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    public function parse(string $data): array
    {
        if ($data === '') {
            throw new PPException('Empty PDF data.');
        }

        // find the pdf header starting position
        if (($trimpos = \strpos($data, '%PDF-')) === false) {
            throw new PPException('Invalid PDF data: missing %PDF header.');
        }

        // get PDF content string
        $this->pdfdata = \substr($data, $trimpos);
        // get xref and trailer data
        $this->xref = $this->getXrefData();
        // parse all document objects
        $this->objects = [];
        $decodeStreams = $this->cfg['decode_streams'] ?? true;
        foreach ($this->xref['xref'] as $obj => $offset) {
            if (\array_key_exists($obj, $this->objects)) {
                continue;
            }

            if (\is_int($offset)) {
                if ($offset <= 0) {
                    continue;
                }

                // decode objects with positive offset
                $this->objects[$obj] = $this->getIndirectObject($obj, $offset, $decodeStreams);
                continue;
            }

            if (\preg_match('/^\d+_\d+_\d+$/', $offset) === 1) {
                $compressedObj = $this->getCompressedObject($obj, $offset);
                if ($compressedObj !== null) {
                    $this->objects[$obj] = $compressedObj;
                }
            }
        }

        $this->pdfdata = '';
        return [$this->xref, $this->objects];
    }

    /**
     * Get content of indirect object.
     *
     * @param string $obj_ref  Object number and generation number separated by underscore character.
     * @param int    $offset   Object offset.
     * @param bool   $decoding If true decode streams.
     *
     * @return array<int, RawObjectArray> Object data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function getIndirectObject(string $obj_ref, int $offset = 0, bool $decoding = true): array
    {
        $obj = \explode('_', $obj_ref);
        if (\count($obj) !== 2) {
            throw new PPException('Invalid object reference: ' . \serialize($obj));
        }

        /** @var array{0: string, 1: string} $obj */
        $objref = $obj[0] . ' ' . $obj[1] . ' obj';
        // ignore leading zeros
        $offset += \strspn($this->pdfdata, '0', $offset);
        $objPos = \strpos($this->pdfdata, $objref, $offset);
        if ((int) $objPos !== (int) $offset) {
            ++$offset;
            $objPos = \strpos($this->pdfdata, $objref, $offset);
            if ((int) $objPos !== (int) $offset) {
                // an indirect reference to an undefined object shall be considered a reference to the null object
                return [['null', 'null', $offset]];
            }
        }

        // starting position of object content
        $offset += \strlen($objref);
        // return raw object content
        return $this->getRawIndirectObject($offset, $decoding);
    }

    /**
     * Get content of indirect object.
     *
     * @param int  $offset   Object offset.
     * @param bool $decoding If true decode streams.
     *
     * @return array<int, RawObjectArray> Object data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function getRawIndirectObject(int $offset, bool $decoding): array
    {
        // get array of object content
        $objdata = [];
        $idx = 0; // object main index
        do {
            $oldoffset = $offset;

            $element = $this->getRawObject($offset);
            $offset = $element[2];
            $prevElement = $objdata[$idx - 1] ?? null;
            // decode stream using stream's dictionary information
            if (
                $decoding
                && $element[0] === 'stream'
                && \is_array($prevElement)
                && $prevElement[0] === '<<'
                && \is_array($prevElement[1])
                && \is_string($element[1])
            ) {
                /** @var array<int, RawObjectArray> $sdic */
                $sdic = $prevElement[1];
                $element[3] = $this->decodeStream($sdic, $element[1]);
            }

            $objdata[$idx] = $element;
            ++$idx;
        } while ($element[0] !== 'endobj' && (int) $offset !== (int) $oldoffset);

        // remove closing delimiter
        \array_pop($objdata);

        // return raw object content
        return $objdata;
    }

    /**
     * Get the content of object, resolving indect object reference if necessary.
     *
     * @param RawObjectArray $obj Object value.
     *
     * @return RawObjectArray Object data.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function getObjectVal(array $obj): array
    {
        if ($obj[0] === 'objref' && \is_string($obj[1])) {
            // reference to indirect object
            if (($this->objects[$obj[1]][0] ?? null) !== null) {
                // this object has been already parsed
                return $this->objects[$obj[1]][0];
            }

            if (isset($this->xref['xref'][$obj[1]])) {
                $xrefOffset = $this->xref['xref'][$obj[1]];
                if (\is_int($xrefOffset)) {
                    if ($xrefOffset <= 0) {
                        return $obj;
                    }

                    // parse new object
                    $this->objects[$obj[1]] = $this->getIndirectObject($obj[1], $xrefOffset, false);
                    if (($this->objects[$obj[1]][0] ?? null) !== null) {
                        return $this->objects[$obj[1]][0];
                    }

                    return $obj;
                }

                if (\preg_match('/^\d+_\d+_\d+$/', $xrefOffset) === 1) {
                    $compressedObj = $this->getCompressedObject($obj[1], $xrefOffset);
                    if ($compressedObj !== null) {
                        $this->objects[$obj[1]] = $compressedObj;
                        return $this->objects[$obj[1]][0] ?? $obj;
                    }

                    return $obj;
                }

                return $obj;
            }
        }

        return $obj;
    }

    /**
     * Resolve one compressed object by object-stream locator.
     *
     * @param string $objRef   Target object reference (e.g. "14_0").
     * @param string $locator  Object-stream locator "streamObj_streamGen_index".
     *
     * @return array<int, RawObjectArray>|null
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    private function getCompressedObject(string $objRef, string $locator): ?array
    {
        $parts = \explode('_', $locator);
        $streamRef = $parts[0] . '_' . ($parts[1] ?? '');
        $cache = $this->objstmCache[$streamRef] ?? null;
        if (!\is_array($cache)) {
            $cache = $this->parseObjectStream($streamRef);
            $this->objstmCache[$streamRef] = $cache;
        }

        $obj = $cache[$objRef] ?? null;
        return \is_array($obj) ? $obj : null;
    }

    /**
     * Parse a PDF object stream and return extracted objects keyed as "objNum_0".
     *
     * @param string $streamRef Object stream reference.
     *
     * @return array<string, array<int, RawObjectArray>>
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    private function parseObjectStream(string $streamRef): array
    {
        if (($this->objects[$streamRef][0] ?? null) === null) {
            $streamOffset = $this->xref['xref'][$streamRef] ?? null;
            if (!\is_int($streamOffset) || $streamOffset <= 0) {
                return [];
            }

            $this->objects[$streamRef] = $this->getIndirectObject($streamRef, $streamOffset, true);
        }

        $streamObj = $this->objects[$streamRef];
        if (!$this->hasDecodedStreamPayload($streamObj)) {
            $streamOffset = $this->xref['xref'][$streamRef] ?? null;
            if (!\is_int($streamOffset) || $streamOffset <= 0) {
                return [];
            }

            $streamObj = $this->getIndirectObject($streamRef, $streamOffset, true);
            $this->objects[$streamRef] = $streamObj;
        }

        [$dict, $decodedData] = $this->extractObjectStreamEnvelope($streamObj);
        if ($dict === null || $decodedData === null) {
            return [];
        }

        [$n, $first] = $this->readObjectStreamConfig($dict);
        if ($n <= 0 || $first < 0) {
            return [];
        }

        $index = $this->readObjectStreamIndex($decodedData, $n, $first);
        if ($index === null) {
            return [];
        }

        return $this->extractObjectsFromStream($decodedData, $first, $index['objNums'], $index['objOffsets']);
    }

    /**
     * @param array<int, RawObjectArray> $streamObj
     *
     * @return array{0: array<int, RawObjectArray>|null, 1: string|null}
     */
    private function extractObjectStreamEnvelope(array $streamObj): array
    {
        $dict = null;
        $decodedData = null;
        foreach ($streamObj as $element) {
            if ($element[0] === '<<' && \is_array($element[1])) {
                $dict = $element[1];
                continue;
            }

            if ($element[0] === 'stream' && \is_array($element[3] ?? null)) {
                $decodedData = $element[3][0];
            }
        }

        return [
            \is_array($dict) ? $dict : null,
            \is_string($decodedData) ? $decodedData : null,
        ];
    }

    /**
     * @param array<int, RawObjectArray> $dict
     *
     * @return array{0: int, 1: int}
     */
    private function readObjectStreamConfig(array $dict): array
    {
        $n = 0;
        $first = 0;
        $dictCount = \count($dict);
        for ($idx = 0; $idx < $dictCount; ++$idx) {
            $key = $dict[$idx] ?? null;
            $val = $dict[$idx + 1] ?? null;
            if (!\is_array($key) || !\is_array($val)) {
                continue;
            }

            if ($key[0] !== '/' || !\is_string($key[1])) {
                continue;
            }

            if ($key[1] === 'N' && $val[0] === 'numeric' && \is_scalar($val[1])) {
                $n = (int) $val[1];
                continue;
            }

            if ($key[1] === 'First' && $val[0] === 'numeric' && \is_scalar($val[1])) {
                $first = (int) $val[1];
            }
        }

        return [$n, $first];
    }

    /**
     * @param string $decodedData Decoded object stream payload.
     * @param int    $n           Number of embedded objects.
     * @param int    $first       Byte offset where object bodies begin.
     *
     * @return array{objNums: array<int, int>, objOffsets: array<int, int>}|null
     */
    private function readObjectStreamIndex(string $decodedData, int $n, int $first): ?array
    {
        $header = \substr($decodedData, 0, $first);
        $meta = \preg_split('/\s+/', \trim($header));
        if (!\is_array($meta) || \count($meta) < (2 * $n)) {
            return null;
        }

        $objNums = [];
        $objOffsets = [];
        for ($idx = 0; $idx < $n; ++$idx) {
            $numTok = $meta[2 * $idx] ?? null;
            $offTok = $meta[(2 * $idx) + 1] ?? null;
            if (!\is_string($numTok) || !\is_string($offTok) || !\is_numeric($numTok) || !\is_numeric($offTok)) {
                return null;
            }

            $objNums[$idx] = (int) $numTok;
            $objOffsets[$idx] = (int) $offTok;
        }

        return [
            'objNums' => $objNums,
            'objOffsets' => $objOffsets,
        ];
    }

    /**
     * @param string          $decodedData Decoded object stream payload.
     * @param int             $first       Byte offset where object bodies begin.
     * @param array<int, int> $objNums     Embedded object numbers.
     * @param array<int, int> $objOffsets  Embedded object offsets from $first.
     *
     * @return array<string, array<int, RawObjectArray>>
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    private function extractObjectsFromStream(string $decodedData, int $first, array $objNums, array $objOffsets): array
    {
        $result = [];
        $n = \count($objNums);
        $streamLen = \strlen($decodedData);
        for ($idx = 0; $idx < $n; ++$idx) {
            $start = $first + ($objOffsets[$idx] ?? 0);
            $nextStart = $idx < ($n - 1) ? $first + ($objOffsets[$idx + 1] ?? 0) : $streamLen;
            if ($start < 0 || $nextStart < $start || $start > $streamLen) {
                continue;
            }

            $body = \trim(\substr($decodedData, $start, $nextStart - $start));
            if ($body === '') {
                continue;
            }

            $miniObj = $this->parseObjectBody($body);
            $key = ($objNums[$idx] ?? 0) . '_0';
            if ($key !== '0_0' && $miniObj !== null) {
                $result[$key] = $miniObj;
            }
        }

        return $result;
    }

    /**
     * Check whether a parsed object already includes decoded stream payload.
     *
     * @param array<int, RawObjectArray> $streamObj
     */
    private function hasDecodedStreamPayload(array $streamObj): bool
    {
        foreach ($streamObj as $element) {
            if ($element[0] !== 'stream') {
                continue;
            }

            if (!\is_array($element[3] ?? null)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Parse a raw object body into parser token array by embedding it in a tiny PDF.
     *
     * @param string $body Raw object body from an object stream.
     *
     * @return array<int, RawObjectArray>|null
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    private function parseObjectBody(string $body): ?array
    {
        $header = "%PDF-1.4\n";
        $indirect = "1 0 obj\n" . $body . "\nendobj\n";
        $objOffset = \strlen($header);
        $startxref = $objOffset + \strlen($indirect);
        $xref = \sprintf("xref\n0 2\n0000000000 65535 f \n%010d 00000 n \n", $objOffset);
        $trailer = "trailer\n<< /Size 2 /Root 1 0 R >>\n";
        $miniPdf = $header . $indirect . $xref . $trailer . "startxref\n" . $startxref . "\n%%EOF\n";

        $parser = new self($this->cfg);
        [, $objects] = $parser->parse($miniPdf);
        $obj = $objects['1_0'] ?? null;
        return \is_array($obj) ? $obj : null;
    }

    /**
     * Decode the specified stream.
     *
     * @param array<int, RawObjectArray>  $sdic   Stream's dictionary array.
     * @param string            $stream Stream to decode.
     *
     * @return array{
     *             0: string,
     *             1: array<string>,
     *         } Decoded stream data and remaining filters.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    protected function decodeStream(array $sdic, string $stream): array
    {
        // get stream length and filters
        $slength = \strlen($stream);
        if ($slength <= 0) {
            return ['', []];
        }

        $filters = [];
        $params = [];
        foreach ($sdic as $key => $val) {
            if (!\is_string($val[1])) {
                continue;
            }

            if ($val[0] === '/') {
                $nextSdic = $sdic[$key + 1] ?? null;
                if ($val[1] === 'Length' && \is_array($nextSdic) && $nextSdic[0] === 'numeric') {
                    // get declared stream length
                    $this->getDeclaredStreamLength($stream, $slength, $sdic, $key);
                } elseif ($val[1] === 'Filter' && ($sdic[$key + 1] ?? null) !== null) {
                    $filters = $this->getFilters($filters, $sdic, $key);
                } elseif ($val[1] === 'DecodeParms' && ($sdic[$key + 1] ?? null) !== null) {
                    $params = $this->getDecodeParms($sdic, $key);
                }
            }
        }

        return $this->getDecodedStream($filters, $stream, $params);
    }

    /**
     * Get Filters
     *
     * @param string            $stream  Stream
     * @param int               $slength Stream length
     * @param array<int, RawObjectArray>   $sdic Stream's dictionary array.
     * @param int               $key     Index
     */
    protected function getDeclaredStreamLength(string &$stream, int &$slength, array $sdic, int $key): void
    {
        // get declared stream length
        $declength = (int) ($sdic[$key + 1][1] ?? 0);
        if ($declength < $slength) {
            $stream = \substr($stream, 0, $declength);
            $slength = $declength;
        }
    }

    /**
     * Get Filters
     *
     * @param array<string>     $filters Array of Filters
     * @param array<int, RawObjectArray> $sdic    Stream's dictionary array.
     * @param int               $key     Index
     *
     * @return array<string> Array of filters
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    protected function getFilters(array $filters, array $sdic, int $key): array
    {
        // resolve indirect object
        $nextElem = $sdic[$key + 1] ?? null;
        if (!\is_array($nextElem)) {
            return $filters;
        }

        $elem = $nextElem;
        $objval = $this->getObjectVal($elem);

        switch ($objval[0]) {
            case '/':
                // single filter
                if (\is_string($objval[1])) {
                    $filters[] = $objval[1];
                }

                break;
            case '[':
                if (!\is_array($objval[1])) {
                    break;
                }

                foreach ($objval[1] as $flt) {
                    if ($flt[0] !== '/') {
                        continue;
                    }

                    if (!\is_string($flt[1])) {
                        continue;
                    }

                    $filters[] = $flt[1];
                }

                break;
        }

        return $filters;
    }

    /**
     * Get DecodeParms
     *
     * @param array<int, RawObjectArray> $sdic    Stream's dictionary array.
     * @param int               $key     Index
     *
     * @return array<string, mixed> Decode parameters
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    protected function getDecodeParms(array $sdic, int $key): array
    {
        // resolve indirect object
        $nextElem = $sdic[$key + 1] ?? null;
        if (!\is_array($nextElem)) {
            return [];
        }

        $elem = $nextElem;
        $objval = $this->getObjectVal($elem);
        $params = [];

        switch ($objval[0]) {
            case '<<':
                // single DecodeParms dictionary
                if (\is_array($objval[1])) {
                    $params = $this->buildDecodeParms($objval[1]);
                }

                break;
            case '[':
                // array of DecodeParms (one per filter)
                if (\is_array($objval[1])) {
                    foreach ($objval[1] as $parm) {
                        if ($parm[0] === '<<') {
                            if (\is_array($parm[1])) {
                                $params = $this->buildDecodeParms($parm[1]);
                                break;
                            }
                        } elseif ($parm[0] === 'null') {
                            continue;
                        }
                    }
                }

                break;
        }

        return $params;
    }

    /**
     * Build DecodeParms associative array from raw dictionary
     *
     * @param array<int, RawObjectArray> $parmdict Raw parameter dictionary.
     *
     * @return array<string, mixed> Decoded parameters
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception
     */
    private function buildDecodeParms(array $parmdict): array
    {
        $params = [];
        $count = \count($parmdict);
        for ($i = 0; $i < $count; $i += 2) {
            if (!\is_array($parmdict[$i] ?? null)) {
                continue;
            }

            if ($parmdict[$i][0] !== '/' || !\is_string($parmdict[$i][1])) {
                continue;
            }

            $key = $parmdict[$i][1];
            $nextVal = $parmdict[$i + 1] ?? null;
            if (!\is_array($nextVal)) {
                continue;
            }

            $val = $nextVal;
            // resolve indirect references
            $val = $this->getObjectVal($val);

            // extract the value based on type
            $paramVal = $this->extractParamValue($val);
            if ($paramVal !== null) {
                $params[$key] = $paramVal;
            }
        }

        return $params;
    }

    /**
     * Extract parameter value from a raw object
     *
     * @param RawObjectArray $val Raw object value
     *
     * @return int|string|bool|null The extracted parameter value, or null if unable to extract
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function extractParamValue(array $val): int|string|bool|null
    {
        $val1 = $val[1];

        return match ($val[0]) {
            'numeric' => \is_string($val1) ? (int) $val1 : null,
            '/' => \is_string($val1) ? $val1 : null,
            'string' => \is_string($val1) ? $val1 : null,
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /**
     * Decode the specified stream.
     *
     * @param array<string> $filters Array of decoding filters to apply
     * @param string        $stream  Stream to decode.
     * @param array<string, mixed> $params DecodeParms dictionary (optional).
     *
     * @return array{
     *             0: string,
     *             1: array<string>,
     *         } Decoded stream data and remaining filters.
     *
     * @throws \Com\Tecnick\Pdf\Parser\Exception If filter decoding fails and ignore_filter_errors is false.
     */
    protected function getDecodedStream(array $filters, string $stream, array $params = []): array
    {
        // decode the stream
        $errorfilters = [];
        try {
            $filter = new Filter();
            $stream = $filter->decodeAll($filters, $stream, $params);
        } catch (\Com\Tecnick\Pdf\Filter\Exception $exception) {
            if ($this->cfg['ignore_filter_errors'] ?? false) {
                $errorfilters = $filters;
            } else {
                throw new PPException($exception->getMessage());
            }
        }

        return [$stream, $errorfilters];
    }
}
