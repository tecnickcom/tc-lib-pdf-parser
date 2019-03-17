<?php
/**
 * Parser.php
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Com\Tecnick\Pdf\Parser;

use \Com\Tecnick\Pdf\Parser\Exception as PPException;

/**
 * Com\Tecnick\Pdf\Parser\Parser
 *
 * PHP class for parsing PDF documents.
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 */
class Parser extends \Com\Tecnick\Pdf\Parser\Process\Xref
{
    /**
     * Raw content of the PDF document.
     *
     * @var string
     */
    protected $pdfdata = '';

    /**
     * Array of PDF objects.
     *
     * @var array
     */
    protected $objects = array();

    /**
     * Array of configuration parameters.
     *
     * @var array
     */
    private $cfg = array(
        'ignore_filter_errors'  => false,
    );

    /**
     * Initialize the PDF parser
     *
     * @param array $cfg   Array of configuration parameters:
     *          'ignore_filter_decoding_errors'  : if true ignore filter decoding errors;
     *          'ignore_missing_filter_decoders' : if true ignore missing filter decoding errors.
     */
    public function __construct($cfg = array())
    {
        if (isset($cfg['ignore_filter_errors'])) {
            $this->cfg['ignore_filter_errors'] = (bool)$cfg['ignore_filter_errors'];
        }
    }

    /**
     * Parse a PDF document into an array of objects
     *
     * @param string $data PDF data to parse.
     */
    public function parse($data)
    {
        if (empty($data)) {
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
        $this->objects = array();
        foreach ($this->xref['xref'] as $obj => $offset) {
            if (!isset($this->objects[$obj]) && ($offset > 0)) {
                // decode objects with positive offset
                $this->objects[$obj] = $this->getIndirectObject($obj, $offset, true);
            }
        }
        // release some memory
        unset($this->pdfdata);
        return array($this->xref, $this->objects);
    }

    /**
     * Get content of indirect object.
     *
     * @param string $obj_ref  Object number and generation number separated by underscore character.
     * @param int    $offset   Object offset.
     * @param bool   $decoding If true decode streams.
     *
     * @return array Object data.
     */
    protected function getIndirectObject($obj_ref, $offset = 0, $decoding = true)
    {
        $obj = explode('_', $obj_ref);
        if (($obj === false) || (count($obj) != 2)) {
            throw new PPException('Invalid object reference: '.$obj);
        }
        $objref = $obj[0].' '.$obj[1].' obj';
        // ignore leading zeros
        $offset += strspn($this->pdfdata, '0', $offset);
        if (strpos($this->pdfdata, $objref, $offset) != $offset) {
            $offset++;
            if (strpos($this->pdfdata, $objref, $offset) != $offset) {
                // an indirect reference to an undefined object shall be considered a reference to the null object
                return array('null', 'null', $offset);
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
     * @param string $obj_ref  Object number and generation number separated by underscore character.
     * @param int    $offset   Object offset.
     * @param bool   $decoding If true decode streams.
     *
     * @return array Object data.
     */
    protected function getRawIndirectObject($offset, $decoding)
    {
        // get array of object content
        $objdata = array();
        $idx = 0; // object main index
        do {
            $oldoffset = $offset;
            // get element
            $element = $this->getRawObject($offset);
            $offset = $element[2];
            // decode stream using stream's dictionary information
            if ($decoding
                && ($element[0] == 'stream')
                && (isset($objdata[($idx - 1)][0]))
                && ($objdata[($idx - 1)][0] == '<<')
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
     * @param string $obj Object value.
     *
     * @return array Object data.
     */
    protected function getObjectVal($obj)
    {
        if ($obj[0] == 'objref') {
            // reference to indirect object
            if (isset($this->objects[$obj[1]])) {
                // this object has been already parsed
                return $this->objects[$obj[1]];
            } elseif (isset($this->xref[$obj[1]])) {
                // parse new object
                $this->objects[$obj[1]] = $this->getIndirectObject($obj[1], $this->xref[$obj[1]], false);
                return $this->objects[$obj[1]];
            }
        }
        return $obj;
    }

    /**
     * Decode the specified stream.
     *
     * @param array  $sdic   Stream's dictionary array.
     * @param string $stream Stream to decode.
     *
     * @return array Decoded stream data and remaining filters.
     */
    protected function decodeStream($sdic, $stream)
    {
        // get stream length and filters
        $slength = strlen($stream);
        if ($slength <= 0) {
            return array('', array());
        }
        $filters = array();
        foreach ($sdic as $key => $val) {
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
     * @param string $stream  Stream
     * @param int    $slength Stream length
     * @param array  $sdic    Stream's dictionary array.
     * @param int    $key     Index
     *
     * @return array Array of filters
     */
    protected function getDeclaredStreamLength(&$stream, &$slength, $sdic, $key)
    {
        // get declared stream length
        $declength = intval($sdic[($key + 1)][1]);
        if ($declength < $slength) {
            $stream = substr($stream, 0, $declength);
            $slength = $declength;
        }
    }

    /**
     * Get Filters
     *
     * @param array $filters Array of Filters
     * @param array $sdic    Stream's dictionary array.
     * @param int   $key     Index
     *
     * @return array Array of filters
     */
    protected function getFilters($filters, $sdic, $key)
    {
        // resolve indirect object
        $objval = $this->getObjectVal($sdic[($key + 1)]);
        if ($objval[0] == '/') {
            // single filter
            $filters[] = $objval[1];
        } elseif ($objval[0] == '[') {
            // array of filters
            foreach ($objval[1] as $flt) {
                if ($flt[0] == '/') {
                    $filters[] = $flt[1];
                }
            }
        }
        return $filters;
    }

    /**
     * Decode the specified stream.
     *
     * @param array  $filters Array of decoding filters to apply
     * @param string $stream  Stream to decode.
     *
     * @return array Decoded stream data and remaining filters.
     */
    protected function getDecodedStream($filters, $stream)
    {
        // decode the stream
        $errorfilters = array();
        try {
            $filter = new \Com\Tecnick\Pdf\Filter\Filter;
            $stream = $filter->decodeAll($filters, $stream);
        } catch (\Com\Tecnick\Pdf\Filter\Exception $e) {
            if ($this->cfg['ignore_filter_errors']) {
                $errorfilters = $filters;
            } else {
                throw new PPException($e->getMessage());
            }
        }
        return array($stream, $errorfilters);
    }
}
