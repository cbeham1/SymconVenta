<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class ValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateVentaHumidifier(): void
    {
        $this->validateModule(__DIR__ . '/../VentaHumidifier');
    }

    public function testValidateVentaAutomation(): void
    {
        $this->validateModule(__DIR__ . '/../VentaAutomation');
    }
}
