<?php

namespace EngeniTeam\InvoiceAfip\Traits;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

trait Responsible
{

    protected $lastErrors = [];
    protected $logger;

    public function initLog($logname)
    {
        $this->logger = new Logger($logname);
        $this->logger->pushHandler(new StreamHandler(storage_path(sprintf('logs/%s.log', $logname)), Logger::INFO));
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

    public function isSuccess()
    {
        return !$this->hasErrors();
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

}
