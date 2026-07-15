<?php

declare(strict_types=1);

$root =
    dirname(__DIR__);

$failures = [];

$assert = static function (
    bool $condition,
    string $message
) use (&$failures): void {

    if (!$condition) {
        $failures[] = $message;
    }
};

$phpFiles =
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        )
    );

foreach ($phpFiles as $file) {

    if ($file->getExtension() !== 'php') {
        continue;
    }

    $command =
        PHP_BINARY
        . ' -l '
        . escapeshellarg($file->getPathname());

    exec(
        $command,
        $output,
        $exitCode
    );

    $assert(
        $exitCode === 0,
        'PHP lint failed: ' . $file->getPathname()
    );
}

foreach ([
    'etc/webapi.xml',
    'etc/di.xml',
    'etc/module.xml',
    'etc/acl.xml'
] as $xmlFile) {

    $path =
        $root
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $xmlFile
        );

    $previous =
        libxml_use_internal_errors(true);

    $xml =
        simplexml_load_file($path);

    $assert(
        $xml !== false,
        'Invalid XML: ' . $xmlFile
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previous);
}

$webapi =
    simplexml_load_file($root . '/etc/webapi.xml');

$protectedRoutes = [
    '/V1/heron/import-products',
    '/V1/heron/reindex',
    '/V1/heron/images',
    '/V1/heron/clean-index',
    '/V1/heron/clean-cache',
    '/V1/heron/delete-products',
    '/V1/heron/images-local/:batchId',
    '/V1/heron/batch-stop/:batchId',
    '/V1/heron/update-qty'
];

foreach ($webapi->route as $route) {

    $url =
        (string)$route['url'];

    if (!in_array($url, $protectedRoutes, true)) {
        continue;
    }

    $resource =
        (string)$route->resources->resource['ref'];

    $assert(
        $resource === 'Heron_Bulk::operations',
        'Route must be protected: ' . $url
    );
}

$imageImport =
    file_get_contents($root . '/Service/ImageBulkDbImportService.php');

$assert(
    strpos($imageImport, "'catalog:images:resize'") !== false,
    'Image import must run catalog:images:resize'
);

$assert(
    strpos($imageImport, 'extractZipSafely') !== false,
    'Image import must use safe ZIP extraction'
);

$assert(
    strpos($imageImport, '.stop') !== false,
    'Image import must support cooperative batch stop'
);

$deleteProducts =
    file_get_contents($root . '/Model/DeleteProducts.php');

$assert(
    strpos($deleteProducts, 'DELETE_ALL_PRODUCTS') !== false,
    'Delete products must require confirmation code'
);

$legacyCommandFiles = [
    'Model/Reindex.php',
    'Model/DeleteProducts.php',
    'Service/ImageBulkDbImportService.php'
];

foreach ($legacyCommandFiles as $legacyFile) {

    $content =
        file_get_contents($root . '/' . $legacyFile);

    $assert(
        strpos($content, 'shell_exec(') === false,
        'Legacy shell_exec found in ' . $legacyFile
    );

    $assert(
        strpos($content, 'exec(') === false,
        'Legacy exec found in ' . $legacyFile
    );
}

if (!empty($failures)) {

    echo "FAILED\n";

    foreach ($failures as $failure) {
        echo '- ' . $failure . "\n";
    }

    exit(1);
}

echo "OK\n";
