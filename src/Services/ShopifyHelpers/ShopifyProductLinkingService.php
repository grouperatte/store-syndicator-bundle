<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

use DateTime;
use Exception;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Pimcore\Model\DataObject;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Db;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Product;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationRepository;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;

class ShopifyProductLinkingService
{
    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private ConfigurationService $configurationService
    ) {
    }

    /**
     * links pimcores objects to shopifys based on the configuration's attribute with the link option selected
     * 
     * places the remoteProduct ID in the products TorqSS:*storename*:shopifyId property 
     * sets the TorqSS:*storename*:linked property to true
     *
     * @param DateTime $afterDate OPTIONAL. If present, only looks up objects modified / created after a certain date
     * @throws conditon
     **/
    public function link(Configuration $configuration, DateTime $afterDate = null)
    {
        $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($configuration);
        $shopifyQueryService = new ShopifyQueryService($authenticator);
        $remoteStoreName = $this->configurationService->getStoreName($configuration);
        $linkedProperty = "TorqSS:" . $remoteStoreName . ":linked";
        $remoteIdProperty = "TorqSS:" . $remoteStoreName . ":shopifyId";

        if ($afterDate) {
            $afterDate = $afterDate->format('Y-m-d\TH:i:s\Z');
        }
        /*
        * get what variant field we are mapping on
        * if no marked field return failed message
        */

        /*
        * check here if we are linking based off variant or product, metafield or base value
        * hardcoded for testing
        */
        $linkingAttribute = ConfigurationService::getMapOnRow($configuration);

        /*
        *   run variant query
        */
        switch ($linkingAttribute['field type']) {
            case 'variant metafields':
                $remoteVariants = $shopifyQueryService->queryVariants(ShopifyGraphqlHelperService::buildVariantsQuery($afterDate));
                /*
                * array that contains only the field we are mapping off of
                * [remote&localval => 'variantId', 'productId']
                */
                $mapOnArray = [];
                foreach ($remoteVariants as $variantId => $remoteVariant) {
                    foreach ($remoteVariant['metafields'] as $namespaceAndKey => $metafield) {
                        if ($namespaceAndKey == $linkingAttribute['remote field']) {
                            if (array_key_exists($metafield['value'], $mapOnArray)) {
                                $mapOnArray[$metafield['value']] = 'duplicate';
                            }
                            $mapOnArray[$metafield['value']] = [
                                'variantId' => $variantId,
                                'productId' => $remoteVariant['product']
                            ];
                        }
                    }
                }
                break;
            default:
                throw new Exception("invalid or null remote field type in attribute mapping");
        }

        /*
        *   get all unlinked objects in the database for each configuration for this store and merge them to one array
        *   like ["id" => true, "id" => true]
        */
        $unlinkeds = [];
        foreach ($this->configurationRepository->getSameStoreConfigurations($configuration) as $configuration) {
            /*  
            *   get all *objectType* variants that are unlinked
            */
            foreach ($this->getUnlinkedProducts($linkedProperty, $this->configurationService->getDataobjectClass($configuration)) as $toMergeUnlinked) {
                if ($toMergeUnlinked['oo_id']) {
                    $unlinkeds[$toMergeUnlinked['oo_id']] = true;
                }
            }
        }

        /*
        *   link them together
        */
        //parent Id's we have already mapped
        $mappedParentIds = [];

        foreach ($unlinkeds as $unlinked => $value) {
            if (!$object = DataObject::getById($unlinked)) {
                throw new Exception('tried to get object with id= ' . $unlinked);
            }
            if (!$localFieldValue = AttributesService::getObjectFieldValues($object, explode('.', $linkingAttribute['local field']))) {
                continue;
            }
            if (array_key_exists($localFieldValue, $mapOnArray)) {
                $baseProduct = $object->getParent();
                $object->setProperty($linkedProperty, "Checkbox", true);
                $object->setProperty($remoteIdProperty, "Text", $mapOnArray[$localFieldValue]['variantId']);
                $object->save();
                if (!array_key_exists($baseProduct->getId(), $mappedParentIds)) {
                    $baseProduct->setProperty($linkedProperty, "Checkbox", true);
                    $baseProduct->setProperty($remoteIdProperty, "Text", $mapOnArray[$localFieldValue]['productId']);
                    $baseProduct->save();
                    $mappedParentIds[$baseProduct->getId()] = true;
                }
                unset($mapOnArray[$localFieldValue]); //dont map multiple products to this value
            }
        }
    }

    private function getUnlinkedProducts($linkedProperty, ClassDefinition $classDefinition)
    {
        $db = Db::get();

        $tablename = 'object_' . $classDefinition->getId();
        $query = "select p.oo_id from pimcore.$tablename p left outer join pimcore.properties pr on p.oo_id = pr.cid and pr.name like '$linkedProperty' where pr.name is null";
        return $db->query($query);
    }
}
