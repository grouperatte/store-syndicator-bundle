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
    private static $GET_PRODUCTS_QUERY = '/shopify-queries/products-query.graphql';
    private static $CREATE_MEDIA_QUERY = '/shopify-queries/create-media.graphql';
    private static $UPDATE_MEDIA_QUERY = '/shopify-queries/update-media.graphql';
    private static $GET_METAFIELDS_QUERY = '/shopify-queries/metafield-query.graphql';
    private static $UPDATE_VARIANTS_QUERY = '/shopify-queries/update-product-variants.graphql';
    private static $GET_VARIANTS_QUERY = '/shopify-queries/variants-query.graphql';

    public static function buildCreateQuery($remoteFile)
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

    public static function buildProductsQuery($afterDateString = null)
    {
        $getProductsQuery = file_get_contents(dirname(__FILE__) . self::$GET_PRODUCTS_QUERY);
        if ($afterDateString) {
            $getProductsQuery = preg_replace("/REPLACEMEPARAMS/", 'query: \"created_at:>' . "'" . $afterDateString . "' OR " . 'updated_at:>' . "'" . $afterDateString . "'" . '\"', $getProductsQuery);
        } else {
            $getProductsQuery = preg_replace("/\(REPLACEMEPARAMS\)/", '', $getProductsQuery);
        }

        return self::bulkQueryWrap($getProductsQuery);
    }

    public static function buildCreateMediaQuery($remoteFile)
    {
        $createMediaQuery = file_get_contents(dirname(__FILE__) . self::$CREATE_MEDIA_QUERY);
        return self::bulkwrap($createMediaQuery, $remoteFile);
    }

    public static function buildUpdateMediaQuery($remoteFile)
    {
        $updateMediaQuery = file_get_contents(dirname(__FILE__) . self::$UPDATE_MEDIA_QUERY);
        return self::bulkwrap($updateMediaQuery, $remoteFile);
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

    public static function buildUpdateVariantsQuery($remoteFile)
    {
        $updateVariantsQuery = file_get_contents(dirname(__FILE__) . self::$UPDATE_VARIANTS_QUERY);
        return self::bulkwrap($updateVariantsQuery, $remoteFile);
    }

    public static function buildVariantsQuery($afterDateString = null)
    {
        $variantsQuery = file_get_contents(dirname(__FILE__) . self::$GET_VARIANTS_QUERY);
        if ($afterDateString) {
            $variantsQuery = preg_replace("/REPLACEMEPARAMS/", 'query: \"created_at:>' . "'" . $afterDateString . "' OR " . 'updated_at:>' . "'" . $afterDateString . "'" . '\"', $variantsQuery);
        } else {
            $variantsQuery = preg_replace("/\(REPLACEMEPARAMS\)/", '', $variantsQuery);
        }

        return self::bulkQueryWrap($variantsQuery);
    }
}
