<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * 05.13 §6.3: the user belongs to more than one company and has not
 * chosen which to act for this session. Rendered as a redirect to the
 * company chooser on pages and as 409 `company_choice_required` on /api
 * (bootstrap/app.php).
 */
final class CompanyChoiceRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Choose which company you are ordering for.');
    }
}
