# tc-lib-pdf-parser
*PHP library to parse PDF documents*

[![Latest Stable Version](https://poser.pugx.org/tecnickcom/tc-lib-pdf-parser/version)](https://packagist.org/packages/tecnickcom/tc-lib-pdf-parser)
![Build](https://github.com/tecnickcom/tc-lib-pdf-parser/actions/workflows/check.yml/badge.svg)
[![Coverage](https://codecov.io/gh/tecnickcom/tc-lib-pdf-parser/graph/badge.svg?token=SIGYQJG8D4)](https://codecov.io/gh/tecnickcom/tc-lib-pdf-parser)
[![License](https://poser.pugx.org/tecnickcom/tc-lib-pdf-parser/license)](https://packagist.org/packages/tecnickcom/tc-lib-pdf-parser)
[![Downloads](https://poser.pugx.org/tecnickcom/tc-lib-pdf-parser/downloads)](https://packagist.org/packages/tecnickcom/tc-lib-pdf-parser)

[![Donate via PayPal](https://img.shields.io/badge/donate-paypal-87ceeb.svg)](https://www.paypal.com/donate/?hosted_button_id=NZUEC5XS8MFBJ)
*Please consider supporting this project by making a donation via [PayPal](https://www.paypal.com/donate/?hosted_button_id=NZUEC5XS8MFBJ)*

* **category**    Library
* **package**     \Com\Tecnick\Pdf\Parser
* **author**      Nicola Asuni <info@tecnick.com>
* **copyright**   2015-2026 Nicola Asuni - Tecnick.com LTD
* **license**     https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
* **link**        https://github.com/tecnickcom/tc-lib-pdf-parser
* **SRC DOC**     https://tcpdf.org/docs/srcdoc/tc-lib-pdf-parser

## Description

PHP library to parse PDF documents.

The initial source code has been derived from [TCPDF](<http://www.tcpdf.org>).


## Getting started

First, you need to install all development dependencies using [Composer](https://getcomposer.org/):

```bash
$ curl -sS https://getcomposer.org/installer | php
$ mv composer.phar /usr/local/bin/composer
```

This project include a Makefile that allows you to test and build the project with simple commands.
To see all available options:

```bash
make help
```

To install all the development dependencies:

```bash
make deps
```

## Running all tests

Before committing the code, please check if it passes all tests using

```bash
make qa
```

All artifacts are generated in the target directory.


## Example

Examples are located in the `example` directory.

Start a development server (requires PHP 8.0+) using the command:

```
make server
```

and point your browser to <http://localhost:8000/index.php>


## Installation

Create a composer.json in your projects root-directory:

```json
{
    "require": {
        "tecnickcom/tc-lib-pdf-parser": "^3.0.0"
    }
}
```

Or add to an existing project with: 

```bash
composer require tecnickcom/tc-lib-pdf-parser ^3.0.0
```


## Packaging

This library is mainly intended to be used and included in other PHP projects using Composer.
However, since some production environments dictates the installation of any application as RPM or DEB packages,
this library includes make targets for building these packages (`make rpm` and `make deb`).
The packages are generated under the `target` directory.

When this library is installed using an RPM or DEB package, you can use it your code by including the autoloader:
```
require_once ('/usr/share/php/Com/Tecnick/Pdf/Parser/autoload.php');
```



## Developer(s) Contact

*2026 Nicola Asuni <info@tecnick.com>
