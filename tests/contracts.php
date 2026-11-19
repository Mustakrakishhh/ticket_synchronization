<?php

declare(strict_types=1);

if (!function_exists('yaml_parse_file')) {
    throw new RuntimeException('PHP yaml extension is required to validate OpenAPI contracts.');
}
foreach (['docs/openapi.yaml', 'docs/bitrix24-oauth-base.openapi.yaml'] as $path) {
    $document = yaml_parse_file(dirname(__DIR__) . '/' . $path);
    if (!is_array($document) || !isset($document['openapi'], $document['info'], $document['paths'])) {
        throw new RuntimeException("Invalid OpenAPI contract: {$path}");
    }
}

echo "OpenAPI contract checks passed.\n";
