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
     * Array of configuration parameters.
     *
     * @var array<string, bool>
     */
    private array $cfg = [
        'ignore_filter_errors' => false,
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
     *                                 decoding errors.
     */
    public function __construct(array $cfg = [])
    {
        if (\array_key_exists('ignore_filter_errors', $cfg)) {
            $this->cfg['ignore_filter_errors'] = $cfg['ignore_filter_errors'];
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
        foreach ($this->xref['xref'] as $obj => $offset) {
            if (\array_key_exists($obj, $this->objects)) {
                continue;
            }

            if ($offset <= 0) {
                continue;
            }

            // decode objects with positive offset
            $this->objects[$obj] = $this->getIndirectObject($obj, $offset, true);
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

            if (\array_key_exists($obj[1], $this->xref['xref'])) {
                $xrefOffset = $this->xref['xref'][$obj[1]] ?? null;
                if (!\is_int($xrefOffset)) {
                    return $obj;
                }

                // parse new object
                $this->objects[$obj[1]] = $this->getIndirectObject($obj[1], $xrefOffset, false);
                if (($this->objects[$obj[1]][0] ?? null) !== null) {
                    return $this->objects[$obj[1]][0];
                }
            }
        }

        return $obj;
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
