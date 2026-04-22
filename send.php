<?php

declare(strict_types=1);

use Local\CatalogTransfer\SectionExporter;

define("NO_KEEP_STATISTIC", true);
define("NO_AGENT_STATISTIC", true);
define("NO_AGENT_CHECK", true);
define("DisableEventsCheck", true);

require $_SERVER["DOCUMENT_ROOT"] .
    "/bitrix/modules/main/include/prolog_before.php";

$iblockId = (int) ($_REQUEST["IBLOCK_ID"] ?? 0);
$sectionId = (int) ($_REQUEST["SECTION_ID"] ?? 0);

if ($iblockId <= 0 || $sectionId <= 0) {
    http_response_code(400);
    echo "Не переданы IBLOCK_ID или SECTION_ID";
    exit();
}

$exporter = new SectionExporter(
    iblockId: $iblockId,
    rootSectionId: $sectionId,
    endpoint: "https://target-site.ru/local/api/catalog-import/", // URL принимающей стороны
    sourceCode: "source-site",
    baseUrl: "https://source-site.ru",
    batchSize: 2000,
);

try {
    $result = $exporter->send();

    header("Content-Type: application/json; charset=UTF-8");
    echo \Bitrix\Main\Web\Json::encode(
        [
            "success" => true,
            "result" => $result,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
} catch (\Throwable $e) {
    http_response_code(500);

    echo \Bitrix\Main\Web\Json::encode(
        [
            "success" => false,
            "error" => $e->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
}
