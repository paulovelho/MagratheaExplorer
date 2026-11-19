<?php

namespace MagratheaExplorer\Provisioning;

use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Logger;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\ExplorerApiControl;
use MagratheaExplorer\Idempotency;
use MagratheaExplorer\IdempotencyClaim;
use MagratheaExplorer\IdempotencyDbStore;
use MagratheaExplorer\IdempotencyRecord;
use MagratheaExplorer\Key\KeyApi;
use MagratheaExplorer\Key\KeyControl;
use MagratheaExplorer\Key\KeyLog;

/**
 * POST /provision/keys, the only provisioning route (see ProvisioningControl). Authenticated by
 * KeyAuthControl::IsProvisioning(), never by a key. Extends ExplorerApiControl for its body
 * parsing (JSON or form); GetRequestKey() is never called here: there's no key in the request.
 */
class ProvisioningApi extends ExplorerApiControl {

	/** Every field the body may have. Settings aren't among them: they're the operator's (admin → Provisioning). */
	const CREATE_FIELDS = ["name"];

	/**
	 * POST /provision/keys -- a new key, with the provisioning settings. Returns GET /key's view
	 * plus `secret`, the key's bearer secret: the only time it's ever shown. Header:
	 * Idempotency-Key (optional): a retry gets the same key, with a new secret (Replay()).
	 */
	public function CreateKey(): array {
		$name = self::ParseCreateBody($this->GetPost() ?? []);
		$idempotencyKey = Idempotency::KeyFromRequest();
		$claim = null;
		if($idempotencyKey !== null) {
			$begin = Idempotency::Begin(new IdempotencyDbStore(), Idempotency::CREDENTIAL_PROVISIONING, ProvisioningControl::ROW_ID,
				$idempotencyKey, self::CreateRequestHash($name));
			if($begin instanceof IdempotencyRecord) return self::Replay($begin);
			$claim = $begin;
		}
		return Idempotency::Run($claim, fn() => self::Provision($name, $claim));
	}

	/** For an Idempotency-Key: the name, as checked (trimmed), is the whole request. */
	public static function CreateRequestHash(string $name): string {
		return Idempotency::RequestHash("POST provision/keys", ["name" => $name]);
	}

	private static function Provision(string $name, ?IdempotencyClaim $claim): array {
		try {
			// the key and its Idempotency-Key record commit together, in CreateKey()'s transaction
			[$key, $secret] = ProvisioningControl::CreateKey($name, $claim?->Recorder(Idempotency::RESOURCE_KEY));
		} catch(MagratheaDBException $ex) {
			// an admin key took the name between the check and the insert
			if($ex->getCode() === 1062) ErrorCodes::Instance()->ThrowException(4094);
			self::Fail($ex);
		}
		KeyLog::Write("provisioned", $key);
		return ["secret" => $secret] + KeyApi::View($key->Reload());
	}

	/**
	 * A retry of a create that completed. The first secret can't be returned: only its hash was
	 * stored. So the key gets a new one, returned here, and the first one stops working. The
	 * first response never reached the caller (that's why it retried), so nobody holds that
	 * secret, and a key nobody has the secret of would hold a `max_keys` slot for nothing. A
	 * duplicate of a request whose response *did* arrive kills the secret it returned: only retry
	 * after a failure. Deleted since (offboarded): 4041.
	 */
	private static function Replay(IdempotencyRecord $record): array {
		// can't happen (the route is in the request hash), but a file id must never be read as a key id
		if($record->resourceType !== Idempotency::RESOURCE_KEY) ErrorCodes::Instance()->ThrowException(4221);
		try {
			$key = KeyControl::GetRowWhere(["id" => (int)$record->resourceId, "provisioned" => 1]);
			if($key === null) ErrorCodes::Instance()->ThrowException(4041);
			$secret = KeyControl::RotateSecret($key);
		} catch(MagratheaDBException $ex) {
			self::Fail($ex);
		}
		KeyLog::Write("secret rotated (Idempotency-Key replay)", $key);
		Idempotency::MarkReplayed();
		return ["secret" => $secret] + KeyApi::View($key->Reload());
	}

	/**
	 * The framework's error response carries a DB exception's query and values, in every mode:
	 * log it here, answer without them.
	 */
	private static function Fail(MagratheaDBException $ex): never {
		Logger::Instance()->LogError($ex);
		ErrorCodes::Instance()->ThrowException(500);
		throw new \LogicException("unreachable"); // ThrowException() always throws
	}

	/**
	 * The body's `name`, checked. Any other field is refused (40013 naming it), settings included:
	 * a provisioned key's settings come from admin → Provisioning, and a field silently ignored
	 * would look like it had applied.
	 */
	public static function ParseCreateBody(array $body): string {
		foreach(array_keys($body) as $field) {
			if(!in_array($field, self::CREATE_FIELDS, true)) {
				ErrorCodes::Instance()->ThrowException(40013, null,
					$field." (not accepted: the only field is `name`; a provisioned key's settings are the operator's)");
			}
		}
		$raw = $body["name"] ?? null;
		if($raw === null || (is_string($raw) && trim($raw) === "")) {
			ErrorCodes::Instance()->ThrowException(4001, null, "name");
		}
		$name = KeyControl::NormalizeName($raw);
		if($name === null) {
			ErrorCodes::Instance()->ThrowException(40013, null,
				"name (expected a string of 1 to ".KeyControl::NAME_MAX_LENGTH." characters, without control characters)");
		}
		return $name;
	}

}
