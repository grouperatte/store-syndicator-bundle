<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

class ShopifyGraphqlHelperService
{
    private string $createProductsQuery;
    private string $updateProductsQuery;
    private string $bulkQueryWrapper;
    private string $fileUploadQuery;
    private string $queryFinishedQuery;
    private string $getProductsQuery;
    private string $createMediaQuery;
    private string $updateMediaQuery;

    public function __construct()
    {
    }

    public function buildCreateQuery($remoteFile)
    {
        if (!isset($this->createProductsQuery)) {
            $this->createProductsQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/create-products.graphql');
        }
        return $this->bulkwrap($this->createProductsQuery, $remoteFile);
    }

    public function buildUpdateQuery($remoteFile)
    {
        if (!isset($this->updateProductsQuery)) {
            $this->updateProductsQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/update-products.graphql');
        }
        return $this->bulkwrap($this->updateProductsQuery, $remoteFile);
    }

    private function bulkwrap(string $towrap, $remoteFile)
    {
        if (!isset($this->bulkQueryWrapper)) {
            $this->bulkQueryWrapper = file_get_contents(dirname(__FILE__) . '/shopify-queries/bulk-call-wrapper.graphql');
        }
        $bulkquery = $this->bulkQueryWrapper;
        $bulkquery = preg_replace('/REPLACEMEMUTATION/', $towrap, $bulkquery);
        $bulkquery = preg_replace('/REPLACEMEPATH/', $remoteFile, $bulkquery);
        return $bulkquery;
    }

    public function buildFileUploadQuery()
    {
        if (!isset($this->fileUploadQuery)) {
            $this->fileUploadQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/file-upload.graphql');
        }
        return $this->fileUploadQuery;
    }

    public function buildQueryFinishedQuery($queryType)
    {
        if (!isset($this->queryFinishedQuery)) {
            $this->queryFinishedQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/check-query-finished.graphql');
        }
        $query = $this->queryFinishedQuery;
        $query = preg_replace("/REPLACEMEMUTATION/", $queryType, $query);
        return $query;
    }

    public function buildProductsQuery()
    {
        if (!isset($this->getProductsQuery)) {
            $this->getProductsQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/products-query.graphql');
        }
        return $this->getProductsQuery;
    }

    public function buildCreateMediaQuery($remoteFile)
    {
        if (!isset($this->createMediaQuery)) {
            $this->createMediaQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/create-media.graphql');
        }
        return $this->bulkwrap($this->createMediaQuery, $remoteFile);
    }

    public function buildUpdateMediaQuery($remoteFile)
    {
        if (!isset($this->updateMediaQuery)) {
            $this->updateMediaQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/update-media.graphql');
        }
        return $this->bulkwrap($this->updateMediaQuery, $remoteFile);
    }
}
