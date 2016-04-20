<?php

/**
 * Copyright © 2016 Vaimo Finland Oy, Suomen Maksuturva Oy
 * See LICENSE.txt for license details.
 */
abstract class Vaimo_Maksuturva_Model_Gateway_Abstract extends Mage_Core_Model_Abstract
{
    /**
     * Status query codes
     */
    const STATUS_QUERY_NOT_FOUND = "00";
    const STATUS_QUERY_FAILED = "01";
    const STATUS_QUERY_WAITING = "10";
    const STATUS_QUERY_UNPAID = "11";
    const STATUS_QUERY_UNPAID_DELIVERY = "15";
    const STATUS_QUERY_PAID = "20";
    const STATUS_QUERY_PAID_DELIVERY = "30";
    const STATUS_QUERY_COMPENSATED = "40";
    const STATUS_QUERY_PAYER_CANCELLED = "91";
    const STATUS_QUERY_PAYER_CANCELLED_PARTIAL = "92";
    const STATUS_QUERY_PAYER_CANCELLED_PARTIAL_RETURN = "93";
    const STATUS_QUERY_PAYER_RECLAMATION = "95";
    const STATUS_QUERY_CANCELLED = "99";

    const EXCEPTION_CODE_ALGORITHMS_NOT_SUPORTED = '00';
    const EXCEPTION_CODE_URL_GENERATION_ERRORS = '01';
    const EXCEPTION_CODE_FIELD_ARRAY_GENERATION_ERRORS = '02';
    const EXCEPTION_CODE_REFERENCE_NUMBER_UNDER_100 = '03';
    const EXCEPTION_CODE_FIELD_MISSING = '04';
    const EXCEPTION_CODE_INVALID_ITEM = '05';
    const EXCEPTION_CODE_PHP_CURL_NOT_INSTALLED = '06';
    const EXCEPTION_CODE_HASHES_DONT_MATCH = '07';

    const PAYMENT_SERVICE_URN = 'NewPaymentExtended.pmt';

    protected $_secretKey = null;
    protected $_hashAlgoDefined = null;
    protected $_pmt_hashversion = null;

    /**
     * Url used to redirect the user to
     * @var string
     */
    protected $_baseUrl = null;

    /**
     * Data POSTed to maksuturva
     * @var array
     */
    protected $_statusQueryData = array();

    protected $_charset = 'UTF-8';

    protected $_charsethttp = 'UTF-8';

    private $_errors = array();


    public function __construct()
    {
        // curl is mandatory
        if (!function_exists("curl_init")) {
            throw new MaksuturvaGatewayException(array("cURL is needed in order to communicate with the maksuturva's server. Check your PHP installation."), self::EXCEPTION_CODE_PHP_CURL_NOT_INSTALLED);
        }

        $hashAlgos = hash_algos();

        if (in_array("sha512", $hashAlgos)) {
            $this->_pmt_hashversion = 'SHA-512';
            $this->_hashAlgoDefined = "sha512";
        } else if (in_array("sha256", $hashAlgos)) {
            $this->_pmt_hashversion = 'SHA-256';
            $this->_hashAlgoDefined = "sha256";
        } else if (in_array("sha1", $hashAlgos)) {
            $this->_pmt_hashversion = 'SHA-1';
            $this->_hashAlgoDefined = "sha1";
        } else if (in_array("md5", $hashAlgos)) {
            $this->_pmt_hashversion = 'MD5';
            $this->_hashAlgoDefined = "md5";
        } else {
            throw new MaksuturvaGatewayException(array('the hash algorithms SHA-512, SHA-256, SHA-1 and MD5 are not supported!'), self::EXCEPTION_CODE_ALGORITHMS_NOT_SUPORTED);
        }
    }

    /**
     * Calculate the hash based on the parameters returned from maksuturva
     * @param array $hashData
     */
    public function generateReturnHash($hashData)
    {
        $hashString = '';
        foreach ($hashData as $key => $data) {
            //Ignore the hash itself if passed
            if ($key != 'pmt_hash') {
                $hashString .= $data . '&';
            }
        }

        $hashString .= $this->secretKey . '&';

        return strtoupper(hash($this->_hashAlgoDefined, $hashString));

    }

    public function getErrors()
    {
        return $this->_errors;
    }


    /**
     * Send HTTP POST to maksuturva server
     *
     * @param $data
     * @return mixed
     * @throws MaksuturvaGatewayException
     */
    protected function getPostResponse($url, $data, $timeout = 120)
    {
        $request = curl_init($url);
        curl_setopt($request, CURLOPT_HEADER, 0);
        curl_setopt($request, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($request, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($request, CURLOPT_POST, 1);
        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, 0); // Ignoring certificate verification
        curl_setopt($request, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($request, CURLOPT_POSTFIELDS, $data);

        $res = curl_exec($request);
        if ($res === false) {
            //more informative connection error message, rev 121
            throw new MaksuturvaGatewayException(array("Failed to communicate with Maksuturva. Please check the network connection. URL: " . $this->_statusQueryBaseUrl . " ERROR MESSAGE: " . curl_error($request)));
        }
        curl_close($request);

        return $res;
    }

    /**
     * Internal method to validate the consistency of maksuturva
     * responses for a given status query.
     * @param array $data
     * @return boolean
     */
    protected function _verifyStatusQueryResponse($data)
    {
        $hashFields = array(
            "pmtq_action",
            "pmtq_version",
            "pmtq_sellerid",
            "pmtq_id",
            "pmtq_amount",
            "pmtq_returncode",
            "pmtq_returntext",
            "pmtq_sellercosts",
            "pmtq_paymentmethod",
            "pmtq_escrow",
            "pmtq_certification",
            "pmtq_paymentdate"
        );

        $optionalFields = array(
            "pmtq_sellercosts",
            "pmtq_paymentmethod",
            "pmtq_escrow",
            "pmtq_certification",
            "pmtq_paymentdate"
        );

        $hashString = "";
        foreach ($hashFields as $hashField) {
            if (!isset($data[$hashField]) && !in_array($hashField, $optionalFields)) {
                return false;
                // optional fields
            } elseif (!isset($data[$hashField])) {
                continue;
            }
            // test the vality of data as well, when the field exists
            if (isset($this->_statusQueryData[$hashField]) &&
                ($data[$hashField] != $this->_statusQueryData[$hashField])
            ) {
                return false;
            }
            $hashString .= $data[$hashField] . "&";
        }
        $hashString .= $this->secretKey . '&';

        $calcHash = strtoupper(hash($this->_hashAlgoDefined, $hashString));
        if ($calcHash != $data["pmtq_hash"]) {
            return false;
        }

        return true;
    }
}

class MaksuturvaGatewayException extends Exception
{
    public function __construct($errors, $code = null)
    {
        $message = '';
        foreach ($errors as $error) {
            $message .= $error . ', ';
        }

        parent::__construct($message, $code);
    }
}
