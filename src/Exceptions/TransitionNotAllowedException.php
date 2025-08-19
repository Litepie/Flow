<?php

namespace Litepie\Flow\Exceptions;

use Exception;

class TransitionNotAllowedException extends FlowException
{
    public function __construct(string $transition, string $modelClass)
    {
        $message = "Transition '{$transition}' is not allowed for model '{$modelClass}'";
        parent::__construct($message);
    }
}
