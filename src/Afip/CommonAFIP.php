<?php

namespace EngeniTeam\InvoiceAfip\Afip;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

trait CommonAFIP
{

    protected $credentials;
    protected $lastErrors = [];
    protected $logger;

    public function initLog($filename = null)
    {
        $this->logger = new Logger('afip');
        $this->logger->pushHandler(new StreamHandler(storage_path(sprintf('logs/afip%s.log', $filename)), Logger::INFO));
    }

    public function addError($message)
    {
        $this->lastErrors[] = $message;
    }

    public function getErrorMessages()
    {
        return $this->hasErrors() ? implode(', ', $this->lastErrors) : 'OK';
    }

    public function getResponse($result, $message)
    {
        return ['success' => $result, 'message' => $message];
    }

    public function hasErrors()
    {
        return count($this->lastErrors) > 0;
    }

    public function isOK()
    {
        return !$this->hasErrors();
    }

    public function getLogger()
    {
        return $this->logger;
    }

}
