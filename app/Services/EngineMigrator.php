<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;

class EngineMigrator extends Migrator
{
    public function getMigrationFiles(mixed $paths): array
    {
        if ($this->resolveConnection($this->getConnection())->getDriverName() === 'pgsql') {
            $paths = array_map(fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/') === str_replace('\\', '/', database_path('migrations'))
                ? database_path('postgresql') : $path, (array) $paths);
        }

        return parent::getMigrationFiles($paths);
    }
}
