<?php
/**
 * RawObject.php
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

namespace Com\Tecnick\Pdf\Parser\Process;

use \Com\Tecnick\Pdf\Parser\Exception as PPException;

/**
 * Com\Tecnick\Pdf\Parser\Process\RawObject
 *
 * Process Raw Objects
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 */
abstract class RawObject
{
    /**
     * Get object type, raw value and offset to next object
     *
     * @param int $offset Object offset.
     *
     * @return array Array containing object type, raw value and offset to next object
     */
    protected function getRawObject($offset = 0)
    {
        $objtype = ''; // object type to be returned
        $objval = ''; // object value to be returned
        // skip initial white space chars:
        // \x00 null (NUL)
        // \x09 horizontal tab (HT)
        // \x0A line feed (LF)
        // \x0C form feed (FF)
        // \x0D carriage return (CR)
        // \x20 space (SP)
        $offset += strspn($this->pdfdata, "\x00\x09\x0a\x0c\x0d\x20", $offset);
        // get first char
        $char = $this->pdfdata[$offset];
        if ($char == '%') { // \x25 PERCENT SIGN
            // skip comment and search for next token
            $next = strcspn($this->pdfdata, "\r\n", $offset);
            if ($next > 0) {
                $offset += $next;
                return $this->getRawObject($offset);
            }
        }
        // map symbols with corresponding processing methods
        $map = array(
            '/' => 'Solidus',     // \x2F SOLIDUS
            '(' => 'Parenthesis', // \x28 LEFT PARENTHESIS
            ')' => 'Parenthesis', // \x29 RIGHT PARENTHESIS
            '[' => 'Bracket',     // \x5B LEFT SQUARE BRACKET
            ']' => 'Bracket',     // \x5D RIGHT SQUARE BRACKET
            '<' => 'Angular',     // \x3C LESS-THAN SIGN
            '>' => 'Angular',     // \x3E GREATER-THAN SIGN
        );
        if (isset($map[$char])) {
            $method = 'process'.$map[$char];
            $this->$method($char, $offset, $objtype, $objval);
        } else {
            if ($this->processDefaultName($offset, $objtype, $objval) === false) {
                $this->processDefault($offset, $objtype, $objval);
            }
        }
        return array($objtype, $objval, $offset);
    }

    /**
     * Process name object
     * \x2F SOLIDUS
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processSolidus($char, &$offset, &$objtype, &$objval)
    {
        $objtype = $char;
        ++$offset;
        if (preg_match(
            '/^([^\x00\x09\x0a\x0c\x0d\x20\s\x28\x29\x3c\x3e\x5b\x5d\x7b\x7d\x2f\x25]+)/',
            substr($this->pdfdata, $offset, 256),
            $matches
        ) == 1
        ) {
            $objval = $matches[1]; // unescaped value
            $offset += strlen($objval);
        }
    }

    /**
     * Process literal string object
     * \x28 LEFT PARENTHESIS and \x29 RIGHT PARENTHESIS
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processParenthesis($char, &$offset, &$objtype, &$objval)
    {
        $objtype = $char;
        ++$offset;
        $strpos = $offset;
        if ($char == '(') {
            $open_bracket = 1;
            while ($open_bracket > 0) {
                if (!isset($this->pdfdata[$strpos])) {
                    break;
                }
                $chr = $this->pdfdata[$strpos];
                switch ($chr) {
                    case '\\':
                        // REVERSE SOLIDUS (5Ch) (Backslash)
                        // skip next character
                        ++$strpos;
                        break;
                    case '(':
                        // LEFT PARENHESIS (28h)
                        ++$open_bracket;
                        break;
                    case ')':
                        // RIGHT PARENTHESIS (29h)
                        --$open_bracket;
                        break;
                }
                ++$strpos;
            }
            $objval = substr($this->pdfdata, $offset, ($strpos - $offset - 1));
            $offset = $strpos;
        }
    }

    /**
     * Process array content
     * \x5B LEFT SQUARE BRACKET and \x5D RIGHT SQUARE BRACKET
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processBracket($char, &$offset, &$objtype, &$objval)
    {
        // array object
        $objtype = $char;
        ++$offset;
        if ($char == '[') {
            // get array content
            $objval = array();
            do {
                // get element
                $element = $this->getRawObject($offset);
                $offset = $element[2];
                $objval[] = $element;
            } while ($element[0] != ']');
            // remove closing delimiter
            array_pop($objval);
        }
    }

    /**
     * Process \x3C LESS-THAN SIGN and \x3E GREATER-THAN SIGN
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processAngular($char, &$offset, &$objtype, &$objval)
    {
        if (isset($this->pdfdata[($offset + 1)]) && ($this->pdfdata[($offset + 1)] == $char)) {
            // dictionary object
            $objtype = $char.$char;
            $offset += 2;
            if ($char == '<') {
                // get array content
                $objval = array();
                do {
                    // get element
                    $element = $this->getRawObject($offset);
                    $offset = $element[2];
                    $objval[] = $element;
                } while ($element[0] != '>>');
                // remove closing delimiter
                array_pop($objval);
            }
        } else {
            // hexadecimal string object
            $objtype = $char;
            ++$offset;
            if (($char == '<')
                && (preg_match(
                    '/^([0-9A-Fa-f\x09\x0a\x0c\x0d\x20]+)>/iU',
                    substr($this->pdfdata, $offset),
                    $matches
                ) == 1)
                ) {
                // remove white space characters
                $objval = strtr($matches[1], "\x09\x0a\x0c\x0d\x20", '');
                $offset += strlen($matches[0]);
            } elseif (($endpos = strpos($this->pdfdata, '>', $offset)) !== false) {
                $offset = $endpos + 1;
            }
        }
    }

    /**
     * Process default
     *
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     *
     * @return bool True in case of match, flase otherwise
     */
    protected function processDefaultName(&$offset, &$objtype, &$objval)
    {
        $status = false;
        if (substr($this->pdfdata, $offset, 6) == 'endobj') {
            // indirect object
            $objtype = 'endobj';
            $offset += 6;
            $status = true;
        } elseif (substr($this->pdfdata, $offset, 4) == 'null') {
            // null object
            $objtype = 'null';
            $offset += 4;
            $objval = 'null';
            $status = true;
        } elseif (substr($this->pdfdata, $offset, 4) == 'true') {
            // boolean true object
            $objtype = 'boolean';
            $offset += 4;
            $objval = 'true';
            $status = true;
        } elseif (substr($this->pdfdata, $offset, 5) == 'false') {
            // boolean false object
            $objtype = 'boolean';
            $offset += 5;
            $objval = 'false';
            $status = true;
        } elseif (substr($this->pdfdata, $offset, 6) == 'stream') {
            // start stream object
            $objtype = 'stream';
            $offset += 6;
            if (preg_match('/^([\r]?[\n])/isU', substr($this->pdfdata, $offset), $matches) == 1) {
                $offset += strlen($matches[0]);
                if (preg_match(
                    '/(endstream)[\x09\x0a\x0c\x0d\x20]/isU',
                    substr($this->pdfdata, $offset),
                    $matches,
                    PREG_OFFSET_CAPTURE
                ) == 1) {
                    $objval = substr($this->pdfdata, $offset, $matches[0][1]);
                    $offset += $matches[1][1];
                }
            }
            $status = true;
        } elseif (substr($this->pdfdata, $offset, 9) == 'endstream') {
            // end stream object
            $objtype = 'endstream';
            $offset += 9;
            $status = true;
        }
        return $status;
    }

    /**
     * Process default
     *
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processDefault(&$offset, &$objtype, &$objval)
    {
        if (preg_match(
            '/^([0-9]+)[\s]+([0-9]+)[\s]+R/iU',
            substr($this->pdfdata, $offset, 33),
            $matches
        ) == 1) {
            // indirect object reference
            $objtype = 'objref';
            $offset += strlen($matches[0]);
            $objval = intval($matches[1]).'_'.intval($matches[2]);
        } elseif (preg_match(
            '/^([0-9]+)[\s]+([0-9]+)[\s]+obj/iU',
            substr($this->pdfdata, $offset, 33),
            $matches
        ) == 1) {
            // object start
            $objtype = 'obj';
            $objval = intval($matches[1]).'_'.intval($matches[2]);
            $offset += strlen($matches[0]);
        } elseif (($numlen = strspn($this->pdfdata, '+-.0123456789', $offset)) > 0) {
            // numeric object
            $objtype = 'numeric';
            $objval = substr($this->pdfdata, $offset, $numlen);
            $offset += $numlen;
        }
    }
}
