<?php

namespace Heron\Bulk\Model\Dto;

class ImportResponse
{
    /*
    |--------------------------------------------------------------------------
    | INSERT TYPES
    |--------------------------------------------------------------------------
    */

    public const INSERT = 1;
    public const UPDATE = 2;
    public const NONE = 3;
    public const ERROR = 4;

    /*
    |--------------------------------------------------------------------------
    | PROPERTIES
    |--------------------------------------------------------------------------
    */

    public string $sku = '';

    public bool $success = false;

    public int $insertType = self::NONE;

    public string $message = '';

    /*
    |--------------------------------------------------------------------------
    | TO ARRAY
    |--------------------------------------------------------------------------
    */

    public function toArray(): array
    {
        return [

            'sku' =>
                $this->sku,

            'success' =>
                $this->success,

            'insertType' =>
                $this->insertType,

            'message' =>
                $this->message
        ];
    }
}