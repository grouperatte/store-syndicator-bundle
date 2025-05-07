<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

class ShopifyGraphqlHelperService
{
    private static $CREATE_PRODUCTS_QUERY = '/shopify-queries/create-products.graphql';
    private static $UPDATE_PRODUCTS_QUERY = '/shopify-queries/update-products.graphql';
    private static $BULK_MUTATION_WRAPPER = '/shopify-queries/bulk-call-wrapper.graphql';
    private static $BULK_QUERY_WRAPPER = '/shopify-queries/bulk-query-wrapper.graphql';
    private static $FILE_UPLOAD_QUERY = '/shopify-queries/file-upload.graphql';
    private static $QUERY_FINISHED_QUERY = '/shopify-queries/check-query-finished.graphql';
    private static $GET_METAFIELDS_QUERY = '/shopify-queries/metafield-query.graphql';
    private static $GET_LINKING_QUERY = '/shopify-queries/products-linking-query.graphql';
    private static $SET_METAFIELD_QUERY = '/shopify-queries/metafield-set-value.graphql';
    private static $GET_STORE_LOCATION_QUERY = '/shopify-queries/store-location-query.graphql';
    private static $SET_VARIANT_STOCK = '/shopify-queries/bulk-set-quantities.graphql';
    private static $QUERY_PROGRESS_QUERY = '/shopify-queries/check-query-progress.graphql';
    private static $CREATE_BULK_VARIANTS = '/shopify-queries/create-bulk-variants.graphql';
    private static $UPDATE_BULK_VARIANTS = '/shopify-queries/update-bulk-variants.graphql';
    private static $SET_PRODUCT_STORE_ID = '/shopify-queries/product-publish.graphql';



    public static function buildCreateProductsQuery($remoteFile)
    {
        return self::bulkwrap(file_get_contents(dirname(__FILE__) . self::$CREATE_PRODUCTS_QUERY), $remoteFile);
    }

    public static function buildUpdateQuery($remoteFile)
    {
        $updateProductsQuery = file_get_contents(dirname(__FILE__) . self::$UPDATE_PRODUCTS_QUERY);
        return self::bulkwrap($updateProductsQuery, $remoteFile);
    }

    private static function bulkwrap(string $towrap, $remoteFile)
    {
        $bulkMutationWrapper = file_get_contents(dirname(__FILE__) . self::$BULK_MUTATION_WRAPPER);
        $bulkMutationWrapper = preg_replace('/REPLACEMEMUTATION/', $towrap, $bulkMutationWrapper);
        $bulkMutationWrapper = preg_replace('/REPLACEMEPATH/', $remoteFile, $bulkMutationWrapper);
        return $bulkMutationWrapper;
    }

    private static function bulkQueryWrap(string $towrap)
    {
        $bulkQueryWrapper = file_get_contents(dirname(__FILE__) . self::$BULK_QUERY_WRAPPER);
        $bulkQueryWrapper = preg_replace('/REPLACEMEQUERY/', $towrap, $bulkQueryWrapper);
        return $bulkQueryWrapper;
    }

    public static function buildFileUploadQuery()
    {
        $fileUploadQuery = file_get_contents(dirname(__FILE__) . self::$FILE_UPLOAD_QUERY);
        return $fileUploadQuery;
    }

    public static function buildQueryFinishedQuery($queryType)
    {
        $queryFinishedQuery = file_get_contents(dirname(__FILE__) . self::$QUERY_FINISHED_QUERY);
        $queryFinishedQuery = preg_replace("/REPLACEMEMUTATION/", $queryType, $queryFinishedQuery);
        return $queryFinishedQuery;
    }
    public static function buildQueryProgressQuery($gid)
    {
        $queryProgressQuery = file_get_contents(dirname(__FILE__) . self::$QUERY_PROGRESS_QUERY);
        $queryProgressQuery = preg_replace("/REPLACEMEGID/", $gid, $queryProgressQuery);
        return $queryProgressQuery;
    }

    public static function buildMetafieldsQuery()
    {
        $getMetafieldsQuery = file_get_contents(dirname(__FILE__) . self::$GET_METAFIELDS_QUERY);
        $getMetafieldsQuery = preg_replace("/REPLACEMETYPE/", "PRODUCT", $getMetafieldsQuery);
        return $getMetafieldsQuery;
    }

    public static function buildVariantMetafieldsQuery()
    {
        $getMetafieldsQuery = file_get_contents(dirname(__FILE__) . self::$GET_METAFIELDS_QUERY);
        $getMetafieldsQuery = preg_replace("/REPLACEMETYPE/", "PRODUCTVARIANT", $getMetafieldsQuery);
        return $getMetafieldsQuery;
    }

    public static function buildProductLinkingQuery($metafield)
    {
        $linkingQuery = file_get_contents(dirname(__FILE__) . self::$GET_LINKING_QUERY);
        if ($metafield) {
            $metafieldArray = explode(".", $metafield);
            $linkingQuery = preg_replace("/REPLACEMEMETAFIELD/", 'linkingId: metafield(namespace: \"' . $metafieldArray[0] . '\", key: \"' . $metafieldArray[1] . '\"){
                value
            }
            lastUpdated: metafield(namespace: \"custom\", key: \"last_updated\"){
                value
            }', $linkingQuery);
        }

        return self::bulkQueryWrap($linkingQuery);
    }
    public static function buildMetafieldSetQuery($remoteFile)
    {
        $updateVariantsQuery = file_get_contents(dirname(__FILE__) . self::$SET_METAFIELD_QUERY);
        return self::bulkwrap($updateVariantsQuery, $remoteFile);
    }

    public static function buildStoreLocationQuery()
    {
        $storeLocationQuery = file_get_contents(dirname(__FILE__) . self::$GET_STORE_LOCATION_QUERY);
        return $storeLocationQuery;
    }

    public static function buildSetVariantsStockQuery()
    {
        return file_get_contents(dirname(__FILE__) . self::$SET_VARIANT_STOCK);
    }

    public static function buildCreateBulkVariantQuery()
    {
        return file_get_contents(dirname(__FILE__) . self::$CREATE_BULK_VARIANTS);
    }

    public static function buildUpdateBulkVariantQuery()
    {
        return file_get_contents(dirname(__FILE__) . self::$UPDATE_BULK_VARIANTS);
    }

    public static function buildSetProductStoreIdQuery($remoteFile)
    {
        $updateProductsQuery = file_get_contents(dirname(__FILE__) . self::$SET_PRODUCT_STORE_ID);
        return self::bulkwrap($updateProductsQuery, $remoteFile);
    }
}
