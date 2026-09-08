<?php

namespace MagratheaExplorer\Key;

use Magrathea2\MagratheaApiAuth;

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

}
