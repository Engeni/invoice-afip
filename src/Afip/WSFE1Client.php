<?php

namespace EngeniTeam\InvoiceAfip\Afip;

use Exception;
use SoapClient;
use EngeniTeam\InvoiceAfip\Traits\Responsible;

class WSFE1Client
{
    use Responsible;

    protected $configuration = [];
    protected $wsaaClient;
    protected $wsfeClient;
    protected $ta;
    const DOCUMENT_TYPE=1;

    /**
     * WSFE1Client constructor.
     * @param array $options
     * The WSDL corresponding to WSFE
     * The TA as obtained from WSAA
     * CUIT del emisor de las facturas
     * Proxy IP, to reach the Internet
     * Proxy TCP port
     * For debugging purposes
     */
    public function __construct($options = [])
    {
        $this->initLog('wsfe');
        foreach (array_keys($options) as $key) {
            $this->configuration[$key] = $options[$key];
        }
        $this->configuration['service'] = 'wsfe';
        $this->wsaaClient = new WSAAClient($this->configuration);
        $this->wsaaClient->getTA();
        $this->ta = simplexml_load_file($this->configuration['ta_file']);

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
        $this->wsfeClient = new SoapClient($this->configuration['wsdl_wsfe'], $params);
        $this->getLogger()->info('AFIP::SERVER_STATUS', ['checkServerStatus' => $this->checkServerStatus()]);
    }

    private function checkServerStatus()
    {
        $results = $this->wsfeClient->FEDummy();
        return sprintf("FEDummy</br>APP SERVER STATUS: %s</br>DB SERVER STATUS: %s</br>AUTH SERVER STATUS: %s</br>",
            $results->FEDummyResult->AppServer,
            $results->FEDummyResult->DbServer,
            $results->FEDummyResult->AuthServer);
    }

    public function RecuperaQTY()
    {
        $FECompTotXRequest = [
            'Auth' => [
                'Token' => (string) $this->ta->credentials->token,
                'Sign' => (string) $this->ta->credentials->sign,
                'Cuit' => $this->configuration['cuit']
            ]
        ];
        $this->getLogger()->info('AFIP::FECompTotXRequest::request', ['request' => $FECompTotXRequest]);
        $results = $this->wsfeClient->FECompTotXRequest($FECompTotXRequest);
        $this->getLogger()->info('AFIP::FECompTotXRequest::response', ['response' => $results]);
        $this->checkErrors($results, 'FECompTotXRequest');
        return $results->FECompTotXRequestResult->RegXReq;
    }

    public function RecuperaLastCMP($cbteTipo = null)
    {
        $FECompUltimoAutorizado = [
            'Auth' => [
                'Token' => (string) $this->ta->credentials->token,
                'Sign' => (string) $this->ta->credentials->sign,
                'Cuit' => $this->configuration['cuit']
            ],
            'PtoVta' => $this->configuration['sell_point'],
            'CbteTipo' => $cbteTipo,
        ];
        $this->getLogger()->info('AFIP::FECompUltimoAutorizado::request', ['request' => $FECompUltimoAutorizado]);
        $results = $this->wsfeClient->FECompUltimoAutorizado($FECompUltimoAutorizado);
        $this->getLogger()->info('AFIP::FECompUltimoAutorizado::response', ['response' => $results]);
        $this->checkErrors($results, 'FECompUltimoAutorizado');
        return $results->FECompUltimoAutorizadoResult->CbteNro;
    }

    public function getNewCMP($cbteTipo = null)
    {
        return $this->RecuperaLastCMP($cbteTipo) + 1;
    }

    public function AutorizarCpte($electronicDocument)
    {
        $FECAESolicitar = [
            'Auth' => [
                'Token' => (string) $this->ta->credentials->token,
                'Sign' => (string) $this->ta->credentials->sign,
                'Cuit' => $this->configuration['cuit']
            ],
            'FeCAEReq' => $electronicDocument
        ];
        $this->getLogger()->info('AFIP::FECAESolicitar::request', ['request' => $FECAESolicitar]);
        $results = $this->wsfeClient->FECAESolicitar($FECAESolicitar);
        $this->getLogger()->info('AFIP::FECAESolicitar::response', ['response' => $results]);
        return $this->ProcesarResultadoAut($results->FECAESolicitarResult);
    }

    private function ProcesarResultadoAut($result)
    {
        $errors = [];
        $messages = [];
        if ($result->FeCabResp->Resultado != 'A') {
            if (isset($result->FeDetResp->FECAEDetResponse->Observaciones)) {
                if (count($result->FeDetResp->FECAEDetResponse->Observaciones->Obs) == 1) {
                    $messages[] = htmlentities($result->FeDetResp->FECAEDetResponse->Observaciones->Obs->Msg);
                } else {
                    foreach ($result->FeDetResp->FECAEDetResponse->Observaciones->Obs as $obs) {
                        $messages [] = htmlentities($obs->Msg);
                    }
                }
            }
            if (isset($result->Errors)) {
                if (count($result->Errors->Err) == 1) {
                    $errors[] = htmlentities($result->Errors->Err->Msg);
                } else {
                    foreach ($result->Errors->Err as $err) {
                        $errors[] = htmlentities($err->Msg);
                    }
                }
            }
            return [
                'success' => false,
                'message' => implode(PHP_EOL, $messages),
                'errors' => implode(PHP_EOL, $errors),
            ];
        }
        return [
            'success' => true,
            'message' => 'OK',
            'cae' => [
                'nro' => $result->FeDetResp->FECAEDetResponse->CAE,
                'vto' => $result->FeDetResp->FECAEDetResponse->CAEFchVto,
            ],
            'cbtefch' => $result->FeDetResp->FECAEDetResponse->CbteFch,
            'afip_response' => $result,
        ];
    }

    private function checkErrors($results, $method)
    {
        if (is_soap_fault($results)) {
            throw new Exception($results->faultstring,$results->faultcode);
        }
        if ($method == 'FEDummy') {
            return true;
        }
        $methodResult = $method . 'Result';
        if (isset($results->$methodResult->Errors)) {
            $message = [];
            foreach ($results->$methodResult->Errors as $error) {
                $message[] = "Method=" . $method . "; "."Error=" . $error->Code . "&nbsp;" . $error->Msg;
            }
            throw new Exception(implode(PHP_EOL, $message));
        }
        return true;
    }

    public function GetPtosVenta()
    {
        $FEParamGetPtosVenta = [
            'Auth' => [
                'Token' => (string) $this->ta->credentials->token,
                'Sign' => (string) $this->ta->credentials->sign,
                'Cuit' => $this->configuration['cuit']
            ]
        ];
        $this->getLogger()->info('AFIP::FEParamGetPtosVenta::request', ['request' => $FEParamGetPtosVenta]);
        $results = $this->wsfeClient->FEParamGetPtosVenta($FEParamGetPtosVenta);
        $this->getLogger()->info('AFIP::FEParamGetPtosVenta::response', ['response' => $results]);
        $this->checkErrors($results, 'FEParamGetPtosVenta');
        return $results->FEParamGetPtosVentaResult->ResultGet;
    }
}