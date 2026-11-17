<?php

namespace SmartImport\Exceptions;

use RuntimeException;

final class ImportCancellationRequested extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The import was cancelled before the next row could be committed.');
    }
}
