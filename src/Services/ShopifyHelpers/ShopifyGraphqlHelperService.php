<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

class ShopifyGraphqlHelperService
{
    private string $createProductsQuery;
    private string $updateProductsQuery;
    private string $bulkQueryWrapper;
    private string $fileUploadQuery;
    private string $queryFinishedQuery;

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

    public function buildFileUploadQuery(string $resource, string $filename, string $mimetype)
    {
        if (!isset($this->fileUploadQuery)) {
            $this->fileUploadQuery = file_get_contents(dirname(__FILE__) . '/shopify-queries/file-upload.graphql');
        }
        $query = $this->fileUploadQuery;
        $query = preg_replace("/REPLACEMERESOURCE/", $resource, $query);
        $query = preg_replace("/REPLACEMEFILENAME/", $filename, $query);
        $query = preg_replace("/REPLACEMEMIMETYPE/", $mimetype, $query);
        return $query;
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
}
