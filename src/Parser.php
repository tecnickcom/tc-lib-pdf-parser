<?php

/**
 * Parser.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2023 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
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
 * @copyright 2011-2023 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @phpstan-import-type RawObjectArray from \Com\Tecnick\Pdf\Parser\Process\RawObject
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
        if (isset($cfg['ignore_filter_errors'])) {
            $this->cfg['ignore_filter_errors'] = $cfg['ignore_filter_errors'];
        }
    }

    /**
     * Parse a PDF document into an array of objects
     *
     * @param string $data PDF data to parse.
     *
     * @return array{
     *             0: array{
     *                    'trailer': array{
     *                        'encrypt'?: string,
     *                        'id': array<int, string>,
     *                        'info': string,
     *                        'root': string,
     *                        'size': int,
     *                    },
     *                    'xref': array<string, int>,
     *                },
     *             1: array<string, array<int, RawObjectArray>>,
     *         }
     */
    public function parse(string $data): array
    {
        if ($data === '') {
            throw new PPException('Empty PDF data.');
        }

        // find the pdf header starting position
        if (($trimpos = strpos($data, '%PDF-')) === false) {
            throw new PPException('Invalid PDF data: missing %PDF header.');
        }

        // get PDF content string
        $this->pdfdata = substr($data, $trimpos);
        // get xref and trailer data
        $this->xref = $this->getXrefData();
        // parse all document objects
        $this->objects = [];
        foreach ($this->xref['xref'] as $obj => $offset) {
            if (isset($this->objects[$obj])) {
                continue;
            }

            if ($offset <= 0) {
                continue;
            }

            // decode objects with positive offset
            $this->objects[$obj] = $this->getIndirectObject($obj, $offset, true);
        }

        // release some memory
        unset($this->pdfdata);
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
     */
    protected function getIndirectObject(string $obj_ref, int $offset = 0, bool $decoding = true): array
    {
        $obj = explode('_', $obj_ref);
        if (($obj == false) || (count($obj) != 2)) {
            throw new PPException('Invalid object reference: ' . serialize($obj));
        }

        $objref = $obj[0] . ' ' . $obj[1] . ' obj';
        // ignore leading zeros
        $offset += strspn($this->pdfdata, '0', $offset);
        if (strpos($this->pdfdata, $objref, $offset) != $offset) {
            ++$offset;
            if (strpos($this->pdfdata, $objref, $offset) != $offset) {
                // an indirect reference to an undefined object shall be considered a reference to the null object
                return [['null', 'null', $offset]];
            }
        }

        // starting position of object content
        $offset += strlen($objref);
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
            // decode stream using stream's dictionary information
            if (
                $decoding
                && ($element[0] == 'stream')
                && (isset($objdata[($idx - 1)][0]))
                && ($objdata[($idx - 1)][0] == '<<')
                && (is_array($objdata[($idx - 1)][1]))
                && (is_string($element[1]))
            ) {
                $element[3] = $this->decodeStream($objdata[($idx - 1)][1], $element[1]);
            }

            $objdata[$idx] = $element;
            ++$idx;
        } while (($element[0] != 'endobj') && ($offset != $oldoffset));

        // remove closing delimiter
        array_pop($objdata);

        // return raw object content
        return $objdata;
    }

    /**
     * Get the content of object, resolving indect object reference if necessary.
     *
     * @param RawObjectArray $obj Object value.
     *
     * @return RawObjectArray Object data.
     */
    protected function getObjectVal(array $obj): array
    {
        if (($obj[0] == 'objref') && is_string($obj[1])) {
            // reference to indirect object
            if (isset($this->objects[$obj[1]][0])) {
                // this object has been already parsed
                return $this->objects[$obj[1]][0];
            }

            if (isset($this->xref['xref'][$obj[1]])) {
                // parse new object
                $this->objects[$obj[1]] = $this->getIndirectObject($obj[1], $this->xref['xref'][$obj[1]], false);
                return $this->objects[$obj[1]][0];
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function decodeStream(array $sdic, string $stream): array
    {
        // get stream length and filters
        $slength = strlen($stream);
        if ($slength <= 0) {
            return ['', []];
        }

        $filters = [];
        foreach ($sdic as $key => $val) {
            if (! is_string($val[1])) {
                continue;
            }

            if ($val[0] == '/') {
                if (($val[1] == 'Length') && (isset($sdic[($key + 1)])) && ($sdic[($key + 1)][0] == 'numeric')) {
                    // get declared stream length
                    $this->getDeclaredStreamLength($stream, $slength, $sdic, $key);
                } elseif (($val[1] == 'Filter') && (isset($sdic[($key + 1)]))) {
                    $filters = $this->getFilters($filters, $sdic, $key);
                }
            }
        }

        return $this->getDecodedStream($filters, $stream);
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
        $declength = (int) $sdic[($key + 1)][1];
        if ($declength < $slength) {
            $stream = substr($stream, 0, $declength);
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
     */
    protected function getFilters(array $filters, array $sdic, int $key): array
    {
        // resolve indirect object
        $objval = $this->getObjectVal($sdic[($key + 1)]);

        switch ($objval[0]) {
            case '/':
                // single filter
                if (is_string($objval[1])) {
                    $filters[] = $objval[1];
                }

                break;
            case '[':
                if (! is_array($objval[1])) {
                    break;
                }

                foreach ($objval[1] as $flt) {
                    if (! is_array($flt)) {
                        continue;
                    }

                    if ($flt[0] != '/') {
                        continue;
                    }

                    if (! is_string($flt[1])) {
                        continue;
                    }

                    $filters[] = $flt[1];
                }

                break;
        }

        return $filters;
    }

    /**
     * Decode the specified stream.
     *
     * @param array<string> $filters Array of decoding filters to apply
     * @param string        $stream  Stream to decode.
     *
     * @return array{
     *             0: string,
     *             1: array<string>,
     *         } Decoded stream data and remaining filters.
     */
    protected function getDecodedStream(array $filters, string $stream): array
    {
        // decode the stream
        $errorfilters = [];
        try {
            $filter = new Filter();
            $stream = $filter->decodeAll($filters, $stream);
        } catch (\Com\Tecnick\Pdf\Filter\Exception $exception) {
            if ($this->cfg['ignore_filter_errors']) {
                $errorfilters = $filters;
            } else {
                throw new PPException($exception->getMessage());
            }
        }

        return [$stream, $errorfilters];
    }
}
