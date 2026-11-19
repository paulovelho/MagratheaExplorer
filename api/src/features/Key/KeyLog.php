<?php

namespace MagratheaExplorer\Key;

use Magrathea\Logger;
use MagratheaExplorer\ClientIp;

/**
 * What happens to keys through the API (provisioned, secret rotated), one line each, in the logs
 * folder's `keys_<YYYYMM>.log`. Never a secret: the key's id and name, and who asked. Admin
 * actions go to the admin's own log (AdminManager::Log()).
 *
 * Logging never fails the request: by the time it runs, the key exists or its old secret is
 * already dead, and the response is the only place the new secret is.
 */
final class KeyLog {

	public static function Write(string $action, Key $key): void {
		$logger = Logger::Instance();
		$previous = null;
		try {
			$previous = $logger->GetLogFile();
			$line = $action.": key id=".(int)$key->id." name=".json_encode($key->name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
				." from ".ClientIp::FromRequest();
			$logger->SetLogFile("keys_".date("Ym").".log")->Log($line);
		} catch(\Throwable $ex) {
			error_log("MagratheaExplorer: could not write the keys log (".$action.", key ".(int)$key->id."): ".$ex->getMessage());
		} finally {
			// Logger's file is a singleton setting: put it back for whatever logs next
			if($previous !== null) $logger->SetLogFile($previous);
		}
	}

}
