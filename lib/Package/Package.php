<?php

namespace YConverter\Package;

use YConverter\Config;

abstract class Package
{
    protected $config;

    abstract function getName(): string;

    abstract function getTables(): array;

    abstract function updateTableStructure();

    /**
     * REDAXO 4 source table base names (without prefix) whose presence indicates this
     * package has data to migrate. Empty means "always run". Used to skip packages whose
     * addon was never used in the source installation.
     *
     * @return string[]
     */
    public function getSourceTables(): array
    {
        return [];
    }

    public function setConfig(Config $config)
    {
        $this->config = $config;
    }

    protected function getConfig(): Config
    {
        return $this->config;
    }

}
