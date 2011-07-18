<?php

/**
 * CardDAV plugin 
 *
 * @package Sabre
 * @subpackage CardDAV
 * @copyright Copyright (C) 2007-2011 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */


/**
 * The CardDAV plugin adds CardDAV functionality to the WebDAV server
 */
class Sabre_CardDAV_Plugin extends Sabre_DAV_ServerPlugin {

    /**
     * Url to the addressbooks
     */
    const ADDRESSBOOK_ROOT = 'addressbooks';

    /**
     * xml namespace for CardDAV elements
     */
    const NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';

    /**
     * Server class 
     *
     * @var Sabre_DAV_Server 
     */
    protected $server;

    /**
     * Initializes the plugin 
     *
     * @param Sabre_DAV_Server $server 
     * @return void 
     */
    public function initialize(Sabre_DAV_Server $server) {

        /* Events */
        $server->subscribeEvent('beforeGetProperties', array($this, 'beforeGetProperties'));
        $server->subscribeEvent('report', array($this,'report'));

        /* Namespaces */
        $server->xmlNamespaces[self::NS_CARDDAV] = 'card';

        /* Mapping Interfaces to {DAV:}resourcetype values */
        $server->resourceTypeMapping['Sabre_CardDAV_IAddressBook'] = '{' . self::NS_CARDDAV . '}addressbook';
        
        /* Adding properties that may never be changed */
        $server->protectedProperties[] = '{' . self::NS_CARDDAV . '}supported-address-data';
        $server->protectedProperties[] = '{' . self::NS_CARDDAV . '}max-resource-size';


        $this->server = $server;

    }

    /**
     * Returns a list of supported features.
     *
     * This is used in the DAV: header in the OPTIONS and PROPFIND requests. 
     *
     * @return array
     */
    public function getFeatures() {

        return array('addressbook');

    }

    /**
     * Returns a list of reports this plugin supports.
     *
     * This will be used in the {DAV:}supported-report-set property.
     * Note that you still need to subscribe to the 'report' event to actually 
     * implement them 
     *
     * @param string $uri
     * @return array 
     */
    public function getSupportedReportSet($uri) {

        $node = $this->server->tree->getNodeForPath($uri);
        if ($node instanceof Sabre_CardDAV_AddressBook || $node instanceof Sabre_CardDAV_Card) {
            return array(
                 '{' . self::NS_CARDDAV . '}addressbook-multiget',
            );
        }
        return array();

    }


    /**
     * Adds all CardDAV-specific properties 
     *
     * @param string $path
     * @param Sabre_DAV_INode $node 
     * @param array $requestedProperties
     * @param array $returnedProperties 
     * @return void
     */
    public function beforeGetProperties($path, Sabre_DAV_INode $node, array &$requestedProperties, array &$returnedProperties) { 

        if ($node instanceof Sabre_DAVACL_IPrincipal) {

            // calendar-home-set property
            $addHome = '{' . self::NS_CARDDAV . '}addressbook-home-set';
            if (in_array($addHome,$requestedProperties)) {
                $principalId = $node->getName(); 
                $addressbookHomePath = self::ADDRESSBOOK_ROOT . '/' . $principalId . '/';
                unset($requestedProperties[$addHome]);
                $returnedProperties[200][$addHome] = new Sabre_DAV_Property_Href($addressbookHomePath);
            }

        }

        if ($node instanceof Sabre_CardDAV_Card) {

            // The address-data property is not supposed to be a 'real' 
            // property, but in large chunks of the spec it does act as such. 
            // Therefore we simply expose it as a property.
            $addressDataProp = '{' . self::NS_CARDDAV . '}address-data';
            if (in_array($addressDataProp, $requestedProperties)) {
                unset($requestedProperties[$addressDataProp]);
                $val = $node->get();
                if (is_resource($val))
                    $val = stream_get_contents($val);

                // Taking out \r to not screw up the xml output
                $returnedProperties[200][$addressDataProp] = str_replace("\r","", $val);

            }
        }

    }

    /**
     * This functions handles REPORT requests specific to CardDAV 
     *
     * @param string $reportName 
     * @param DOMNode $dom
     * @return bool 
     */
    public function report($reportName,$dom) {

        switch($reportName) { 
            case '{'.self::NS_CARDDAV.'}addressbook-multiget' :
                $this->addressbookMultiGetReport($dom);
                return false;
            case '{'.self::NS_CARDDAV.'}addressbook-query' :
                $this->addressBookQueryReport($dom);
                return false; 
            default :
                return;

        }


    }

    /**
     * This function handles the addressbook-multiget REPORT.
     *
     * This report is used by the client to fetch the content of a series
     * of urls. Effectively avoiding a lot of redundant requests.
     *
     * @param DOMNode $dom
     * @return void
     */
    public function addressbookMultiGetReport($dom) {

        $properties = array_keys(Sabre_DAV_XMLUtil::parseProperties($dom->firstChild));

        $hrefElems = $dom->getElementsByTagNameNS('urn:DAV','href');
        $propertyList = array();

        foreach($hrefElems as $elem) {

            $uri = $this->server->calculateUri($elem->nodeValue);
            list($propertyList[]) = $this->server->getPropertiesForPath($uri,$properties);

        }

        $this->server->httpResponse->sendStatus(207);
        $this->server->httpResponse->setHeader('Content-Type','application/xml; charset=utf-8');
        $this->server->httpResponse->sendBody($this->server->generateMultiStatus($propertyList));

    }

    /**
     * This function handles the addressbook-query REPORT
     *
     * This report is used by the client to filter an addressbook based on a
     * complex query.
     *
     * @param DOMNode $dom
     * @return void
     */
    protected function addressbookQueryReport($dom) {

        $query = new Sabre_CardDAV_AddressBookQueryReport($dom);
        $query->parse();

        $depth = $this->server->getHTTPDepth(0);

        if ($depth==0) {
            $candidateNodes = array(
                $this->server->objectTree->getNodeForPath($this->getRequestUri())
            );
        } else {
            $candidateNodes = $this->server->objectTree->getChildren($this->getRequestUri());
        }

        $validNodes = array();
        foreach($candidateNodes as $node) {

            if (!$node instanceof Sabre_CardDAV_Card)
                continue;

            $blob = $node->get();
            if (is_resource($blob)) {
                $blob = stream_get_contents($blob);
            }

            if (!$this->validateFilters($blob, $query->filters, $query->test)) {
                continue;
            }

            $validNodes[] = $node;

            if ($query->limit && $query->limit <= count($validNodes)) {
                // We hit the maximum number of items, we can stop now.
                break;
            }

        }

        $result = array();
        foreach($validNodes as $validNode) {
            if ($depth==0) { 
                $href = $this->server->getRequestUri();
            } else {
                $href = $this->server->getRequestUri() . '/' . $validNode->getName();
            }

            list($result[]) = $this->server->getPropertiesForPath($href, $query->requestedProperties, 0);

        }
 
        $this->server->httpResponse->sendStatus(207);
        $this->server->httpResponse->setHeader('Content-Type','application/xml; charset=utf-8');
        $this->server->httpResponse->sendBody($this->server->generateMultiStatus($result));

    }

    /**
     * Validates if a vcard makes it throught a list of filters.
     * 
     * @param string $vcardData 
     * @param array $filters 
     * @param string $test 
     * @return bool 
     */
    public function validateFilters($vcardData, array $filters, $test) {

        $vcard = Sabre_VObject_Reader::read($vcardData);

        foreach($filters as $filter) {


        }

    }

}
