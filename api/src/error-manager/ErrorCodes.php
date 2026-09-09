<?php

namespace MagratheaExplorer;

use Magrathea2\ConfigFile;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\Singleton;

class ErrorCodes extends Singleton {

	private $config;
	function Initialize() {
		$this->config = new ConfigFile();
		$this->config->SetPath(__DIR__);
		$this->config->SetFile("error_codes.conf");
	}

	public function GetError(int $code): string {
		$config = "errors/".$code;
		return $this->config->GetConfig($config) ?? "unknown error code: ".$code;
	}

	public function GetAll(): array {
		return $this->config->GetConfig("errors") ?? [];
	}

	public function GetException(int $code, $data=null, ?string $detail=null): \Magrathea2\Exceptions\MagratheaException {
		$msg = $this->GetError($code);
		if($detail) $msg .= ": ".$detail;
		return new MagratheaApiException($msg, $code, $data);
	}

	public function ThrowException(int $code, $data=null, $detail=null): \Magrathea2\Exceptions\MagratheaException {
		throw $this->GetException($code, $data, $detail);
	}

}
