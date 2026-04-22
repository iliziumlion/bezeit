<?php

declare(strict_types=1);

namespace Local\CatalogTransfer;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use CFile;
use CIBlockElement;
use CIBlockSection;
use CPrice;

final class SectionExporter
{
    private const DEFAULT_BATCH_SIZE = 2000;

    public function __construct(
        private readonly int $iblockId,
        private readonly int $rootSectionId,
        private readonly string $endpoint,
        private readonly string $sourceCode,
        private readonly string $baseUrl,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        if (!Loader::includeModule("iblock")) {
            throw new \RuntimeException("Модуль iblock не подключен");
        }

        if (!Loader::includeModule("catalog")) {
            throw new \RuntimeException("Модуль catalog не подключен");
        }

        if (
            $this->batchSize < 1 ||
            $this->batchSize > self::DEFAULT_BATCH_SIZE
        ) {
            throw new \InvalidArgumentException(
                "batchSize должен быть в диапазоне 1..2000",
            );
        }
    }

    public function send(): array
    {
        $rootSection = $this->getRootSection();
        $sections = $this->getSectionTree($rootSection);
        $sectionIds = array_column($sections, "id");
        $sectionIdMap = array_flip($sectionIds);

        $result = [];
        $batchNumber = 1;
        $lastElementId = 0;

        while (true) {
            $products = $this->getProductsBatch($lastElementId, $sectionIdMap);

            if ($products === []) {
                break;
            }

            $lastElementId = (int) end($products)["source_id"];

            $payload = [
                "meta" => [
                    "source" => $this->sourceCode,
                    "iblock_id" => $this->iblockId,
                    "root_section_id" => $this->rootSectionId,
                    "root_section_transfer_uid" => $this->makeSectionUid(
                        $this->rootSectionId,
                    ),
                    "batch" => $batchNumber,
                    "batch_size" => $this->batchSize,
                    "generated_at" => date(DATE_ATOM),
                ],
                "sections" => array_map(
                    fn(
                        array $section,
                    ): array => $this->normalizeSectionForTransfer($section),
                    $sections,
                ),
                "products" => $products,
            ];

            $response = $this->sendPayload($payload);

            $result[] = [
                "batch" => $batchNumber,
                "count" => count($products),
                "http_status" => $response["status"],
                "response_body" => $response["body"],
            ];

            $batchNumber++;
        }

        return $result;
    }

    private function getRootSection(): array
    {
        $sectionRes = CIBlockSection::GetList(
            [],
            [
                "IBLOCK_ID" => $this->iblockId,
                "=ID" => $this->rootSectionId,
            ],
            false,
            [
                "ID",
                "IBLOCK_ID",
                "IBLOCK_SECTION_ID",
                "NAME",
                "CODE",
                "XML_ID",
                "DESCRIPTION",
                "PICTURE",
                "SORT",
                "ACTIVE",
                "LEFT_MARGIN",
                "RIGHT_MARGIN",
                "DEPTH_LEVEL",
            ],
        );

        $section = $sectionRes->Fetch();

        if (!$section) {
            throw new \RuntimeException("Корневой раздел не найден");
        }

        return $section;
    }

    private function getSectionTree(array $rootSection): array
    {
        $result = [];

        $sectionRes = CIBlockSection::GetList(
            ["LEFT_MARGIN" => "ASC"],
            [
                "IBLOCK_ID" => $this->iblockId,
                ">=LEFT_MARGIN" => $rootSection["LEFT_MARGIN"],
                "<=RIGHT_MARGIN" => $rootSection["RIGHT_MARGIN"],
            ],
            false,
            [
                "ID",
                "IBLOCK_ID",
                "IBLOCK_SECTION_ID",
                "NAME",
                "CODE",
                "XML_ID",
                "DESCRIPTION",
                "PICTURE",
                "SORT",
                "ACTIVE",
                "LEFT_MARGIN",
                "RIGHT_MARGIN",
                "DEPTH_LEVEL",
            ],
        );

        while ($section = $sectionRes->Fetch()) {
            $result[] = [
                "id" => (int) $section["ID"],
                "parent_id" => (int) $section["IBLOCK_SECTION_ID"],
                "name" => (string) $section["NAME"],
                "code" => (string) $section["CODE"],
                "xml_id" => (string) $section["XML_ID"],
                "description" => (string) $section["DESCRIPTION"],
                "picture_url" => $this->buildAbsoluteFileUrl(
                    (int) $section["PICTURE"],
                ),
                "sort" => (int) $section["SORT"],
                "active" => $section["ACTIVE"] === "Y",
                "depth_level" => (int) $section["DEPTH_LEVEL"],
            ];
        }

        return $result;
    }

    private function getProductsBatch(
        int $lastElementId,
        array $allowedSectionIds,
    ): array {
        $result = [];

        $elementRes = CIBlockElement::GetList(
            ["ID" => "ASC"],
            [
                "IBLOCK_ID" => $this->iblockId,
                "SECTION_ID" => $this->rootSectionId,
                "INCLUDE_SUBSECTIONS" => "Y",
                ">ID" => $lastElementId,
            ],
            false,
            ["nTopCount" => $this->batchSize],
            [
                "ID",
                "IBLOCK_ID",
                "IBLOCK_SECTION_ID",
                "NAME",
                "CODE",
                "XML_ID",
                "ACTIVE",
                "SORT",
                "PREVIEW_TEXT",
                "DETAIL_TEXT",
                "PREVIEW_PICTURE",
                "DETAIL_PICTURE",
            ],
        );

        while ($item = $elementRes->GetNext()) {
            $elementId = (int) $item["ID"];

            $sectionIds = $this->getElementSectionIds(
                $elementId,
                $allowedSectionIds,
            );

            $result[] = [
                "source_id" => $elementId,
                "transfer_uid" => $this->makeElementUid($elementId),
                "name" => (string) $item["NAME"],
                "code" => (string) $item["CODE"],
                "xml_id" => (string) $item["XML_ID"],
                "active" => $item["ACTIVE"] === "Y",
                "sort" => (int) $item["SORT"],
                "preview_text" => (string) $item["PREVIEW_TEXT"],
                "detail_text" => (string) $item["DETAIL_TEXT"],
                "preview_picture_url" => $this->buildAbsoluteFileUrl(
                    (int) $item["PREVIEW_PICTURE"],
                ),
                "detail_picture_url" => $this->buildAbsoluteFileUrl(
                    (int) $item["DETAIL_PICTURE"],
                ),
                "main_section_transfer_uid" => $item["IBLOCK_SECTION_ID"]
                    ? $this->makeSectionUid((int) $item["IBLOCK_SECTION_ID"])
                    : null,
                "section_transfer_uids" => array_map(
                    fn(int $sectionId): string => $this->makeSectionUid(
                        $sectionId,
                    ),
                    $sectionIds,
                ),
                "prices" => $this->getPrices($elementId),
            ];
        }

        return $result;
    }

    private function getElementSectionIds(
        int $elementId,
        array $allowedSectionIds,
    ): array {
        $result = [];

        $groupRes = CIBlockElement::GetElementGroups($elementId, true, ["ID"]);

        while ($group = $groupRes->Fetch()) {
            $sectionId = (int) $group["ID"];

            if (isset($allowedSectionIds[$sectionId])) {
                $result[] = $sectionId;
            }
        }

        return array_values(array_unique($result));
    }

    private function getPrices(int $productId): array
    {
        $prices = [];

        $priceRes = CPrice::GetListEx([], ["PRODUCT_ID" => $productId]);

        while ($price = $priceRes->Fetch()) {
            $prices[] = [
                "catalog_group_id" => (int) $price["CATALOG_GROUP_ID"],
                "price" => (string) $price["PRICE"],
                "currency" => (string) $price["CURRENCY"],
            ];
        }

        return $prices;
    }

    private function normalizeSectionForTransfer(array $section): array
    {
        $parentTransferUid = null;

        if (
            $section["parent_id"] > 0 &&
            $section["id"] !== $this->rootSectionId
        ) {
            $parentTransferUid = $this->makeSectionUid($section["parent_id"]);
        }

        return [
            "transfer_uid" => $this->makeSectionUid($section["id"]),
            "parent_transfer_uid" => $parentTransferUid,
            "name" => $section["name"],
            "code" => $section["code"],
            "xml_id" => $section["xml_id"],
            "description" => $section["description"],
            "picture_url" => $section["picture_url"],
            "sort" => $section["sort"],
            "active" => $section["active"],
            "depth_level" => $section["depth_level"],
        ];
    }

    private function sendPayload(array $payload): array
    {
        $httpClient = new HttpClient([
            "socketTimeout" => 60,
            "streamTimeout" => 60,
            "waitResponse" => true,
        ]);

        $httpClient->setHeader(
            "Content-Type",
            "application/json; charset=UTF-8",
            true,
        );

        $body = \Bitrix\Main\Web\Json::encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $responseBody = $httpClient->post($this->endpoint, $body);

        return [
            "status" => $httpClient->getStatus(),
            "body" => (string) $responseBody,
        ];
    }

    private function buildAbsoluteFileUrl(int $fileId): ?string
    {
        if ($fileId <= 0) {
            return null;
        }

        $path = CFile::GetPath($fileId);

        if (!$path) {
            return null;
        }

        return rtrim($this->baseUrl, "/") . $path;
    }

    private function makeSectionUid(int $sectionId): string
    {
        return sprintf(
            "section:%s:%d:%d",
            $this->sourceCode,
            $this->iblockId,
            $sectionId,
        );
    }

    private function makeElementUid(int $elementId): string
    {
        return sprintf(
            "product:%s:%d:%d",
            $this->sourceCode,
            $this->iblockId,
            $elementId,
        );
    }
}
