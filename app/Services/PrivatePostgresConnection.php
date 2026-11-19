<?php

namespace App\Services;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\PostgresBuilder;

class PrivatePostgresConnection extends PostgresConnection
{
    public function getSchemaBuilder(): PostgresBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new class($this) extends PostgresBuilder
        {
            public function getCurrentSchemaListing(): array
            {
                return ['easycare'];
            }
        };
    }
}
