<?php

/**
 * RawObject.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2024 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Com\Tecnick\Pdf\Parser\Process;

/**
 * Com\Tecnick\Pdf\Parser\Process\RawObject
 *
 * Process Raw Objects
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfParser
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2024 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * @phpstan-type RawObjectArray array{
 *                 0: string,
 *                 1: string|array<int, array{
 *                     0: string,
 *                     1: string|array<int, array{
 *                         0: string,
 *                         1: string|array<int, array{
 *                             0: string,
 *                             1: string|array<int, array{
 *                                 0: string,
 *                                 1: string|array<int, array{
 *                                     0: string,
 *                                     1: string,
 *                                     2: int,
 *                                     3?: array{string, array<string>},
 *                                 }>,
 *                                 2: int,
 *                                 3?: array{string, array<string>},
 *                             }>,
 *                             2: int,
 *                             3?: array{string, array<string>},
 *                         }>,
 *                         2: int,
 *                         3?: array{string, array<string>},
 *                     }>,
 *                     2: int,
 *                     3?: array{string, array<string>},
 *                   }>,
 *                 2: int,
 *                 3?: array{string, array<string>},
 *             }
 */
abstract class RawObject
{
    /**
     * Raw content of the PDF document.
     */
    protected string $pdfdata = '';

    /**
     * Array of PDF objects.
     *
     * @var array<string, array<int, RawObjectArray>>
     */
    protected array $objects = [];

    /**
     * Map symbols with corresponding processing methods.
     *
     * @var array<string, string>
     */
    protected const SYMBOLMETHOD = [
        // \x2F SOLIDUS
        '/' => 'Solidus',
        // \x28 LEFT PARENTHESIS
        '(' => 'Parenthesis',
        // \x29 RIGHT PARENTHESIS
        ')' => 'Parenthesis',
        // \x5B LEFT SQUARE BRACKET
        '[' => 'Bracket',
        // \x5D RIGHT SQUARE BRACKET
        ']' => 'Bracket',
        // \x3C LESS-THAN SIGN
        '<' => 'Angular',
        // \x3E GREATER-THAN SIGN
        '>' => 'Angular',
    ];

    /**
     * Get object type, raw value and offset to next object
     *
     * @param int $offset Object offset.
     *
     * @return RawObjectArray Array containing: object type, raw value and offset to next object
     */
    protected function getRawObject(int $offset = 0): array
    {
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

        $objtype = '';
        $objval = '';
        // map symbols with corresponding processing methods
        if (isset(self::SYMBOLMETHOD[$char])) {
            $method = 'process' . self::SYMBOLMETHOD[$char];
            $this->$method($char, $offset, $objtype, $objval);
        } elseif ($this->processDefaultName($offset, $objtype, $objval) === false) {
            $this->processDefault($offset, $objtype, $objval);
        }

        return [$objtype, $objval, $offset];
    }

    /**
     * Process name object
     * \x2F SOLIDUS
     *
     * @param string                   $char    Symbol to process
     * @param int                      $offset  Offset
     * @param string                   $objtype Object type
     * @param string|array<int, array{
     *        0: string,
     *        1: string|array<int, array{
     *            0: string,
     *            1: string,
     *            2: int,
     *        }>,
     *        2: int,
     *    }> $objval  Object content
     */
    protected function processSolidus(string $char, int &$offset, string &$objtype, string|array &$objval): void
    {
        $objtype = $char;
        ++$offset;
        if (
            preg_match(
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
     * @param string                   $char    Symbol to process
     * @param int                      $offset  Offset
     * @param string                   $objtype Object type
     * @param string|array<int, array{
     *        0: string,
     *        1: string|array<int, array{
     *            0: string,
     *            1: string,
     *            2: int,
     *        }>,
     *        2: int,
     *    }> $objval  Object content
     */
    protected function processParenthesis(string $char, int &$offset, string &$objtype, string|array &$objval): void
    {
        $objtype = $char;
        ++$offset;
        $strpos = $offset;
        if ($char == '(') {
            $open_bracket = 1;
            while ($open_bracket > 0) {
                if (! isset($this->pdfdata[$strpos])) {
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
     * @param string            $char    Symbol to process
     * @param int               $offset  Offset
     * @param string            $objtype Object type
     * @param string|array<int, array{
     *        0: string,
     *        1: string|array<int, array{
     *            0: string,
     *            1: string,
     *            2: int,
     *        }>,
     *        2: int,
     *    }> $objval  Object content
     */
    protected function processBracket(string $char, int &$offset, string &$objtype, string|array &$objval): void
    {
        // array object
        $objtype = $char;
        ++$offset;
        if ($char == '[') {
            // get array content
            $objval = [];
            do {
                $element = $this->getRawObject($offset);
                $offset = $element[2];
                $objval[] = $element; // @phpstan-ignore parameterByRef.type
            } while ($element[0] != ']');

            if (count($objval) > 0) {
                // remove closing delimiter
                array_pop($objval); // @phpstan-ignore parameterByRef.type
            }
        }
    }

    /**
     * Process \x3C LESS-THAN SIGN and \x3E GREATER-THAN SIGN
     *
     * @param string                   $char    Symbol to process
     * @param int                      $offset  Offset
     * @param string                   $objtype Object type
     * @param string|array<int, array{
     *        0: string,
     *        1: string|array<int, array{
     *            0: string,
     *            1: string,
     *            2: int,
     *        }>,
     *        2: int,
     *    }> $objval  Object content
     */
    protected function processAngular(string $char, int &$offset, string &$objtype, string|array &$objval): void
    {
        if (isset($this->pdfdata[($offset + 1)]) && ($this->pdfdata[($offset + 1)] === $char)) {
            // dictionary object
            $objtype = $char . $char;
            $offset += 2;
            if ($char == '<') {
                // get array content
                $objval = [];
                do {
                    $element = $this->getRawObject($offset);
                    $offset = $element[2];
                    $objval[] = $element; // @phpstan-ignore parameterByRef.type
                } while ($element[0] != '>>');

                if (count($objval) > 0) {
                    // remove closing delimiter
                    array_pop($objval); // @phpstan-ignore parameterByRef.type
                }
            }
        } else {
            // hexadecimal string object
            $objtype = $char;
            ++$offset;
            if (
                ($char == '<')
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
     * @param int                      $offset  Offset
     * @param string                   $objtype Object type
     * @param string|array<int, array{
     *        0: string,
     *        1: string|array<int, array{
     *            0: string,
     *            1: string,
     *            2: int,
     *        }>,
     *        2: int,
     *    }> $objval  Object content
     *
     * @return bool True in case of match, flase otherwise
     */
    protected function processDefaultName(int &$offset, string &$objtype, string|array &$objval): bool
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
                if (
                    preg_match(
                        '/(endstream)[\x09\x0a\x0c\x0d\x20]/isU',
                        substr($this->pdfdata, $offset),
                        $matches,
                        PREG_OFFSET_CAPTURE
                    ) == 1
                ) {
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
     * @param int                      $offset  Offset
     * @param string                   $objtype Object type
     * @param string|array<int, array{
     *        0: string,
     *        1: string|array<int, array{
     *            0: string,
     *            1: string,
     *            2: int,
     *        }>,
     *        2: int,
     *    }> $objval  Object content
     */
    protected function processDefault(int &$offset, string &$objtype, string|array &$objval): void
    {
        if (
            preg_match(
                '/^([0-9]+)[\s]+([0-9]+)[\s]+R/iU',
                substr($this->pdfdata, $offset, 33),
                $matches
            ) == 1
        ) {
            // indirect object reference
            $objtype = 'objref';
            $offset += strlen($matches[0]);
            $objval = (int) $matches[1] . '_' . (int) $matches[2];
        } elseif (
            preg_match(
                '/^([0-9]+)[\s]+([0-9]+)[\s]+obj/iU',
                substr($this->pdfdata, $offset, 33),
                $matches
            ) == 1
        ) {
            // object start
            $objtype = 'obj';
            $objval = (int) $matches[1] . '_' . (int) $matches[2];
            $offset += strlen($matches[0]);
        } elseif (($numlen = strspn($this->pdfdata, '+-.0123456789', $offset)) > 0) {
            // numeric object
            $objtype = 'numeric';
            $objval = substr($this->pdfdata, $offset, $numlen);
            $offset += $numlen;
        }
    }
}
