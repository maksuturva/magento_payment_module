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

    const PAYMENT_CANCEL_OK = "00";
    const PAYMENT_CANCEL_NOT_FOUND = "20";
    const PAYMENT_CANCEL_ALREADY_SETTLED = "30";
    const PAYMENT_CANCEL_MISMATCH = "31";
    const PAYMENT_CANCEL_ERROR = "90";
    const PAYMENT_CANCEL_FAILED = "99";

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

    /**
     * Parse received response and verify it
     *
     * @param $response Response to be parsed & verified
     *
     * @return array Parsed response
     * @throws MaksuturvaGatewayException
     */
    protected function _processCancelPaymentResponse($response)
    {

        /** @var Fields for hash calculation $hashFields */
        $hashFields = array(
            'pmtc_action',
            'pmtc_version',
            'pmtc_sellerid',
            'pmtc_id',
            'pmtc_returntext',
            'pmtc_returncode'
        );


        /** @var Parsed response $parsedResponse */
        $parsedResponse = $this->_parseResponse($response);

        /** If response was ok, check hash. */
        if($parsedResponse['pmtc_returncode']=== self::PAYMENT_CANCEL_OK){

            $calcHash = $this->_calculateHash($parsedResponse, $hashFields);

            if($calcHash !== $parsedResponse['pmtc_hash']){
                Mage::getSingleton('adminhtml/session')->addError(
                    "The authenticity of the answer could't be verified. Hashes didn't match.
                     Verify cancel in Maksuturva account and make offline refund, if needed."
                );
                throw new MaksuturvaGatewayException(
                    array("The authenticity of the answer could't be verified. Hashes didn't match."),
                    self::EXCEPTION_CODE_HASHES_DONT_MATCH
                );
            }
        }

        switch($parsedResponse['pmtc_returncode']){

            case self::PAYMENT_CANCEL_OK:
                $error = false;
                break;
            case self::PAYMENT_CANCEL_NOT_FOUND:
                $error = true;
                $msg = "Payment not found";
                break;
            case self::PAYMENT_CANCEL_ALREADY_SETTLED:
                if(Mage::getStoreConfigFlag('payment/maksuturva/can_cancel_settled')){
                    $error = false;
                } else {
                    $error = true;
                    $msg = "Payment already settled and cannot be cancelled.";
                }
                break;
            case self::PAYMENT_CANCEL_MISMATCH:
                $error = true;
                $msg = "Cancel parameters from seller and payer do not match";
                break;
            case self::PAYMENT_CANCEL_ERROR:
                $error = true;
                $msg = "Errors in input data";
                if(isset($parsedResponse['errors'])){
                    $msg .= PHP_EOL;
                    $msg .= implode(PHP_EOL, $parsedResponse['errors']);
                }
                break;
            case self::PAYMENT_CANCEL_FAILED:
                $error = true;
                $msg = "Payment cancellation failed.";
                if(isset($parsedResponse['pmtc_returntext'])){
                    $msg .= PHP_EOL . $parsedResponse['pmtc_returntext'];
                }
                break;
            default:
                $error = true;
                $msg = "Refund failed";
                break;
        }

        /** If canceling failed, throw error */
        if($error){
            Mage::getSingleton('adminhtml/session')->addError($msg);
            throw new MaksuturvaGatewayException(
                array($msg),
                $parsedResponse['pmtc_returncode']
            );
        }

        return $parsedResponse;

    }

    protected function _parseResponse($response)
    {

        $xml = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOERROR|LIBXML_NOWARNING);
        $result = Mage::helper('core')->xmlToAssoc($xml);

        return $result;

    }

    public function _calculateHash($fields, $hashFields)
    {
        /** Generate hash */
        $hashString = '';
        foreach($hashFields AS $hashField){
            if(isset($fields[$hashField]) && !empty($fields[$hashField])) {
                $hashString .= $fields[$hashField] . '&';
            }
        }
        $hashString .= $this->secretKey . '&';

        return strtoupper(hash($this->_hashAlgoDefined, $hashString));
    }

    public function getTransactionId($payment){
        return $payment->getParentTransactionId() ? $payment->getParentTransactionId() : $payment->getLastTransId();
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
