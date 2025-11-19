<?php
// src/Service/PriceService/PriceServiceInterface.php
namespace App\Service\PriceService;

interface PriceServiceInterface
{
    public function getPrice(string $upc): float;
}
