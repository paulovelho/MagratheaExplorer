<?php

namespace MagratheaExplorer\Key;

use Magrathea\Logger;
use Magrathea\MagratheaApiAuth;
use MagratheaExplorer\AuthRateLimit;
use MagratheaExplorer\ErrorCodes;
use MagratheaExplorer\Provisioning\ProvisioningControl;

/**
 * Route-level auth gate for `BaseAuthorization()` -- a pure boolean check with no shared
 * state passed to the controller (that's how the framework's declarative route auth works;
 * see ExplorerApiControl::GetRequestKey() for the controller-side re-resolution). Extends
 * MagratheaApiAuth (not the more general MagratheaApiControl) because MagratheaApi's own
 * $authClass property is typed `?MagratheaApiAuth` internally, stricter than the
 * `MagratheaApiControl` type BaseAuthorization()'s parameter declares -- passing a plain
 * MagratheaApiControl subclass fatals with a TypeError on that property assignment. None of
 * MagratheaApiAuth's JWT-specific methods are used here; only GetAuthorizationToken(),
 * inherited from the common MagratheaApiControl base, is needed.
 */
class KeyAuthControl extends MagratheaApiAuth {

	public function IsAuthenticated(): bool {
		try {
			$token = $this->GetAuthorizationToken();
			KeyControl::ResolveFromBearer($token);
			return true;
		} catch(\Throwable $ex) {
			return false;
		}
	}

	/**
	 * `/provision/...` routes: the bearer has to be the provisioning secret (admin →
	 * Provisioning). A key's secret never passes (wrong shape, and it isn't the hash stored
	 * there), and the provisioning secret never passes IsAuthenticated() (it isn't in
	 * `access_keys`). Failures are rate limited per client IP (AuthRateLimit): the limit is
	 * checked first, so a blocked client gets 4291 with `Retry-After` and nothing is compared.
	 * Any other failure is the framework's generic 401, as for keys.
	 */
	public function IsProvisioning(): bool {
		$limit = AuthRateLimit::ForProvisioning();
		$retryAfter = $limit->RetryAfter();
		if($retryAfter !== null) {
			header("Retry-After: ".$retryAfter);
			ErrorCodes::Instance()->ThrowException(4291, null, "retry in ".$retryAfter." s");
		}
		try {
			$token = $this->GetAuthorizationToken();
		} catch(\Throwable $ex) {
			$token = null; // no header, or not Bearer/Basic: a failure like any other
		}
		if(!ProvisioningControl::Authenticate($token)) {
			$limit->RecordFailure();
			return false;
		}
		try {
			ProvisioningControl::TouchLastUsed();
		} catch(\Throwable $ex) {
			Logger::Instance()->LogError($ex); // bookkeeping: never worth failing the request over
		}
		return true;
	}

}
