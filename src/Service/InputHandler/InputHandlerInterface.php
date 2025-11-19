<?php
// src/Service/InputHandler/InputHandlerInterface.php
namespace App\Service\InputHandler;

use App\Model\DataCollection;

interface InputHandlerInterface
{
    public function readData(string $source, ?string $range = null): DataCollection;
}
