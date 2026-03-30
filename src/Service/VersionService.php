<?php

namespace App\Service;

class VersionService
{
    /**
     * Read the current version from the VERSION file and return it as a string. If the file is not found, return '1.0'.
     * @return string
     */
    public static function getVersion(): string
    {
        $versionFilePath = __DIR__ . '/../../VERSION';
        if (file_exists($versionFilePath)) {
            return trim(file_get_contents($versionFilePath));
        }
        return '1.0';
    }

}