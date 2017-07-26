<?php

namespace EngeniTeam\InvoiceAfip\Afip;

use Exception;
use SimpleXMLElement;
use SoapClient;
use EngeniTeam\InvoiceAfip\Traits\Responsible;

class WSAAClient
{
    use Responsible;

    protected $configuration = [];
    protected $soapClient;

    public function __construct($options = [])
    {
        $this->initLog('wsaa');
        foreach (array_keys($options) as $key) {
            $this->configuration[$key] = $options[$key];
        }
        $this->configuration['tra_tmp'] = storage_path('logs/tra.tmp');
        $this->configuration['tra_xml'] = storage_path('logs/tra.xml');

        $params = [
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'exceptions' => 0
        ];
        foreach (['proxy_host', 'proxy_port'] as $key) {
            isset($this->configuration[$key])
                ? $params[$key] = $this->configuration[$key]
                : false;
        }

        $this->soapClient = new SoapClient($this->configuration['wsdl_wsaa'], $params);
    }

    public function getTA()
    {
        try {
            $isValid = false;
            if (file_exists($this->configuration['ta_file'])) {
                $ta = simplexml_load_file($this->configuration['ta_file']);
                $isValid = $this->isValidTA($ta);
            }

            if (!$isValid) {
                $this->createTRA($this->configuration['service']);
                $cms = $this->singTRA();
                $ta = $this->callWSAA($cms);

                if (!file_put_contents($this->configuration['ta_file'], $ta)) {
                    throw new Exception("Failed to create " . $this->configuration['ta_file']);
                }
            }
        } catch (Exception $e) {
            $this->getLogger()->crit($e);
            $this->addError($e->getMessage());
        }
        return $this->getResponse($this->isSuccess(), $this->getErrorMessages());
    }

    private function isValidTA($ta)
    {
        return ($ta->header->expirationTime > date('c'));
    }

    private function createTRA($service)
    {
        $tra = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0">' .
            '</loginTicketRequest>');
        $tra->addChild('header');
        $tra->header->addChild('uniqueId', date('U'));
        $tra->header->addChild('generationTime', date('c', date('U') - 220));
        $tra->header->addChild('expirationTime', date('c', date('U') + 240));
        $tra->addChild('service', $service);
        $tra->asXML($this->configuration['tra_xml']);
    }

    #==============================================================================
    # This method makes the PKCS#7 signature using TRA as input file, CERT and
    # PRIVATEKEY to sign. Generates an intermediate file and finally trims the
    # MIME heading leaving the final CMS required by WSAA.
    private function singTRA()
    {
        // Read the private key from the file.
        $fp = fopen($this->configuration['private_key_file'], "r");
        $privateKey = fread($fp, 8192);
        fclose($fp);
        $pkeyid = openssl_pkey_get_private($privateKey);

        // Read the public key (afip certificate) from the file.
        $fp = fopen($this->configuration['crt_file'], "r");
        $certFile = fread($fp, 8192);
        fclose($fp);

        $fp = fopen($this->configuration['tra_tmp'], "w"); //tra.tmp es un archivo temporal
        fclose($fp);

        $status = openssl_pkcs7_sign(realpath($this->configuration['tra_xml']),
            realpath($this->configuration['tra_tmp']), $certFile,
            array($pkeyid, $this->configuration['key_phrase']),
            array(),
            !PKCS7_DETACHED
        );
        if (!$status) {
            throw new Exception("An error occurred generating PKCS#7 signature");
        }
        $inf = fopen($this->configuration['tra_tmp'], "r");
        $i = 0;
        $CMS = "";
        while (!feof($inf)) {
            $buffer = fgets($inf);
            if ($i++ >= 4) {
                $CMS .= $buffer;
            }
        }
        fclose($inf);

        unlink($this->configuration['tra_tmp']);

        return $CMS;
    }

    private function callWSAA($CMS)
    {
        $results = $this->soapClient->loginCms(array('in0' => $CMS));
        if (is_soap_fault($results)) {
            throw new Exception("SOAP Fault: " . $results->faultcode . "\n" . $results->faultstring);
        }
        return $results->loginCmsReturn;
    }

}