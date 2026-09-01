<?php

namespace App\Merge;

enum LintSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
